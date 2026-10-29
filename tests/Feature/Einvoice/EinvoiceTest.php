<?php

use App\Models\Branch;
use App\Models\User;
use App\Support\Settings\SettingsRepository;
use App\Support\Settings\StoreDefaults;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Einvoice\Models\Einvoice;
use Modules\Einvoice\Models\EinvoiceProvider;
use Modules\Einvoice\Services\EinvoiceService;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;

beforeEach(function () {
    StoreDefaults::install();
    $this->viettel = ['calls' => [], 'issued' => [], 'fail' => null, 'number' => 0];
    $viettel = &$this->viettel;

    Http::fake(function (HttpRequest $request) use (&$viettel) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $viettel['calls'][] = ['path' => $path, 'data' => $request->data(), 'cookie' => $request->header('Cookie')[0] ?? null];

        if ($path === '/auth/login') {
            return $request->data()['password'] === 'dung-mat-khau'
                ? Http::response(['access_token' => 'token-1', 'token_type' => 'bearer', 'expires_in' => 600])
                : Http::response(['code' => 400, 'message' => 'Bad credentials'], 400);
        }

        // Every API call carries the login token as the access_token cookie.
        if (($viettel['calls'][count($viettel['calls']) - 1]['cookie'] ?? null) !== 'access_token=token-1') {
            return Http::response(['errorCode' => 'UNAUTHORIZED', 'description' => 'Unauthorized'], 401);
        }

        if (str_contains($path, '/InvoiceWS/createInvoice/')) {
            if ($viettel['fail'] !== null) {
                return Http::response(['errorCode' => 'INVOICE_ERROR', 'description' => $viettel['fail'], 'result' => null]);
            }

            $uuid = $request->data()['generalInvoiceInfo']['transactionUuid'];
            $number = $request->data()['generalInvoiceInfo']['invoiceSeries'].str_pad((string) ++$viettel['number'], 7, '0', STR_PAD_LEFT);
            $viettel['issued'][$uuid] = $number;

            return Http::response(['errorCode' => null, 'description' => null, 'result' => ['supplierTaxCode' => '0100109106-997', 'invoiceNo' => $number, 'transactionID' => 'T'.$viettel['number'], 'reservationCode' => 'RSV'.$viettel['number']]]);
        }

        if (str_ends_with($path, '/searchInvoiceByTransactionUuid')) {
            $number = $viettel['issued'][$request->data()['transactionUuid']] ?? null;

            return Http::response(['errorCode' => null, 'result' => $number === null ? null : [['invoiceNo' => $number, 'reservationCode' => 'RSV-FOUND', 'issueDate' => now()->getTimestampMs()]]]);
        }

        if (str_ends_with($path, '/cancelTransactionInvoice')) {
            return Http::response(['errorCode' => null, 'description' => null, 'result' => null]);
        }

        if (str_ends_with($path, '/getInvoiceRepresentationFile')) {
            return Http::response(['errorCode' => null, 'fileName' => 'hd.pdf', 'fileToBytes' => base64_encode('%PDF-1.4 hoa don')]);
        }

        return Http::response(['errorCode' => 'NOT_FOUND'], 404);
    });
});

function viettel(array $attributes = []): EinvoiceProvider
{
    $provider = new EinvoiceProvider(['driver' => 'viettel', 'name' => 'Viettel S-Invoice', 'is_active' => true, 'is_sandbox' => false, 'settings' => [
        'seller_tax_code' => '0100109106-997',
        'template_code' => '1/001',
        'invoice_series' => 'C26TAA',
    ], ...$attributes]);
    $provider->credentials = ['username' => '0100109106-997', 'password' => 'dung-mat-khau'];
    $provider->connected_at = now();
    $provider->save();

    return $provider;
}

/**
 * An order picked up at the store: exported and completed at once.
 */
function soldOrder(array $lines, array $overrides = []): Order
{
    return app(OrderService::class)->create(orderData($lines, ['delivery_method' => 'pickup', ...$overrides]), auth()->user(), 'export');
}

function viettelCalls(array $state, string $suffix): array
{
    return array_values(array_filter($state['calls'], fn (array $call) => str_contains($call['path'], $suffix)));
}

test('a draft from an order takes the buyer from the customer and spreads the order discount over the lines', function () {
    $this->actingAs(owner());
    $mouse = stocked(10, 100000, ['name' => 'Chuột không dây'], ['retail_price' => 300000, 'unit' => 'Cái']);
    $keyboard = stocked(10, 200000, ['name' => 'Bàn phím cơ'], ['retail_price' => 500000]);
    $company = customer(['name' => 'Công ty TNHH ITSO', 'tax_code' => '0312345678', 'address' => '12 Lê Lợi', 'district' => 'Quận 1', 'province' => 'TP. Hồ Chí Minh', 'email' => 'ketoan@itso.vn']);

    // 2 × 300.000 + 1 × 500.000 = 1.100.000, order discount 110.000, shipping 30.000.
    $order = soldOrder([[$mouse, 2], [$keyboard, 1]], ['customer_id' => $company->id, 'discount' => 110000, 'shipping_fee' => 30000]);
    expect($order->total)->toBe(1020000);

    $invoice = app(EinvoiceService::class)->fromOrder($order->refresh());

    expect($invoice->status)->toBe(Einvoice::DRAFT)
        ->and($invoice->code)->toStartWith('EIN')
        ->and($invoice->buyer_company)->toBe('Công ty TNHH ITSO')
        ->and($invoice->buyer_tax_code)->toBe('0312345678')
        ->and($invoice->buyer_address)->toBe('12 Lê Lợi, Quận 1, TP. Hồ Chí Minh')
        ->and($invoice->buyer_email)->toBe('ketoan@itso.vn');

    $items = $invoice->items;
    expect($items)->toHaveCount(3)
        ->and($items[0]->discount)->toBe(60000)
        ->and($items[1]->discount)->toBe(50000)
        ->and($items[2]->name)->toBe('Phí giao hàng')
        ->and($items[2]->price)->toBe(30000);

    // Prices include 10% tax: before tax + tax = what the customer pays.
    expect($invoice->total)->toBe($order->total)
        ->and($invoice->amount_before_tax + $invoice->tax_amount)->toBe(1020000)
        ->and($items[0]->amount_before_tax)->toBe(490909)
        ->and($items[0]->tax_amount)->toBe(49091);

    // One invoice in force per order.
    expect(fn () => app(EinvoiceService::class)->fromOrder($order))->toThrow(ValidationException::class);
});

test('issuing sends the invoice to Viettel and locks the order', function () {
    $this->actingAs($user = owner());
    viettel();
    $item = stocked(10, 100000, [], ['retail_price' => 220000]);
    $order = soldOrder([[$item, 1]], ['customer_id' => customer(['tax_code' => '0312345678', 'name' => 'Công ty ITSO', 'address' => '1 Lý Thường Kiệt'])->id]);

    $this->post("/e-invoices/orders/{$order->id}")->assertRedirect();
    $invoice = Einvoice::query()->sole();

    $this->post("/e-invoices/{$invoice->id}/issue")->assertSessionHasNoErrors();
    $invoice->refresh();

    expect($invoice->status)->toBe(Einvoice::ISSUED)
        ->and($invoice->invoice_no)->toBe('C26TAA0000001')
        ->and($invoice->lookup_code)->toBe('RSV1')
        ->and($invoice->issued_by)->toBe($user->id);

    $create = viettelCalls($this->viettel, '/createInvoice/0100109106-997')[0]['data'];
    expect($create['generalInvoiceInfo']['templateCode'])->toBe('1/001')
        ->and($create['generalInvoiceInfo']['transactionUuid'])->toBe($invoice->transaction_uuid)
        ->and($create['buyerInfo']['buyerTaxCode'])->toBe('0312345678')
        ->and($create['summarizeInfo']['totalAmountWithTax'])->toBe(220000)
        ->and($create['summarizeInfo']['totalAmountWithTaxInWords'])->toBe('Hai trăm hai mươi nghìn đồng')
        ->and($create['itemInfo'][0]['itemTotalAmountWithoutTax'] + $create['itemInfo'][0]['taxAmount'])->toBe(220000);

    // The order can no longer be cancelled or edited; the order page says why.
    $this->post("/orders/{$order->id}/cancel", ['reason' => 'Khách đổi ý'])->assertSessionHasErrors('status');
    expect($order->refresh()->status)->toBe(Order::COMPLETED);
    $this->get("/orders/{$order->id}")->assertInertia(fn (Assert $page) => $page
        ->where('locked', fn ($reason) => str_contains($reason, 'C26TAA0000001'))
        ->where('panels', fn ($panels) => collect($panels)->contains(fn ($panel) => $panel['title'] === 'Hóa đơn điện tử')));

    // Issued: no more changes, a PDF can be downloaded.
    $this->put("/e-invoices/{$invoice->id}", ['payment_method' => 'Tiền mặt'])->assertSessionHasErrors('status');
    $this->get("/e-invoices/{$invoice->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

test('a failed issue is kept with the error and a retry never issues twice', function () {
    $this->actingAs(owner());
    viettel();
    $order = soldOrder([[stocked(5, 50000), 1]]);
    $invoice = app(EinvoiceService::class)->fromOrder($order);

    $this->viettel['fail'] = 'Ký hiệu hóa đơn không hợp lệ';
    $this->post("/e-invoices/{$invoice->id}/issue")->assertSessionHasErrors('provider');
    expect($invoice->refresh()->status)->toBe(Einvoice::FAILED)
        ->and($invoice->error)->toContain('Ký hiệu hóa đơn không hợp lệ');

    // Viettel issued it although the answer was lost: the retry finds it.
    $this->viettel['fail'] = null;
    $this->viettel['issued'][$invoice->transaction_uuid] = 'C26TAA0000042';
    $this->post("/e-invoices/{$invoice->id}/issue")->assertSessionHasNoErrors();

    expect($invoice->refresh()->status)->toBe(Einvoice::ISSUED)
        ->and($invoice->invoice_no)->toBe('C26TAA0000042')
        ->and(viettelCalls($this->viettel, '/createInvoice/'))->toHaveCount(1);
});

test('buyer data is checked before issuing', function () {
    $this->actingAs(owner());
    viettel();
    $invoice = app(EinvoiceService::class)->fromOrder(soldOrder([[stocked(5, 50000), 1]]));

    $this->put("/e-invoices/{$invoice->id}", ['buyer_tax_code' => '12345', 'payment_method' => 'TM/CK'])->assertSessionHasErrors('buyer_tax_code');

    $this->put("/e-invoices/{$invoice->id}", ['buyer_tax_code' => '0312345678', 'payment_method' => 'TM/CK'])->assertSessionHasNoErrors();
    $this->post("/e-invoices/{$invoice->id}/issue")->assertSessionHasErrors('buyer_company');
    expect(viettelCalls($this->viettel, '/createInvoice/'))->toBeEmpty();

    // Lines are recomputed by the server.
    $this->put("/e-invoices/{$invoice->id}", [
        'buyer_tax_code' => '0312345678', 'buyer_company' => 'Công ty ITSO', 'buyer_address' => '1 Lý Thường Kiệt', 'payment_method' => 'Chuyển khoản',
        'items' => [['name' => 'Dịch vụ cài đặt', 'quantity' => 2, 'price' => 110000, 'discount' => 0, 'tax_percent' => 8, 'amount' => 1]],
    ])->assertSessionHasNoErrors();
    expect($invoice->refresh()->total)->toBe(220000)
        ->and($invoice->tax_amount)->toBe(16296)
        ->and($invoice->items->sole()->tax_percent)->toBe(8);
});

test('cancelling and adjusting an issued invoice', function () {
    $this->actingAs(owner());
    viettel();
    $order = soldOrder([[stocked(5, 50000, [], ['retail_price' => 330000]), 1]]);
    $service = app(EinvoiceService::class);
    $invoice = $service->issue($service->fromOrder($order));

    // Adjustment: a draft copy, issued with a reference to the original.
    $this->post("/e-invoices/{$invoice->id}/adjust", ['adjustment_type' => 'decrease'])->assertRedirect();
    $adjustment = Einvoice::query()->where('kind', Einvoice::ADJUSTMENT)->sole();
    expect($adjustment->original_id)->toBe($invoice->id)
        ->and($adjustment->status)->toBe(Einvoice::DRAFT)
        ->and($adjustment->items)->toHaveCount(1);

    $this->put("/e-invoices/{$adjustment->id}", ['payment_method' => 'TM/CK', 'note' => 'Giảm giá sau bán', 'items' => [
        ['name' => 'Giảm giá', 'quantity' => 1, 'price' => 33000, 'tax_percent' => 10],
    ], 'issue' => true])->assertSessionHasNoErrors();

    $sent = viettelCalls($this->viettel, '/createInvoice/')[1]['data'];
    expect($sent['generalInvoiceInfo']['adjustmentType'])->toBe('5')
        ->and($sent['generalInvoiceInfo']['originalInvoiceId'])->toBe($invoice->invoice_no)
        ->and($sent['itemInfo'][0]['isIncreaseItem'])->toBeFalse()
        ->and($adjustment->refresh()->status)->toBe(Einvoice::ISSUED);

    // With an issued adjustment the original stays.
    $this->post("/e-invoices/{$invoice->id}/cancel", ['reason' => 'Sai thông tin'])->assertSessionHasErrors('status');

    // Cancelling the adjustment, then the original, unlocks the order.
    $this->post("/e-invoices/{$adjustment->id}/cancel", ['reason' => 'Lập nhầm'])->assertSessionHasNoErrors();
    $this->post("/e-invoices/{$invoice->id}/cancel", ['reason' => 'Sai thông tin'])->assertSessionHasNoErrors();

    expect($invoice->refresh()->status)->toBe(Einvoice::CANCELLED)
        ->and(viettelCalls($this->viettel, '/cancelTransactionInvoice'))->toHaveCount(2)
        ->and(app(OrderService::class)->lockedReason($order))->toBeNull();
});

test('completed orders get their invoice by themselves when auto issue is on', function () {
    $this->actingAs(owner());
    viettel();
    app(SettingsRepository::class)->setMany(['einvoice.auto_issue' => true]);

    $order = soldOrder([[stocked(5, 50000), 2]]);

    $invoice = Einvoice::query()->where('order_id', $order->id)->sole();
    expect($invoice->status)->toBe(Einvoice::ISSUED)
        ->and($invoice->buyer_name)->toBe(null)
        ->and(viettelCalls($this->viettel, '/createInvoice/')[0]['data']['buyerInfo'])->toMatchArray(['buyerNotGetInvoice' => 1]);

    // Turned off: nothing is issued.
    app(SettingsRepository::class)->setMany(['einvoice.auto_issue' => false]);
    $other = soldOrder([[stocked(5, 50000), 1]]);
    expect(Einvoice::query()->where('order_id', $other->id)->exists())->toBeFalse();
});

test('"Đơn chưa tạo HĐĐT" lists exported orders without an invoice and creates them in bulk', function () {
    $this->actingAs(owner());
    $item = stocked(10, 50000);
    $first = soldOrder([[$item, 1]]);
    $second = soldOrder([[$item, 1]]);
    app(OrderService::class)->create(orderData([[$item, 1]]), auth()->user()); // not exported

    $this->get('/e-invoices/pending')->assertInertia(fn (Assert $page) => $page
        ->component('e-invoices/pending')
        ->where('orders.rows.total', 2)
        ->where('connected', false));

    $this->post('/e-invoices/pending/bulk', ['action' => 'create', 'ids' => [$first->id, $second->id]])->assertSessionHasNoErrors();
    expect(Einvoice::query()->where('status', Einvoice::DRAFT)->count())->toBe(2);

    $this->get('/e-invoices/pending')->assertInertia(fn (Assert $page) => $page->where('orders.rows.total', 0));
    $this->get('/e-invoices')->assertInertia(fn (Assert $page) => $page->component('e-invoices/index')->where('invoices.rows.total', 2));
});

test('connecting Viettel checks the account and never shows the password again', function () {
    $this->actingAs(owner());

    $this->put('/e-invoices/providers/viettel', [
        'username' => '0100109106-997', 'password' => 'sai-mat-khau', 'is_active' => true,
        'settings' => ['seller_tax_code' => '0100109106-997', 'template_code' => '1/001', 'invoice_series' => 'C26TAA'],
    ])->assertSessionHasErrors('username');
    expect(EinvoiceProvider::query()->exists())->toBeFalse();

    $this->postJson('/e-invoices/providers/viettel/verify', ['username' => 'x', 'password' => 'dung-mat-khau'])->assertOk();

    $this->put('/e-invoices/providers/viettel', [
        'username' => '0100109106-997', 'password' => 'dung-mat-khau', 'is_active' => true,
        'settings' => ['seller_tax_code' => '0100109106-997', 'template_code' => '1/001', 'invoice_series' => 'C26TAA'],
    ])->assertSessionHasNoErrors();

    $provider = EinvoiceProvider::query()->sole();
    expect($provider->credential('password'))->toBe('dung-mat-khau')
        ->and($provider->getRawOriginal('credentials'))->not->toContain('dung-mat-khau');

    $this->get('/e-invoices/settings')->assertInertia(fn (Assert $page) => $page
        ->component('e-invoices/settings')
        ->where('providers.0.connected', true)
        ->where('providers.0.has_password', true)
        ->missing('providers.0.password'))
        ->assertDontSee('dung-mat-khau');

    // Saving again without a password keeps it.
    $this->put('/e-invoices/providers/viettel', [
        'username' => '0100109106-997', 'password' => '', 'is_active' => true,
        'settings' => ['seller_tax_code' => '0100109106-997', 'template_code' => '1/001', 'invoice_series' => 'C27TAA'],
    ])->assertSessionHasNoErrors();
    expect($provider->refresh()->credential('password'))->toBe('dung-mat-khau')
        ->and($provider->setting('invoice_series'))->toBe('C27TAA');
});

test('invoices are limited by permission and branch', function () {
    $this->actingAs(owner());
    $invoice = app(EinvoiceService::class)->fromOrder(soldOrder([[stocked(5, 50000), 1]]));

    $cashier = staffWithRole('Thu ngân');
    $this->actingAs($cashier)->get('/e-invoices')->assertForbidden();
    $this->actingAs($cashier)->get('/e-invoices/settings')->assertForbidden();

    $accountant = staffWithRole('Kế toán', Branch::query()->create(['code' => 'CN02', 'name' => 'Chi nhánh 2']));
    $this->actingAs($accountant)->get('/e-invoices')->assertOk();
    $this->actingAs($accountant)->get("/e-invoices/{$invoice->id}")->assertNotFound();
    $this->actingAs($accountant)->post("/e-invoices/{$invoice->id}/issue")->assertNotFound();

    $local = staffWithRole('Kế toán');
    expect($local->can('einvoices.approve'))->toBeTrue();
    $this->actingAs($local)->get("/e-invoices/{$invoice->id}")->assertOk();
    $this->actingAs($local)->put('/e-invoices/providers/viettel', ['username' => 'x'])->assertForbidden();
});

test('the pages render', function (string $url) {
    $this->actingAs(owner());
    viettel();
    $invoice = app(EinvoiceService::class)->fromOrder(soldOrder([[stocked(5, 50000), 1]]));

    $this->get(str_replace('{id}', (string) $invoice->id, $url))->assertOk();
})->with(['/e-invoices', '/e-invoices/pending', '/e-invoices/settings', '/e-invoices/{id}']);

test('the default roles get e-invoice permissions', function () {
    expect(User::query()->exists())->toBeFalse();
    $accountant = staffWithRole('Kế toán');

    foreach (['view', 'create', 'approve', 'cancel', 'export'] as $action) {
        expect($accountant->can("einvoices.{$action}"))->toBeTrue();
    }
});
