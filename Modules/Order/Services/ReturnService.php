<?php

namespace Modules\Order\Services;

use App\Models\OrderReason;
use App\Support\Sequences\Sequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerDebt;
use Modules\Customer\Services\CustomerLedger;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\InventoryService;
use Modules\Order\Events\OrderReturned;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\OrderReturn;

/**
 * "Khách trả hàng": goods of an exported order come back into stock at the
 * cost they left with, the customer owes the returned value less, and the
 * money can be refunded right away.
 */
class ReturnService
{
    public function __construct(
        protected OrderService $orders,
        protected InventoryService $inventory,
        protected CustomerLedger $ledger,
    ) {}

    /**
     * @param  array<int, int>  $quantities  order item id => quantity returned
     * @param  array{payment_method_id: int, amount: int}|null  $refund
     */
    public function create(Order $order, array $quantities, ?int $reasonId = null, ?string $note = null, ?array $refund = null): OrderReturn
    {
        if ($reasonId === null && settings('reasons.require_return')) {
            throw ValidationException::withMessages(['reason_id' => 'Hãy chọn lý do trả hàng.']);
        }

        $reason = $reasonId === null ? null : OrderReason::query()->where('type', 'return')->find($reasonId);

        $return = DB::transaction(function () use ($order, $quantities, $reason, $note): OrderReturn {
            $locked = $this->orders->lock($order);

            if (! $locked->isExported()) {
                throw ValidationException::withMessages(['items' => 'Chỉ trả hàng được với đơn đã xuất kho.']);
            }

            $lines = [];

            foreach ($locked->items as $item) {
                $quantity = (int) ($quantities[$item->id] ?? 0);

                if ($quantity < 0 || $quantity > $item->quantity - $item->returned_quantity) {
                    throw ValidationException::withMessages(['items' => "Số lượng trả của {$item->name} vượt quá số còn lại."]);
                }

                if ($quantity > 0) {
                    $lines[] = [$item, $quantity];
                }
            }

            if ($lines === []) {
                throw ValidationException::withMessages(['items' => 'Chọn ít nhất một sản phẩm để trả.']);
            }

            /** @var OrderReturn $return */
            $return = OrderReturn::query()->create([
                'code' => Sequence::next('SRN', OrderReturn::class),
                'branch_id' => $locked->branch_id,
                'order_id' => $locked->id,
                'customer_id' => $locked->customer_id,
                'status' => 'received',
                'reason_id' => $reason?->id,
                'note' => $note,
                'received_at' => now(),
                'created_by' => auth()->id(),
            ]);

            usort($lines, fn (array $a, array $b): int => $a[0]->variant_id <=> $b[0]->variant_id);
            $total = 0;
            $count = 0;

            foreach ($lines as [$item, $quantity]) {
                /** @var OrderItem $item */
                $price = $this->unitRefund($locked, $item);

                if ($item->tracksStock() && $item->variant !== null) {
                    $this->inventory->move($locked->branch_id, $item->variant, $quantity, StockMovement::SALE_RETURN, [
                        'unit_cost' => $item->cost,
                        'update_cost' => $item->cost > 0,
                        'reference' => $return,
                        'reference_code' => $return->code,
                        'note' => "Trả hàng đơn {$locked->code}",
                    ]);
                }

                $return->items()->create([
                    'order_item_id' => $item->id,
                    'variant_id' => $item->variant_id,
                    'quantity' => $quantity,
                    'price' => $price,
                    'total' => $price * $quantity,
                    'cost' => $item->cost,
                ]);

                $item->forceFill(['returned_quantity' => $item->returned_quantity + $quantity])->save();
                $total += $price * $quantity;
                $count += $quantity;
            }

            $return->forceFill(['total' => $total, 'quantity' => $count])->save();
            $locked->forceFill(['returned_total' => $locked->returned_total + $total])->save();

            if ($locked->customer_id !== null) {
                /** @var Customer $customer */
                $customer = Customer::query()->withTrashed()->findOrFail($locked->customer_id);
                $this->ledger->record($customer, CustomerDebt::RETURN, -$total, ['reference' => $return, 'reference_code' => $return->code, 'note' => "Trả hàng đơn {$locked->code}"]);
            }

            $this->orders->history($locked, 'returned', "{$return->code}: ".number_format($total, 0, ',', '.'));

            return $return;
        });

        if ($refund !== null && $refund['amount'] > 0) {
            DB::transaction(function () use ($order, $return, $refund): void {
                $this->orders->refund($order, $refund['payment_method_id'], $refund['amount'], "Hoàn tiền trả hàng {$return->code}", ['reference' => $return]);
                $return->forceFill(['refunded' => $refund['amount']])->save();
            });
        }

        $this->orders->refreshCustomer($order->customer_id);
        DB::afterCommit(fn () => event(new OrderReturned($return)));

        return $return;
    }

    /**
     * Refund later for an existing return.
     */
    public function refund(OrderReturn $return, int $methodId, int $amount): void
    {
        DB::transaction(function () use ($return, $methodId, $amount): void {
            /** @var OrderReturn $locked */
            $locked = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);

            if ($amount > $locked->total - $locked->refunded) {
                throw ValidationException::withMessages(['refund_amount' => 'Số tiền hoàn vượt quá giá trị hàng trả còn chưa hoàn.']);
            }

            $this->orders->refund($locked->order, $methodId, $amount, "Hoàn tiền trả hàng {$locked->code}", ['reference' => $locked]);
            $locked->forceFill(['refunded' => $locked->refunded + $amount])->save();
            $return->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * What one unit of a line is worth back: the line value after its own
     * and its share of the order discount. Shipping is not refunded.
     */
    public function unitRefund(Order $order, OrderItem $item): int
    {
        if ($item->quantity <= 0) {
            return 0;
        }

        $share = $order->subtotal > 0 ? intdiv($order->discount * $item->total, $order->subtotal) : 0;

        return intdiv($item->total - $share, $item->quantity);
    }

    /**
     * What should be refunded by default: the part of the returned value
     * the customer has already paid for.
     */
    public function suggestedRefund(Order $order, int $returnValue): int
    {
        $dueAfter = $order->total - $order->returned_total - $returnValue;

        return max(0, min($returnValue, $order->paid - $dueAfter));
    }
}
