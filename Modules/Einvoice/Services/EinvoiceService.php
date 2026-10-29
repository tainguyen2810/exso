<?php

namespace Modules\Einvoice\Services;

use App\Models\TaxRate;
use App\Models\User;
use App\Support\Sequences\Sequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Einvoice\Drivers\EinvoiceException;
use Modules\Einvoice\Drivers\EinvoiceManager;
use Modules\Einvoice\Models\Einvoice;
use Modules\Einvoice\Models\EinvoiceItem;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;

/**
 * Everything that changes an e-invoice. Amounts are computed here from the
 * lines (never taken from the browser):
 *
 *  - fromOrder: a draft with the buyer from the customer (tax code,
 *    company) and the lines of the order still kept by the customer; the
 *    order discount is spread over the lines (like Sapo), the shipping fee
 *    is a line of its own
 *  - issue: sent to the provider with its transaction_uuid; a retry first
 *    asks the provider whether that uuid was already issued
 *  - cancel, adjust: an issued invoice is never edited; it is cancelled or
 *    corrected by an adjustment invoice ("hóa đơn điều chỉnh")
 *
 * @phpstan-type LineInput array{variant_id?: int|null, code?: string|null, name: string, unit?: string|null, quantity: int, price: int, discount?: int, tax_percent: int, is_promotion?: bool}
 */
class EinvoiceService
{
    public function __construct(protected EinvoiceManager $drivers) {}

    /**
     * A draft invoice for an exported order.
     */
    public function fromOrder(Order $order, ?User $user = null): Einvoice
    {
        $order->loadMissing(['items', 'customer', 'payments']);

        if (! $order->isExported() || $order->status === Order::CANCELLED) {
            throw ValidationException::withMessages(['order' => "Đơn {$order->code} chưa xuất kho hoặc đã hủy, chưa tạo được hóa đơn."]);
        }

        $existing = $this->invoiceOf($order);

        if ($existing !== null) {
            throw ValidationException::withMessages(['order' => "Đơn {$order->code} đã có hóa đơn {$existing->code}."]);
        }

        $lines = $this->orderLines($order);

        if ($lines === []) {
            throw ValidationException::withMessages(['order' => "Đơn {$order->code} không còn sản phẩm nào để xuất hóa đơn (đã trả hết)."]);
        }

        $customer = $order->customer;
        $address = $customer === null ? $order->shipping_address : implode(', ', array_filter([$customer->address, $customer->ward, $customer->district, $customer->province]));
        // Marketplace buyers come masked (L******h): no invoice in their name.
        $masked = $customer !== null && $customer->marketplace_id !== null && $customer->tax_code === null;

        return DB::transaction(function () use ($order, $user, $customer, $address, $masked, $lines): Einvoice {
            $invoice = new Einvoice([
                'branch_id' => $order->branch_id,
                'order_id' => $order->id,
                'buyer_name' => $customer === null || $masked ? null : mb_substr($customer->name, 0, 255),
                'buyer_company' => $customer?->tax_code !== null ? mb_substr($customer->name, 0, 255) : null,
                'buyer_tax_code' => $customer?->tax_code,
                'buyer_address' => $masked || $address === null || $address === '' ? null : mb_substr($address, 0, 500),
                'buyer_email' => $masked ? null : $customer?->email,
                'buyer_phone' => $masked ? null : $customer?->phone,
                'payment_method' => $this->paymentLabel($order),
            ]);
            $this->prepare($invoice, $user);
            $invoice->save();
            $this->saveLines($invoice, $lines);

            return $invoice->refresh();
        });
    }

    /**
     * Change a draft (or a failed one): buyer and lines.
     *
     * @param  array<string, mixed>  $buyer
     * @param  list<LineInput>|null  $lines  null keeps the lines
     */
    public function update(Einvoice $invoice, array $buyer, ?array $lines): Einvoice
    {
        return DB::transaction(function () use ($invoice, $buyer, $lines): Einvoice {
            $locked = $this->lock($invoice);

            if (! $locked->isEditable()) {
                throw ValidationException::withMessages(['status' => 'Hóa đơn đã phát hành hoặc đã hủy, không sửa được. Hãy lập hóa đơn điều chỉnh.']);
            }

            $locked->fill($buyer)->save();

            if ($lines !== null) {
                if ($lines === [] && $locked->adjustment_type !== 'info') {
                    throw ValidationException::withMessages(['items' => 'Thêm ít nhất một dòng hàng hóa.']);
                }

                $this->saveLines($locked, $lines);
            }

            return $locked->refresh();
        });
    }

    /**
     * "Phát hành": send to the provider. Errors leave the invoice "Phát
     * hành lỗi" with the provider's message, ready to fix and retry.
     */
    public function issue(Einvoice $invoice, ?User $user = null): Einvoice
    {
        $error = DB::transaction(function () use ($invoice, $user): ?string {
            $locked = $this->lock($invoice);

            if (! $locked->isEditable()) {
                throw ValidationException::withMessages(['status' => 'Hóa đơn đã phát hành hoặc đã hủy.']);
            }

            $this->validateForIssue($locked);
            $provider = $locked->provider ?? $this->drivers->active();

            if ($provider === null || ! $provider->is_active) {
                throw ValidationException::withMessages(['provider' => 'Chưa kết nối nhà cung cấp hóa đơn điện tử (Cấu hình → Hóa đơn điện tử).']);
            }

            $locked->forceFill([
                'provider_id' => $provider->id,
                'template_code' => $locked->template_code ?? $provider->setting('template_code'),
                'series' => $locked->series ?? $provider->setting('invoice_series'),
            ])->save();
            $locked->setRelation('provider', $provider);

            try {
                $driver = $this->drivers->for($provider);
                // A call that timed out may have issued it already.
                $issued = ($locked->status === Einvoice::FAILED ? $driver->find($locked) : null) ?? $driver->issue($locked);
            } catch (EinvoiceException $exception) {
                $locked->forceFill(['status' => Einvoice::FAILED, 'error' => mb_substr($exception->getMessage(), 0, 500)])->save();

                return $exception->getMessage();
            }

            $locked->forceFill([
                'status' => Einvoice::ISSUED,
                'invoice_no' => mb_substr($issued->invoiceNo, 0, 50),
                'lookup_code' => $issued->lookupCode === null ? null : mb_substr($issued->lookupCode, 0, 100),
                'issued_at' => $issued->issuedAt ?? now(),
                'issued_by' => $user?->id,
                'error' => null,
            ])->save();

            return null;
        });

        if ($error !== null) {
            throw ValidationException::withMessages(['provider' => $error]);
        }

        return $invoice->refresh();
    }

    /**
     * Hủy hóa đơn. A draft is only marked cancelled; an issued one is
     * cancelled with the provider first.
     */
    public function cancel(Einvoice $invoice, string $reason): Einvoice
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Nhập lý do hủy hóa đơn.']);
        }

        DB::transaction(function () use ($invoice, $reason): void {
            $locked = $this->lock($invoice);

            if ($locked->status === Einvoice::CANCELLED) {
                throw ValidationException::withMessages(['status' => 'Hóa đơn đã hủy.']);
            }

            if ($locked->status === Einvoice::ISSUED) {
                if (Einvoice::query()->where('original_id', $locked->id)->where('status', Einvoice::ISSUED)->exists()) {
                    throw ValidationException::withMessages(['status' => 'Hóa đơn đã có hóa đơn điều chỉnh, không hủy được.']);
                }

                if ($locked->provider === null) {
                    throw ValidationException::withMessages(['provider' => 'Không còn kết nối nhà cung cấp đã phát hành hóa đơn.']);
                }

                try {
                    $this->drivers->for($locked->provider)->cancel($locked, $reason);
                } catch (EinvoiceException $exception) {
                    throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
                }
            }

            $locked->forceFill(['status' => Einvoice::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 255)])->save();
        });

        return $invoice->refresh();
    }

    /**
     * "Lập hóa đơn điều chỉnh" for an issued invoice: a draft with the same
     * buyer and lines, to change into the difference before issuing.
     */
    public function adjust(Einvoice $original, string $type, ?User $user = null): Einvoice
    {
        if (! array_key_exists($type, Einvoice::ADJUSTMENT_TYPES)) {
            throw ValidationException::withMessages(['adjustment_type' => 'Chọn loại điều chỉnh.']);
        }

        if ($original->status !== Einvoice::ISSUED || $original->kind !== Einvoice::ORIGINAL) {
            throw ValidationException::withMessages(['status' => 'Chỉ lập hóa đơn điều chỉnh cho hóa đơn gốc đã phát hành.']);
        }

        return DB::transaction(function () use ($original, $type, $user): Einvoice {
            $invoice = $original->replicate(['code', 'transaction_uuid', 'status', 'invoice_no', 'lookup_code', 'issued_at', 'issued_by', 'error', 'cancelled_at', 'cancel_reason', 'created_by']);
            $invoice->forceFill(['kind' => Einvoice::ADJUSTMENT, 'original_id' => $original->id, 'adjustment_type' => $type, 'note' => null]);
            $this->prepare($invoice, $user);
            $invoice->save();

            $lines = $type === 'info' ? [] : array_values($original->items->map(fn (EinvoiceItem $item): array => [
                'variant_id' => $item->variant_id,
                'code' => $item->code,
                'name' => $item->name,
                'unit' => $item->unit,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'discount' => $item->discount,
                'tax_percent' => $item->tax_percent,
                'is_promotion' => $item->is_promotion,
            ])->all());
            $this->saveLines($invoice, $lines);

            return $invoice->refresh();
        });
    }

    /**
     * The order's invoice still in force (draft or issued), if any.
     */
    public function invoiceOf(Order $order): ?Einvoice
    {
        return Einvoice::query()->where('order_id', $order->id)->where('kind', Einvoice::ORIGINAL)
            ->where('status', '!=', Einvoice::CANCELLED)->latest('id')->first();
    }

    /**
     * Lines of an order as sold: quantity still kept (returns taken out),
     * line discounts, then the order discount spread by value so the lines
     * add up to what the customer pays for the goods.
     *
     * @return list<LineInput>
     */
    public function orderLines(Order $order): array
    {
        $tax = $this->defaultTax();
        $lines = [];
        $bases = [];

        foreach ($order->items as $item) {
            /** @var OrderItem $item */
            $quantity = $item->quantity - $item->returned_quantity;

            if ($quantity <= 0) {
                continue;
            }

            $discount = $item->quantity === 0 ? 0 : intdiv($item->discount * $quantity + intdiv($item->quantity, 2), $item->quantity);
            $lines[] = [
                'variant_id' => $item->variant_id,
                'code' => $item->sku,
                'name' => $item->name,
                'unit' => $item->unit,
                'quantity' => $quantity,
                'price' => $item->price,
                'discount' => min($discount, $quantity * $item->price),
                'tax_percent' => $tax,
                'is_promotion' => (bool) $item->is_gift,
            ];
            $bases[] = $quantity * $item->price - min($discount, $quantity * $item->price);
        }

        // Order discount (typed + promotions) still to spread over the lines.
        $goods = $order->total - $order->returned_total - $order->shipping_fee;
        $extra = array_sum($bases) - $goods;

        if ($extra > 0 && array_sum($bases) > 0) {
            $shares = $this->spread($extra, $bases);
            $lines = array_map(fn (array $line, int $share, int $base): array => [...$line, 'discount' => $line['discount'] + min($share, $base)], $lines, $shares, $bases);
        }

        if ($order->shipping_fee > 0 && $lines !== []) {
            $lines[] = ['code' => 'PHIGIAOHANG', 'name' => 'Phí giao hàng', 'unit' => 'Lần', 'quantity' => 1, 'price' => $order->shipping_fee, 'discount' => 0, 'tax_percent' => $tax];
        }

        return $lines;
    }

    /**
     * Amounts of one line. With prices including tax (Cấu hình → Thuế) the
     * amount is split into before tax + tax; otherwise tax is added.
     *
     * @return array{amount: int, amount_before_tax: int, tax_amount: int, amount_after_tax: int}
     */
    public function lineAmounts(int $quantity, int $price, int $discount, int $taxPercent): array
    {
        $amount = max(0, $quantity * $price - min(max(0, $discount), $quantity * $price));
        $rate = max(0, $taxPercent);

        if ($rate === 0) {
            return ['amount' => $amount, 'amount_before_tax' => $amount, 'tax_amount' => 0, 'amount_after_tax' => $amount];
        }

        if (settings('tax.price_includes_tax')) {
            $before = intdiv($amount * 100 + intdiv(100 + $rate, 2), 100 + $rate);

            return ['amount' => $amount, 'amount_before_tax' => $before, 'tax_amount' => $amount - $before, 'amount_after_tax' => $amount];
        }

        $tax = intdiv($amount * $rate + 50, 100);

        return ['amount' => $amount, 'amount_before_tax' => $amount, 'tax_amount' => $tax, 'amount_after_tax' => $amount + $tax];
    }

    /** Tax of the default sale rate (Cấu hình → Thuế), as a percentage. */
    public function defaultTax(): int
    {
        $bp = TaxRate::query()->where('code', settings('tax.default_sale'))->value('rate_bp');
        $percent = $bp === null ? 10 : intdiv((int) $bp, 100);

        return array_key_exists($percent, Einvoice::TAX_RATES) ? $percent : 10;
    }

    /**
     * @param  list<LineInput>  $lines
     */
    protected function saveLines(Einvoice $invoice, array $lines): void
    {
        $invoice->items()->delete();
        $totals = ['subtotal' => 0, 'discount' => 0, 'amount_before_tax' => 0, 'tax_amount' => 0, 'total' => 0];

        foreach ($lines as $index => $line) {
            $quantity = (int) $line['quantity'];
            $price = max(0, (int) $line['price']);

            if ($quantity <= 0) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => 'Số lượng phải lớn hơn 0.']);
            }

            $tax = (int) $line['tax_percent'];

            if (! array_key_exists($tax, Einvoice::TAX_RATES)) {
                throw ValidationException::withMessages(["items.{$index}.tax_percent" => 'Thuế suất không hợp lệ.']);
            }

            $discount = min(max(0, (int) ($line['discount'] ?? 0)), $quantity * $price);
            $amounts = $this->lineAmounts($quantity, $price, $discount, $tax);
            $invoice->items()->create([
                'variant_id' => $line['variant_id'] ?? null,
                'code' => isset($line['code']) ? mb_substr((string) $line['code'], 0, 100) : null,
                'name' => mb_substr(trim($line['name']), 0, 500),
                'unit' => isset($line['unit']) ? mb_substr((string) $line['unit'], 0, 30) : null,
                'quantity' => $quantity,
                'price' => $price,
                'discount' => $discount,
                'tax_percent' => $tax,
                'is_promotion' => (bool) ($line['is_promotion'] ?? false),
                'sort' => $index,
                ...$amounts,
            ]);

            $totals['subtotal'] += $quantity * $price;
            $totals['discount'] += $discount;
            $totals['amount_before_tax'] += $amounts['amount_before_tax'];
            $totals['tax_amount'] += $amounts['tax_amount'];
            $totals['total'] += $amounts['amount_after_tax'];
        }

        $invoice->forceFill($totals)->save();
    }

    protected function validateForIssue(Einvoice $invoice): void
    {
        $invoice->loadMissing(['items', 'original']);

        if ($invoice->items->isEmpty() && $invoice->adjustment_type !== 'info') {
            throw ValidationException::withMessages(['items' => 'Hóa đơn chưa có dòng hàng hóa.']);
        }

        if ($invoice->buyer_tax_code !== null) {
            if (preg_match('/^\d{10}(-\d{3})?$/', $invoice->buyer_tax_code) !== 1) {
                throw ValidationException::withMessages(['buyer_tax_code' => 'Mã số thuế người mua phải gồm 10 số (hoặc 10 số - 3 số chi nhánh).']);
            }

            if ($invoice->buyer_company === null || trim($invoice->buyer_company) === '') {
                throw ValidationException::withMessages(['buyer_company' => 'Có mã số thuế thì phải nhập tên đơn vị mua hàng.']);
            }

            if ($invoice->buyer_address === null || trim($invoice->buyer_address) === '') {
                throw ValidationException::withMessages(['buyer_address' => 'Có mã số thuế thì phải nhập địa chỉ đơn vị mua hàng.']);
            }
        }

        if ($invoice->kind === Einvoice::ADJUSTMENT && $invoice->original?->status !== Einvoice::ISSUED) {
            throw ValidationException::withMessages(['status' => 'Hóa đơn gốc không còn ở trạng thái đã phát hành.']);
        }
    }

    protected function prepare(Einvoice $invoice, ?User $user): void
    {
        $invoice->forceFill([
            'code' => Sequence::next('EIN', Einvoice::class),
            'status' => Einvoice::DRAFT,
            'transaction_uuid' => (string) Str::uuid(),
            'created_by' => $user->id ?? auth()->id(),
        ]);
    }

    protected function lock(Einvoice $invoice): Einvoice
    {
        /** @var Einvoice $locked */
        $locked = Einvoice::query()->with(['items', 'provider', 'original'])->lockForUpdate()->findOrFail($invoice->id);

        return $locked;
    }

    /** "Tiền mặt", "Chuyển khoản" or "TM/CK" from how the order was paid. */
    protected function paymentLabel(Order $order): string
    {
        $types = $order->payments->where('amount', '>', 0)->pluck('method_type')->unique()->values()->all();

        return match (true) {
            $types === ['cash'] => 'Tiền mặt',
            $types !== [] && array_diff($types, ['bank', 'ewallet']) === [] => 'Chuyển khoản',
            default => 'TM/CK',
        };
    }

    /**
     * Split an amount over weights, largest remainder first, so the shares
     * add up exactly.
     *
     * @param  list<int>  $weights
     * @return list<int>
     */
    protected function spread(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        $shares = [];
        $remainders = [];

        foreach ($weights as $index => $weight) {
            $shares[$index] = intdiv($amount * $weight, $total);
            $remainders[$index] = ($amount * $weight) % $total;
        }

        arsort($remainders);

        foreach (array_keys($remainders) as $index) {
            if (array_sum($shares) >= $amount) {
                break;
            }

            $shares[$index]++;
        }

        return array_values($shares);
    }
}
