<?php

namespace Modules\Cashbook\Services;

use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Sequences\Sequence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cashbook\Models\CashVoucher;
use Modules\Cashbook\Models\CashVoucherType;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerDebt;
use Modules\Customer\Services\CustomerLedger;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderPayment;
use Modules\Purchase\Models\PurchaseReceipt;
use Modules\Purchase\Models\Supplier;
use Modules\Purchase\Models\SupplierDebt;
use Modules\Purchase\Services\SupplierLedger;

/**
 * Sổ quỹ: the only place vouchers are created or cancelled.
 *
 * - Money taken or refunded for an order, and payments to suppliers, become
 *   automatic vouchers (type "Tự động") with the order / receipt as chứng từ
 *   gốc. They follow their document and cannot be edited or cancelled here.
 * - Vouchers entered by hand may change the debt of the customer or
 *   supplier ("Thay đổi công nợ"); cancelling one reverses that change.
 */
class CashbookService
{
    public function __construct(
        protected CustomerLedger $customers,
        protected SupplierLedger $suppliers,
    ) {}

    /**
     * A voucher entered by hand.
     *
     * @param  array{kind: string, branch_id: int, type_id: int, party_type: string, party_id?: int|null, party_name?: string|null, amount: int, payment_method_id: int, reference?: string|null, changes_debt?: bool, affects_profit?: bool, description?: string|null, tags?: string|null, recorded_at?: CarbonImmutable|null}  $data
     */
    public function create(array $data, User $user): CashVoucher
    {
        $kind = $data['kind'];
        $type = CashVoucherType::query()->where('kind', $kind)->findOrFail($data['type_id']);

        if ($type->code === CashVoucherType::AUTO) {
            throw ValidationException::withMessages(['type_id' => 'Loại phiếu "Tự động" chỉ dành cho phiếu tạo từ chứng từ khác.']);
        }

        $method = PaymentMethod::query()->where('is_active', true)->find($data['payment_method_id'])
            ?? throw ValidationException::withMessages(['payment_method_id' => 'Hình thức thanh toán không hợp lệ hoặc đã ngừng dùng.']);

        [$partyId, $partyName] = $this->party($data['party_type'], $data['party_id'] ?? null, $data['party_name'] ?? null);
        $changesDebt = ($data['changes_debt'] ?? false) && in_array($data['party_type'], ['customer', 'supplier'], true);

        return DB::transaction(function () use ($data, $user, $kind, $type, $method, $partyId, $partyName, $changesDebt): CashVoucher {
            /** @var CashVoucher $voucher */
            $voucher = CashVoucher::query()->create([
                'branch_id' => $data['branch_id'],
                'kind' => $kind,
                'code' => Sequence::next(CashVoucher::prefix($kind), CashVoucher::class),
                'type_id' => $type->id,
                'status' => CashVoucher::COMPLETED,
                'is_auto' => false,
                'party_type' => $data['party_type'],
                'party_id' => $partyId,
                'party_name' => $partyName,
                'amount' => $data['amount'],
                'payment_method_id' => $method->id,
                'method_name' => $method->name,
                'method_type' => $method->type,
                'reference' => $data['reference'] ?? null,
                'changes_debt' => $changesDebt,
                'affects_profit' => $data['affects_profit'] ?? $type->affects_profit,
                'description' => $data['description'] ?? null,
                'tags' => $data['tags'] ?? null,
                'recorded_at' => $data['recorded_at'] ?? now(),
                'created_by' => $user->id,
            ]);

            if ($changesDebt) {
                $this->changeDebt($voucher, 1);
            }

            return $voucher;
        });
    }

    /**
     * Change the details of a voucher entered by hand. Money, party and
     * fund stay as created: cancel and enter a new voucher to change them.
     *
     * @param  array{type_id?: int, reference?: string|null, affects_profit?: bool, description?: string|null, tags?: string|null, recorded_at?: CarbonImmutable|null}  $data
     */
    public function update(CashVoucher $voucher, array $data): void
    {
        $this->ensureManual($voucher);

        if (isset($data['type_id'])) {
            $type = CashVoucherType::query()->where('kind', $voucher->kind)->findOrFail($data['type_id']);

            if ($type->code === CashVoucherType::AUTO) {
                throw ValidationException::withMessages(['type_id' => 'Loại phiếu "Tự động" chỉ dành cho phiếu tạo từ chứng từ khác.']);
            }
        }

        $voucher->fill(array_filter([
            'type_id' => $data['type_id'] ?? null,
            'recorded_at' => $data['recorded_at'] ?? null,
            'affects_profit' => $data['affects_profit'] ?? null,
        ], fn (mixed $value): bool => $value !== null));

        foreach (['reference', 'description', 'tags'] as $field) {
            if (array_key_exists($field, $data)) {
                $voucher->{$field} = $data[$field];
            }
        }

        $voucher->save();
    }

    public function cancel(CashVoucher $voucher, User $user): void
    {
        $this->ensureManual($voucher);

        DB::transaction(function () use ($voucher, $user): void {
            /** @var CashVoucher $locked */
            $locked = CashVoucher::query()->lockForUpdate()->findOrFail($voucher->id);

            if ($locked->isCancelled()) {
                throw ValidationException::withMessages(['status' => 'Phiếu đã hủy.']);
            }

            $locked->forceFill(['status' => CashVoucher::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $user->id])->save();

            if ($locked->changes_debt) {
                $this->changeDebt($locked, -1);
            }

            $voucher->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Phiếu thu (payment) or phiếu chi (refund) for money of an order.
     * Running it twice for the same payment does nothing.
     */
    public function recordOrderPayment(OrderPayment $payment): ?CashVoucher
    {
        if ($payment->amount === 0 || $this->existsFor($payment->getMorphClass(), $payment->id)) {
            return null;
        }

        /** @var Order $order */
        $order = Order::query()->findOrFail($payment->order_id);
        $kind = $payment->amount > 0 ? CashVoucher::RECEIPT : CashVoucher::PAYMENT;
        $document = $payment->reference_type !== null && $payment->reference_id !== null
            ? $payment->reference()->first()
            : null;

        return $this->createAuto([
            'branch_id' => $payment->branch_id,
            'kind' => $kind,
            'party_type' => $order->customer_id !== null ? 'customer' : 'other',
            'party_id' => $order->customer_id,
            'party_name' => $order->customer_name ?: 'Khách lẻ',
            'amount' => abs($payment->amount),
            'payment_method_id' => $payment->payment_method_id,
            'method_name' => $payment->method_name,
            'method_type' => $payment->method_type,
            'origin_type' => $payment->getMorphClass(),
            'origin_id' => $payment->id,
            'document_type' => ($document ?? $order)->getMorphClass(),
            'document_id' => ($document ?? $order)->getKey(),
            'document_code' => $document?->getAttribute('code') ?? $order->code,
            'changes_debt' => $order->customer_id !== null,
            'description' => $kind === CashVoucher::RECEIPT
                ? 'Phiếu thu tự động tạo khi khách hàng thanh toán cho đơn hàng'
                : 'Phiếu chi tự động tạo khi hoàn tiền cho khách hàng',
            'recorded_at' => $payment->paid_at,
            'created_by' => $payment->created_by,
        ]);
    }

    /**
     * Phiếu chi for money paid to a supplier on a purchase receipt.
     */
    public function recordSupplierPayment(SupplierDebt $line): ?CashVoucher
    {
        if ($line->type !== SupplierDebt::PAYMENT || $line->amount === 0 || $this->existsFor($line->getMorphClass(), $line->id)) {
            return null;
        }

        $receipt = $line->reference_type === (new PurchaseReceipt)->getMorphClass()
            ? PurchaseReceipt::query()->find($line->reference_id)
            : null;

        if ($receipt === null) {
            return null;
        }

        /** @var Supplier $supplier */
        $supplier = Supplier::query()->withTrashed()->findOrFail($line->supplier_id);
        // Purchase payments are recorded as cash or bank transfer.
        $method = PaymentMethod::query()
            ->where('type', $line->payment_method === 'cash' ? 'cash' : 'bank')
            ->orderByDesc('is_system')
            ->orderBy('id')
            ->first();

        return $this->createAuto([
            'branch_id' => $receipt->branch_id,
            'kind' => $line->amount < 0 ? CashVoucher::PAYMENT : CashVoucher::RECEIPT,
            'party_type' => 'supplier',
            'party_id' => $supplier->id,
            'party_name' => $supplier->name,
            'amount' => abs($line->amount),
            'payment_method_id' => $method?->id,
            'method_name' => $method->name ?? ($line->payment_method === 'cash' ? 'Tiền mặt' : 'Chuyển khoản'),
            'method_type' => $line->payment_method === 'cash' ? 'cash' : 'bank',
            'origin_type' => $line->getMorphClass(),
            'origin_id' => $line->id,
            'document_type' => $receipt->getMorphClass(),
            'document_id' => $receipt->id,
            'document_code' => $receipt->code,
            'changes_debt' => true,
            'description' => 'Phiếu chi tự động tạo khi thanh toán cho nhà cung cấp',
            'recorded_at' => $line->created_at ?? now(),
            'created_by' => $line->created_by,
        ]);
    }

    /**
     * Automatic vouchers for payments recorded before the cash book existed
     * (or missed). Runs in the current store. Returns how many were created.
     */
    public function sync(): int
    {
        $created = 0;

        OrderPayment::query()
            ->whereNotIn('id', CashVoucher::query()->where('origin_type', (new OrderPayment)->getMorphClass())->select('origin_id'))
            ->orderBy('id')
            ->each(function (OrderPayment $payment) use (&$created): void {
                $created += $this->recordOrderPayment($payment) === null ? 0 : 1;
            });

        SupplierDebt::query()
            ->where('type', SupplierDebt::PAYMENT)
            ->whereNotIn('id', CashVoucher::query()->where('origin_type', (new SupplierDebt)->getMorphClass())->select('origin_id'))
            ->orderBy('id')
            ->each(function (SupplierDebt $line) use (&$created): void {
                $created += $this->recordSupplierPayment($line) === null ? 0 : 1;
            });

        return $created;
    }

    /**
     * Opening balance, money in, money out and closing balance of the
     * completed vouchers matching $scope, for [$from, $to].
     *
     * @param  callable(Builder<CashVoucher>): mixed  $scope
     * @return array{opening: int, receipts: int, payments: int, closing: int}
     */
    public function summary(callable $scope, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $base = function () use ($scope): Builder {
            $query = CashVoucher::query()->where('status', CashVoucher::COMPLETED);
            $scope($query);

            return $query;
        };

        $signed = "SUM(CASE WHEN kind = 'receipt' THEN amount ELSE -amount END)";
        $opening = (int) $base()->where('recorded_at', '<', $from)->selectRaw("{$signed} as total")->value('total');
        $period = $base()->whereBetween('recorded_at', [$from, $to])
            ->selectRaw("SUM(CASE WHEN kind = 'receipt' THEN amount ELSE 0 END) as receipts, SUM(CASE WHEN kind = 'payment' THEN amount ELSE 0 END) as payments")
            ->first();
        $receipts = (int) $period?->getAttribute('receipts');
        $payments = (int) $period?->getAttribute('payments');

        return ['opening' => $opening, 'receipts' => $receipts, 'payments' => $payments, 'closing' => $opening + $receipts - $payments];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createAuto(array $attributes): CashVoucher
    {
        return DB::transaction(function () use ($attributes): CashVoucher {
            $kind = (string) $attributes['kind'];

            /** @var CashVoucher $voucher */
            $voucher = CashVoucher::query()->create([
                ...$attributes,
                'code' => Sequence::next(CashVoucher::prefix($kind), CashVoucher::class),
                'type_id' => CashVoucherType::auto($kind)->id,
                'status' => CashVoucher::COMPLETED,
                'is_auto' => true,
                'affects_profit' => false,
            ]);

            return $voucher;
        });
    }

    protected function existsFor(string $type, int $id): bool
    {
        return CashVoucher::query()->where('origin_type', $type)->where('origin_id', $id)->exists();
    }

    protected function ensureManual(CashVoucher $voucher): void
    {
        if ($voucher->is_auto) {
            throw ValidationException::withMessages(['status' => 'Phiếu tự động chỉ thay đổi theo chứng từ gốc ('.$voucher->document_code.').']);
        }

        if ($voucher->isCancelled()) {
            throw ValidationException::withMessages(['status' => 'Phiếu đã hủy, không sửa được.']);
        }
    }

    /**
     * Who pays or receives: [id, name]. Customers, suppliers and staff must
     * belong to the store; "other" is a free name.
     *
     * @return array{0: int|null, 1: string}
     */
    protected function party(string $type, ?int $id, ?string $name): array
    {
        $record = match ($type) {
            'customer' => $id === null ? null : Customer::query()->find($id),
            'supplier' => $id === null ? null : Supplier::query()->find($id),
            'staff' => $id === null ? null : User::query()->find($id),
            'other' => null,
            default => throw ValidationException::withMessages(['party_type' => 'Nhóm người nộp/nhận không hợp lệ.']),
        };

        if ($type === 'other') {
            $name = trim((string) $name);

            if ($name === '') {
                throw ValidationException::withMessages(['party_name' => 'Hãy nhập tên người nộp/nhận.']);
            }

            return [null, mb_substr($name, 0, 255)];
        }

        if ($record === null) {
            throw ValidationException::withMessages(['party_id' => 'Hãy chọn '.mb_strtolower(CashVoucher::PARTIES[$type]).'.']);
        }

        return [(int) $record->getKey(), (string) $record->getAttribute('name')];
    }

    /**
     * Apply ($sign = 1) or reverse ($sign = -1) the debt change of a voucher.
     * Customer: a receipt lowers what they owe, a payment raises it.
     * Supplier: a payment lowers what the store owes, a receipt raises it.
     */
    protected function changeDebt(CashVoucher $voucher, int $sign): void
    {
        $note = ($sign > 0 ? '' : 'Hủy ').CashVoucher::label($voucher->kind).' '.$voucher->code;
        $options = ['reference' => $voucher, 'reference_code' => $voucher->code, 'note' => ucfirst($note)];

        if ($voucher->party_type === 'customer') {
            $customer = Customer::query()->withTrashed()->findOrFail($voucher->party_id);
            $amount = $voucher->kind === CashVoucher::RECEIPT ? -$voucher->amount : $voucher->amount;
            $type = $sign < 0 ? CustomerDebt::ADJUSTMENT : ($voucher->kind === CashVoucher::RECEIPT ? CustomerDebt::PAYMENT : CustomerDebt::REFUND);
            $this->customers->record($customer, $type, $sign * $amount, $options);
        }

        if ($voucher->party_type === 'supplier') {
            $supplier = Supplier::query()->withTrashed()->findOrFail($voucher->party_id);
            $amount = $voucher->kind === CashVoucher::PAYMENT ? -$voucher->amount : $voucher->amount;
            $type = $sign < 0 ? SupplierDebt::ADJUSTMENT : SupplierDebt::PAYMENT;
            $this->suppliers->record($supplier, $type, $sign * $amount, [...$options, 'payment_method' => $voucher->method_type === 'cash' ? 'cash' : 'transfer']);
        }
    }
}
