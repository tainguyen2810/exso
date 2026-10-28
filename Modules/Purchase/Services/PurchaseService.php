<?php

namespace Modules\Purchase\Services;

use App\Support\Sequences\Sequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\InventoryService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseReceipt;
use Modules\Purchase\Models\PurchaseReceiptItem;
use Modules\Purchase\Models\PurchaseReturn;
use Modules\Purchase\Models\Supplier;
use Modules\Purchase\Models\SupplierDebt;

/**
 * Purchase orders, receipts, payments and returns to suppliers. Every total
 * is computed here from quantities and prices; nothing is taken from the
 * browser. Stock goes through InventoryService, debt through SupplierLedger.
 *
 * @phpstan-type LineInput array{variant_id: int, quantity: int, price: int, discount?: int}
 * @phpstan-type DocumentInput array{supplier_id: int, branch_id: int, discount?: int, costs?: list<array{name: string, amount: int}>, note?: string|null, reference?: string|null, expected_at?: string|null, purchase_order_id?: int|null, items: list<LineInput>}
 */
class PurchaseService
{
    public function __construct(
        protected InventoryService $inventory,
        protected SupplierLedger $ledger,
    ) {}

    /**
     * Line totals, document totals and each line's cost per unit after
     * spreading the order discount and purchase costs by line value.
     *
     * @param  list<LineInput>  $items
     * @param  list<array{name: string, amount: int}>  $costs
     * @return array{items: list<array{variant_id: int, quantity: int, price: int, discount: int, total: int, unit_cost: int}>, subtotal: int, discount: int, cost_total: int, total: int}
     */
    public function calculate(array $items, int $discount = 0, array $costs = []): array
    {
        $lines = [];

        foreach ($items as $item) {
            $quantity = max(0, (int) $item['quantity']);
            $price = max(0, (int) $item['price']);
            $gross = $quantity * $price;
            $lineDiscount = min(max(0, (int) ($item['discount'] ?? 0)), $gross);
            $lines[] = [
                'variant_id' => (int) $item['variant_id'],
                'quantity' => $quantity,
                'price' => $price,
                'discount' => $lineDiscount,
                'total' => $gross - $lineDiscount,
                'unit_cost' => 0,
            ];
        }

        $subtotal = array_sum(array_column($lines, 'total'));
        $discount = min(max(0, $discount), $subtotal);
        $costTotal = array_sum(array_map(fn (array $cost): int => max(0, (int) $cost['amount']), $costs));

        // Spread (costs - discount) over the lines by value; the last line takes the rounding.
        $spread = $costTotal - $discount;
        $weights = $subtotal > 0 ? array_column($lines, 'total') : array_column($lines, 'quantity');
        $weightTotal = array_sum($weights);
        $left = $spread;
        $last = array_key_last($lines);

        foreach ($lines as $index => &$line) {
            $share = $weightTotal > 0
                ? ($index === $last ? $left : intdiv($spread * $weights[$index], $weightTotal))
                : 0;
            $left -= $share;
            $value = max(0, $line['total'] + $share);
            $line['unit_cost'] = $line['quantity'] > 0 ? intdiv($value + intdiv($line['quantity'], 2), $line['quantity']) : 0;
        }
        unset($line);

        return [
            'items' => $lines,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'cost_total' => $costTotal,
            'total' => $subtotal - $discount + $costTotal,
        ];
    }

    /**
     * Create or update a purchase order (only while it is pending).
     *
     * @param  DocumentInput  $data
     */
    public function saveOrder(?PurchaseOrder $order, array $data): PurchaseOrder
    {
        if ($order !== null && $order->status !== PurchaseOrder::PENDING) {
            throw ValidationException::withMessages(['status' => 'Đơn đặt hàng đã kết thúc, không sửa được.']);
        }

        if ($order !== null && $order->items()->where('received_quantity', '>', 0)->exists()) {
            throw ValidationException::withMessages(['status' => 'Đơn đã nhập hàng một phần, không sửa được sản phẩm.']);
        }

        $this->checkVariants($data['items']);
        $totals = $this->calculate($data['items'], (int) ($data['discount'] ?? 0));

        return DB::transaction(function () use ($order, $data, $totals): PurchaseOrder {
            $order ??= new PurchaseOrder(['code' => Sequence::next('DHN', PurchaseOrder::class), 'created_by' => auth()->id()]);
            $order->fill([
                'supplier_id' => $data['supplier_id'],
                'branch_id' => $data['branch_id'],
                'expected_at' => $data['expected_at'] ?? null,
                'note' => $data['note'] ?? null,
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'total' => $totals['subtotal'] - $totals['discount'],
            ])->save();

            $order->items()->delete();

            foreach ($totals['items'] as $line) {
                $order->items()->create([
                    'variant_id' => $line['variant_id'],
                    'quantity' => $line['quantity'],
                    'price' => $line['price'],
                    'discount' => $line['discount'],
                    'total' => $line['total'],
                ]);
            }

            return $order;
        });
    }

    /**
     * End an order: cancelled when nothing arrived, otherwise completed
     * with what was received.
     */
    public function closeOrder(PurchaseOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            $locked = PurchaseOrder::query()->with('items')->lockForUpdate()->findOrFail($order->id);

            if ($locked->status !== PurchaseOrder::PENDING) {
                throw ValidationException::withMessages(['status' => 'Đơn đặt hàng đã kết thúc.']);
            }

            if ($locked->receipts()->where('status', PurchaseReceipt::DRAFT)->exists()) {
                throw ValidationException::withMessages(['status' => 'Còn phiếu nhập chưa nhập kho của đơn này. Hãy nhập kho hoặc hủy phiếu đó trước.']);
            }

            $locked->update(['status' => $locked->receiveStatus() === 'none' ? PurchaseOrder::CANCELLED : PurchaseOrder::COMPLETED]);
        });
    }

    /**
     * Create or update a receipt (only while it is a draft). With
     * $receive the goods go into stock right away.
     *
     * @param  DocumentInput  $data
     */
    public function saveReceipt(?PurchaseReceipt $receipt, array $data, bool $receive = false): PurchaseReceipt
    {
        if ($receipt !== null && $receipt->status !== PurchaseReceipt::DRAFT) {
            throw ValidationException::withMessages(['status' => 'Phiếu đã nhập kho hoặc đã hủy, không sửa được.']);
        }

        $this->checkVariants($data['items']);
        $costs = array_values(array_filter($data['costs'] ?? [], fn (array $cost): bool => (int) $cost['amount'] > 0));
        $totals = $this->calculate($data['items'], (int) ($data['discount'] ?? 0), $costs);

        $orderId = $data['purchase_order_id'] ?? null;

        if ($orderId !== null) {
            $order = PurchaseOrder::query()->whereKey($orderId)->first();

            if ($order === null || $order->status !== PurchaseOrder::PENDING || $order->supplier_id !== (int) $data['supplier_id']) {
                throw ValidationException::withMessages(['purchase_order_id' => 'Đơn đặt hàng nhập không hợp lệ hoặc đã kết thúc.']);
            }
        }

        $receipt = DB::transaction(function () use ($receipt, $data, $totals, $costs, $orderId): PurchaseReceipt {
            $receipt ??= new PurchaseReceipt(['code' => Sequence::next('PN', PurchaseReceipt::class), 'created_by' => auth()->id()]);
            $receipt->fill([
                'supplier_id' => $data['supplier_id'],
                'branch_id' => $data['branch_id'],
                'purchase_order_id' => $orderId,
                'note' => $data['note'] ?? null,
                'reference' => $data['reference'] ?? null,
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'costs' => $costs,
                'cost_total' => $totals['cost_total'],
                'total' => $totals['total'],
            ])->save();

            $receipt->items()->delete();

            foreach ($totals['items'] as $line) {
                $receipt->items()->create($line);
            }

            return $receipt;
        });

        if ($receive) {
            $this->receive($receipt);
        }

        return $receipt;
    }

    /**
     * Put the goods of a draft receipt into stock: weighted average cost,
     * latest purchase price, supplier debt and the purchase order's
     * received quantities are all updated together.
     */
    public function receive(PurchaseReceipt $receipt): void
    {
        DB::transaction(function () use ($receipt): void {
            /** @var PurchaseReceipt $locked */
            $locked = PurchaseReceipt::query()->with(['items.variant.product', 'supplier'])->lockForUpdate()->findOrFail($receipt->id);

            if ($locked->status !== PurchaseReceipt::DRAFT) {
                throw ValidationException::withMessages(['status' => 'Phiếu đã nhập kho hoặc đã hủy.']);
            }

            if ($locked->items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Phiếu chưa có sản phẩm.']);
            }

            // Same lock order everywhere (by variant id) so two receipts never deadlock.
            foreach ($locked->items->sortBy('variant_id') as $item) {
                $this->inventory->move($locked->branch_id, $item->variant, $item->quantity, StockMovement::PURCHASE, [
                    'unit_cost' => $item->unit_cost,
                    'update_cost' => true,
                    'reference' => $locked,
                    'reference_code' => $locked->code,
                ]);

                $item->variant->forceFill(['import_price' => $item->price])->save();
            }

            $this->ledger->record($locked->supplier, SupplierDebt::PURCHASE, $locked->total, [
                'reference' => $locked,
                'reference_code' => $locked->code,
            ]);

            $locked->update(['status' => PurchaseReceipt::RECEIVED, 'received_at' => now(), 'received_by' => auth()->id()]);
            $this->addToOrder($locked, 1);

            $receipt->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Record a payment to the supplier for a receipt.
     */
    public function pay(PurchaseReceipt $receipt, int $amount, string $method, ?string $note = null): void
    {
        DB::transaction(function () use ($receipt, $amount, $method, $note): void {
            /** @var PurchaseReceipt $locked */
            $locked = PurchaseReceipt::query()->with('supplier')->lockForUpdate()->findOrFail($receipt->id);

            if ($locked->status === PurchaseReceipt::CANCELLED) {
                throw ValidationException::withMessages(['amount' => 'Phiếu đã hủy.']);
            }

            if ($amount <= 0 || $amount > $locked->remaining()) {
                throw ValidationException::withMessages(['amount' => 'Số tiền phải lớn hơn 0 và không quá số còn phải trả ('.number_format($locked->remaining(), 0, ',', '.').').']);
            }

            $locked->update(['paid' => $locked->paid + $amount]);

            $this->ledger->record($locked->supplier, SupplierDebt::PAYMENT, -$amount, [
                'reference' => $locked,
                'reference_code' => $locked->code,
                'payment_method' => $method,
                'note' => $note,
            ]);

            $receipt->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Cancel a receipt. A received one takes its goods back out of stock
     * (refused when they were already sold) and removes the debt.
     */
    public function cancel(PurchaseReceipt $receipt): void
    {
        DB::transaction(function () use ($receipt): void {
            /** @var PurchaseReceipt $locked */
            $locked = PurchaseReceipt::query()->with(['items.variant.product', 'supplier'])->lockForUpdate()->findOrFail($receipt->id);

            if ($locked->status === PurchaseReceipt::CANCELLED) {
                throw ValidationException::withMessages(['status' => 'Phiếu đã hủy.']);
            }

            if ($locked->status === PurchaseReceipt::RECEIVED) {
                foreach ($locked->items->sortBy('variant_id') as $item) {
                    $this->inventory->move($locked->branch_id, $item->variant, -($item->quantity - $item->returned_quantity), StockMovement::PURCHASE, [
                        'reference' => $locked,
                        'reference_code' => $locked->code,
                        'note' => 'Hủy phiếu nhập',
                    ]);
                }

                $this->ledger->record($locked->supplier, SupplierDebt::CANCEL, -($locked->total - $locked->returned_total), [
                    'reference' => $locked,
                    'reference_code' => $locked->code,
                ]);

                $this->addToOrder($locked, -1);
            }

            $locked->update(['status' => PurchaseReceipt::CANCELLED]);
            $receipt->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Send goods of a received receipt back to the supplier. The debt goes
     * down by the value returned (price after discounts, costs not refunded).
     *
     * @param  array<int, int>  $quantities  receipt item id => quantity
     */
    public function returnGoods(PurchaseReceipt $receipt, array $quantities, ?string $note = null): PurchaseReturn
    {
        return DB::transaction(function () use ($receipt, $quantities, $note): PurchaseReturn {
            /** @var PurchaseReceipt $locked */
            $locked = PurchaseReceipt::query()->with(['items.variant.product', 'supplier'])->lockForUpdate()->findOrFail($receipt->id);

            if ($locked->status !== PurchaseReceipt::RECEIVED) {
                throw ValidationException::withMessages(['items' => 'Chỉ trả hàng được với phiếu đã nhập kho.']);
            }

            $lines = [];

            foreach ($locked->items as $item) {
                $quantity = (int) ($quantities[$item->id] ?? 0);

                if ($quantity < 0 || $quantity > $item->quantity - $item->returned_quantity) {
                    throw ValidationException::withMessages(['items' => "Số lượng trả của {$item->variant->name} vượt quá số còn lại."]);
                }

                if ($quantity > 0) {
                    $lines[] = [$item, $quantity, $this->returnPrice($locked, $item)];
                }
            }

            if ($lines === []) {
                throw ValidationException::withMessages(['items' => 'Chọn ít nhất một sản phẩm để trả.']);
            }

            /** @var PurchaseReturn $return */
            $return = $locked->returns()->create([
                'code' => Sequence::next('THN', PurchaseReturn::class),
                'branch_id' => $locked->branch_id,
                'supplier_id' => $locked->supplier_id,
                'note' => $note,
                'created_by' => auth()->id(),
            ]);

            $total = 0;

            usort($lines, fn (array $a, array $b): int => $a[0]->variant_id <=> $b[0]->variant_id);

            foreach ($lines as [$item, $quantity, $price]) {
                $this->inventory->move($locked->branch_id, $item->variant, -$quantity, StockMovement::PURCHASE_RETURN, [
                    'reference' => $return,
                    'reference_code' => $return->code,
                ]);

                $return->items()->create([
                    'purchase_receipt_item_id' => $item->id,
                    'variant_id' => $item->variant_id,
                    'quantity' => $quantity,
                    'price' => $price,
                    'total' => $price * $quantity,
                ]);

                $item->update(['returned_quantity' => $item->returned_quantity + $quantity]);
                $total += $price * $quantity;
            }

            $return->update(['total' => $total]);
            $locked->update(['returned_total' => $locked->returned_total + $total]);

            $this->ledger->record($locked->supplier, SupplierDebt::RETURN, -$total, [
                'reference' => $return,
                'reference_code' => $return->code,
                'note' => "Trả hàng phiếu {$locked->code}",
            ]);

            return $return;
        });
    }

    /**
     * Price per unit refunded on return: the line value after its own and
     * its share of the order discount.
     */
    public function returnPrice(PurchaseReceipt $receipt, PurchaseReceiptItem $item): int
    {
        if ($item->quantity <= 0) {
            return 0;
        }

        $share = $receipt->subtotal > 0 ? intdiv($receipt->discount * $item->total, $receipt->subtotal) : 0;

        return intdiv($item->total - $share, $item->quantity);
    }

    /**
     * Add (+1) or remove (-1) a receipt's quantities to its purchase order
     * and complete the order once everything arrived.
     */
    protected function addToOrder(PurchaseReceipt $receipt, int $sign): void
    {
        if ($receipt->purchase_order_id === null) {
            return;
        }

        /** @var PurchaseOrder|null $order */
        $order = PurchaseOrder::query()->with('items')->lockForUpdate()->find($receipt->purchase_order_id);

        if ($order === null) {
            return;
        }

        foreach ($receipt->items as $item) {
            $left = $item->quantity - ($sign < 0 ? $item->returned_quantity : 0);

            foreach ($order->items->where('variant_id', $item->variant_id) as $line) {
                if ($left <= 0) {
                    break;
                }

                $take = $sign > 0 ? min($left, max(0, $line->quantity - $line->received_quantity)) : min($left, $line->received_quantity);
                $line->update(['received_quantity' => $line->received_quantity + $sign * $take]);
                $left -= $take;
            }
        }

        $order->load('items');

        if ($sign > 0 && $order->status === PurchaseOrder::PENDING && $order->receiveStatus() === 'full') {
            $order->update(['status' => PurchaseOrder::COMPLETED]);
        } elseif ($sign < 0 && $order->status === PurchaseOrder::COMPLETED && $order->receiveStatus() !== 'full') {
            $order->update(['status' => PurchaseOrder::PENDING]);
        }
    }

    /**
     * Only goods and services can be bought; combos are made of goods.
     *
     * @param  list<LineInput>  $items
     */
    protected function checkVariants(array $items): void
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Thêm ít nhất một sản phẩm.']);
        }

        $ids = array_unique(array_map(fn (array $item): int => (int) $item['variant_id'], $items));
        $variants = ProductVariant::query()->with('product')->whereIn('id', $ids)->get();

        if ($variants->count() !== count($ids)) {
            throw ValidationException::withMessages(['items' => 'Có sản phẩm không tồn tại hoặc đã bị xóa.']);
        }

        foreach ($variants as $variant) {
            if ($variant->product->type === Product::TYPE_COMBO) {
                throw ValidationException::withMessages(['items' => "{$variant->name} là combo, hãy nhập từng sản phẩm thành phần."]);
            }
        }

        foreach ($items as $index => $item) {
            if ((int) $item['quantity'] <= 0) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => 'Số lượng phải lớn hơn 0.']);
            }
        }
    }

    public function supplierCode(): string
    {
        return Sequence::next('NCC', Supplier::class);
    }
}
