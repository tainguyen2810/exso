<?php

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Support\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Cashbook\Models\CashVoucher;
use Modules\Cashbook\Models\CashVoucherType;
use Modules\Cashbook\Services\CashbookService;
use Modules\Customer\Models\CustomerDebt;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderPayment;
use Modules\Order\Services\OrderService;
use Modules\Order\Services\ReturnService;
use Modules\Purchase\Models\PurchaseReceipt;
use Modules\Purchase\Models\Supplier;
use Modules\Purchase\Services\PurchaseService;

function cashbookSupplier(): Supplier
{
    return Supplier::query()->create(['code' => 'NCC'.fake()->unique()->numberBetween(1000, 9999), 'name' => 'Công ty Phân phối ABC', 'status' => 'active']);
}

function voucherType(string $kind, string $code): CashVoucherType
{
    return CashVoucherType::query()->where('kind', $kind)->where('code', $code)->firstOrFail();
}

function bank(): PaymentMethod
{
    return PaymentMethod::query()->where('type', 'bank')->firstOrFail();
}

test('money taken for an order becomes an automatic receipt, a refund an automatic payment', function () {
    $owner = owner();
    $this->actingAs($owner);
    $mouse = stocked(10, 80000, [], ['retail_price' => 150000]);
    $an = customer(['name' => 'Trần Thị Bình']);
    $orders = app(OrderService::class);

    $order = $orders->create(orderData([[$mouse, 2]], ['customer_id' => $an->id, 'payments' => [['payment_method_id' => cash()->id, 'amount' => 300000]]]), $owner, 'approve');

    $receipt = CashVoucher::query()->sole();
    expect($receipt->code)->toBe('RVN00001')
        ->and($receipt->kind)->toBe(CashVoucher::RECEIPT)
        ->and($receipt->is_auto)->toBeTrue()
        ->and($receipt->type->code)->toBe(CashVoucherType::AUTO)
        ->and($receipt->amount)->toBe(300000)
        ->and($receipt->party_type)->toBe('customer')
        ->and($receipt->party_name)->toBe('Trần Thị Bình')
        ->and($receipt->document_code)->toBe($order->code)
        ->and($receipt->method_type)->toBe('cash')
        ->and($receipt->branch_id)->toBe(mainBranch()->id);

    $orders->cancel($order);

    $payment = CashVoucher::query()->where('kind', CashVoucher::PAYMENT)->sole();
    expect($payment->code)->toBe('PVN00001')
        ->and($payment->amount)->toBe(300000)
        ->and($payment->document_code)->toBe($order->code);

    // Paying again for the same order payment does not duplicate the voucher.
    expect(app(CashbookService::class)->recordOrderPayment(OrderPayment::query()->firstOrFail()))->toBeNull()
        ->and(CashVoucher::query()->count())->toBe(2);
});

test('a refund for a customer return points to the return', function () {
    $owner = owner();
    $this->actingAs($owner);
    $mouse = stocked(10, 80000, [], ['retail_price' => 150000]);
    $orders = app(OrderService::class);
    $order = $orders->create(orderData([[$mouse, 2]], ['delivery_method' => 'pickup', 'payments' => [['payment_method_id' => cash()->id, 'amount' => 300000]]]), $owner, 'export');

    $return = app(ReturnService::class)->create($order, [$order->items->first()->id => 1], refund: ['payment_method_id' => cash()->id, 'amount' => 150000]);

    $payment = CashVoucher::query()->where('kind', CashVoucher::PAYMENT)->sole();
    expect($payment->party_type)->toBe('other')
        ->and($payment->party_name)->toBe('Khách lẻ')
        ->and($payment->document_code)->toBe($return->code)
        ->and($payment->amount)->toBe(150000);
});

test('paying a supplier for a receipt creates an automatic payment voucher', function () {
    $owner = owner();
    $this->actingAs($owner);
    $abc = cashbookSupplier();
    $mouse = variant();
    $purchases = app(PurchaseService::class);
    $receipt = $purchases->saveReceipt(null, [
        'supplier_id' => $abc->id,
        'branch_id' => mainBranch()->id,
        'items' => [['variant_id' => $mouse->id, 'quantity' => 10, 'price' => 100000]],
    ], true);

    $purchases->pay($receipt, 400000, 'transfer');

    $voucher = CashVoucher::query()->sole();
    expect($voucher->kind)->toBe(CashVoucher::PAYMENT)
        ->and($voucher->is_auto)->toBeTrue()
        ->and($voucher->party_type)->toBe('supplier')
        ->and($voucher->party_id)->toBe($abc->id)
        ->and($voucher->amount)->toBe(400000)
        ->and($voucher->method_type)->toBe('bank')
        ->and($voucher->document_type)->toBe((new PurchaseReceipt)->getMorphClass())
        ->and($voucher->document_code)->toBe($receipt->code)
        ->and($abc->refresh()->debt)->toBe(600000);
});

test('a manual receipt can lower a customer debt and cancelling it restores the debt', function () {
    $owner = owner();
    $this->actingAs($owner);
    $an = customer();
    app(\Modules\Customer\Services\CustomerLedger::class)->record($an, CustomerDebt::ADJUSTMENT, 500000);
    $cashbook = app(CashbookService::class);

    $voucher = $cashbook->create([
        'kind' => CashVoucher::RECEIPT,
        'branch_id' => mainBranch()->id,
        'type_id' => voucherType(CashVoucher::RECEIPT, 'THUNOKH')->id,
        'party_type' => 'customer',
        'party_id' => $an->id,
        'amount' => 200000,
        'payment_method_id' => cash()->id,
        'changes_debt' => true,
    ], $owner);

    expect($voucher->is_auto)->toBeFalse()
        ->and($voucher->changes_debt)->toBeTrue()
        ->and($voucher->affects_profit)->toBeFalse()
        ->and($an->refresh()->debt)->toBe(300000);

    $cashbook->cancel($voucher, $owner);

    expect($voucher->status)->toBe(CashVoucher::CANCELLED)
        ->and($an->refresh()->debt)->toBe(500000)
        ->and(CustomerDebt::query()->where('customer_id', $an->id)->sum('amount'))->toEqual(500000);
});

test('a manual payment to a supplier lowers what the store owes', function () {
    $owner = owner();
    $this->actingAs($owner);
    $abc = cashbookSupplier();
    app(\Modules\Purchase\Services\SupplierLedger::class)->record($abc, 'purchase', 1000000);

    app(CashbookService::class)->create([
        'kind' => CashVoucher::PAYMENT,
        'branch_id' => mainBranch()->id,
        'type_id' => voucherType(CashVoucher::PAYMENT, 'TRANONCC')->id,
        'party_type' => 'supplier',
        'party_id' => $abc->id,
        'amount' => 250000,
        'payment_method_id' => bank()->id,
        'changes_debt' => true,
    ], $owner);

    expect($abc->refresh()->debt)->toBe(750000);
});

test('automatic vouchers cannot be edited or cancelled by hand', function () {
    $owner = owner();
    $this->actingAs($owner);
    $mouse = stocked(5, 50000, [], ['retail_price' => 100000]);
    app(OrderService::class)->create(orderData([[$mouse, 1]], ['payments' => [['payment_method_id' => cash()->id, 'amount' => 100000]]]), $owner);
    $voucher = CashVoucher::query()->sole();

    expect(fn () => app(CashbookService::class)->cancel($voucher, $owner))->toThrow(ValidationException::class);

    $this->post("/cashbook/vouchers/{$voucher->id}/cancel")->assertSessionHasErrors('status');
    expect($voucher->refresh()->status)->toBe(CashVoucher::COMPLETED);
});

test('the cash book closing balance matches the vouchers of the period', function () {
    $owner = owner();
    $this->actingAs($owner);
    $cashbook = app(CashbookService::class);
    $other = voucherType(CashVoucher::RECEIPT, 'THUKHAC');
    $rent = voucherType(CashVoucher::PAYMENT, 'MATBANG');
    $make = fn (string $kind, CashVoucherType $type, int $amount, string $day, ?PaymentMethod $method = null) => $cashbook->create([
        'kind' => $kind, 'branch_id' => mainBranch()->id, 'type_id' => $type->id, 'party_type' => 'other', 'party_name' => 'Anh Tư',
        'amount' => $amount, 'payment_method_id' => ($method ?? cash())->id, 'recorded_at' => CarbonImmutable::parse($day.' 10:00'),
    ], $owner);

    $make(CashVoucher::RECEIPT, $other, 1000000, '2026-09-20');
    $make(CashVoucher::PAYMENT, $rent, 300000, '2026-09-25');
    $make(CashVoucher::RECEIPT, $other, 500000, '2026-10-02');
    $make(CashVoucher::PAYMENT, $rent, 200000, '2026-10-15');
    $make(CashVoucher::RECEIPT, $other, 9000000, '2026-10-16', bank());
    $cancelled = $make(CashVoucher::RECEIPT, $other, 700000, '2026-10-20');
    $cashbook->cancel($cancelled, $owner);
    $make(CashVoucher::PAYMENT, $rent, 50000, '2026-11-02');

    $cashOnly = fn ($query) => $query->where('method_type', 'cash');
    $summary = $cashbook->summary($cashOnly, CarbonImmutable::parse('2026-10-01')->startOfDay(), CarbonImmutable::parse('2026-10-31')->endOfDay());

    expect($summary)->toBe(['opening' => 700000, 'receipts' => 500000, 'payments' => 200000, 'closing' => 1000000]);

    // Closing balance = every completed cash voucher up to the end of the period.
    $all = CashVoucher::query()->where('status', CashVoucher::COMPLETED)->where('method_type', 'cash')
        ->where('recorded_at', '<=', CarbonImmutable::parse('2026-10-31')->endOfDay())->get();
    expect($all->sum(fn (CashVoucher $voucher) => $voucher->signedAmount()))->toBe($summary['closing']);

    $this->get('/cashbook?filter[recorded_at][from]=01/10/2026&filter[recorded_at][to]=31/10/2026&filter[fund]=cash')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('cashbook/index')
            ->where('summary', $summary)
            ->has('vouchers.rows.data', 2));
});

test('vouchers are entered over HTTP, limited to the user branches and permissions', function () {
    $owner = owner();
    $other = Branch::query()->create(['code' => 'CN02', 'name' => 'Quận 3']);

    $this->actingAs($owner)->post('/cashbook/vouchers', [
        'kind' => 'payment',
        'branch_id' => mainBranch()->id,
        'type_id' => voucherType(CashVoucher::PAYMENT, 'DIENNUOC')->id,
        'party_type' => 'other',
        'party_name' => 'Điện lực',
        'amount' => 1250000,
        'payment_method_id' => cash()->id,
        'description' => 'Tiền điện tháng 10',
    ])->assertRedirect();

    $voucher = CashVoucher::query()->sole();
    expect($voucher->code)->toBe('PVN00001')
        ->and($voucher->affects_profit)->toBeTrue()
        ->and($voucher->created_by)->toBe($owner->id);

    $this->get('/cashbook/payments')->assertOk()->assertInertia(fn ($page) => $page->component('cash-vouchers/index')->has('vouchers.rows.data', 1));
    $this->get("/cashbook/vouchers/{$voucher->id}")->assertOk()->assertInertia(fn ($page) => $page->component('cash-vouchers/form')->where('voucher.code', 'PVN00001'));
    $this->get('/cashbook/payments?export=xlsx')->assertOk();

    // The "Tự động" type is only for automatic vouchers.
    $this->post('/cashbook/vouchers', [
        'kind' => 'receipt', 'branch_id' => mainBranch()->id, 'type_id' => voucherType(CashVoucher::RECEIPT, CashVoucherType::AUTO)->id,
        'party_type' => 'other', 'party_name' => 'X', 'amount' => 1000, 'payment_method_id' => cash()->id,
    ])->assertSessionHasErrors('type_id');

    // A cashier does not see the cash book; an accountant of another branch cannot open the voucher.
    $cashier = staffWithRole('Thu ngân');
    $this->actingAs($cashier)->get('/cashbook/receipts')->assertForbidden();

    $accountant = staffWithRole('Kế toán', $other);
    $this->actingAs($accountant)->get("/cashbook/vouchers/{$voucher->id}")->assertNotFound();
    $this->actingAs($accountant)->post('/cashbook/vouchers', [
        'kind' => 'receipt', 'branch_id' => mainBranch()->id, 'type_id' => voucherType(CashVoucher::RECEIPT, 'THUKHAC')->id,
        'party_type' => 'other', 'party_name' => 'X', 'amount' => 1000, 'payment_method_id' => cash()->id,
    ])->assertSessionHasErrors('branch_id');
});

test('the record date can only be changed when the store allows it', function () {
    $owner = owner();
    $payload = [
        'kind' => 'receipt', 'branch_id' => mainBranch()->id, 'type_id' => voucherType(CashVoucher::RECEIPT, 'THUKHAC')->id,
        'party_type' => 'other', 'party_name' => 'X', 'amount' => 1000, 'payment_method_id' => cash()->id, 'recorded_at' => '01/10/2026',
    ];

    $this->actingAs($owner)->post('/cashbook/vouchers', $payload);
    expect(CashVoucher::query()->latest('id')->first()->recorded_at->toDateString())->toBe(now()->toDateString());

    app(SettingsRepository::class)->setMany(['cashbook.allow_change_date' => true]);
    $this->post('/cashbook/vouchers', $payload);
    expect(CashVoucher::query()->latest('id')->first()->recorded_at->toDateString())->toBe('2026-10-01');

    $this->post('/cashbook/vouchers', [...$payload, 'recorded_at' => now()->addDays(3)->format('d/m/Y')])->assertSessionHasErrors('recorded_at');
});

test('voucher types: built-in ones stay, used ones cannot be deleted', function () {
    $owner = owner();
    $this->actingAs($owner);

    $this->post('/cashbook/types', ['kind' => 'payment', 'name' => 'Túi zip', 'affects_profit' => true])->assertRedirect();
    $zip = CashVoucherType::query()->where('name', 'Túi zip')->sole();
    expect($zip->code)->toStartWith('PVG')
        ->and($zip->affects_profit)->toBeTrue();

    $this->get('/cashbook/payment-types')->assertOk()->assertInertia(fn ($page) => $page->component('cash-voucher-types/index'));

    $this->delete('/cashbook/types/'.voucherType(CashVoucher::PAYMENT, CashVoucherType::AUTO)->id);
    expect(CashVoucherType::query()->where('code', CashVoucherType::AUTO)->count())->toBe(2);

    app(CashbookService::class)->create([
        'kind' => 'payment', 'branch_id' => mainBranch()->id, 'type_id' => $zip->id, 'party_type' => 'other', 'party_name' => 'X',
        'amount' => 1000, 'payment_method_id' => cash()->id,
    ], $owner);
    $this->delete("/cashbook/types/{$zip->id}");
    expect($zip->fresh())->not->toBeNull();
});

test('cashbook:sync creates the vouchers missing for older payments, once', function () {
    $owner = owner();
    $this->actingAs($owner);
    $mouse = stocked(5, 50000, [], ['retail_price' => 100000]);
    app(OrderService::class)->create(orderData([[$mouse, 1]], ['payments' => [['payment_method_id' => cash()->id, 'amount' => 100000]]]), $owner);
    CashVoucher::query()->delete();

    $this->artisan('cashbook:sync')->assertSuccessful();
    $this->artisan('cashbook:sync')->assertSuccessful();

    expect(CashVoucher::query()->count())->toBe(1)
        ->and(Order::query()->count())->toBe(1);
});
