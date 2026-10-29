<?php

use App\Models\PaymentMethod;
use App\Models\Tenant;
use App\Support\Settings\StoreDefaults;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Support\SessionKey;
use Modules\Cashbook\Models\CashVoucher;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;
use Modules\Product\Models\ProductVariant;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\CodReconciliation;
use Modules\Shipping\Models\Shipment;
use Modules\Shipping\Services\CodReconciliationService;
use Modules\Shipping\Services\ShipmentService;

beforeEach(function () {
    StoreDefaults::install();
    $this->ghn = ['status' => 'ready_to_pick', 'log' => [], 'cancel' => true, 'create' => true, 'calls' => []];
    $ghn = &$this->ghn;

    Http::fake(function (HttpRequest $request) use (&$ghn) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $ghn['calls'][] = ['path' => $path, 'data' => $request->data(), 'headers' => $request->headers()];
        $ok = fn (mixed $data) => Http::response(['code' => 200, 'message' => 'Success', 'data' => $data]);

        return match (true) {
            str_ends_with($path, '/master-data/province') => $ok([['ProvinceID' => 202, 'ProvinceName' => 'Hồ Chí Minh', 'NameExtension' => ['TP.HCM', 'Thành phố Hồ Chí Minh']]]),
            str_ends_with($path, '/master-data/district') => $ok([['DistrictID' => 1444, 'DistrictName' => 'Quận 3', 'NameExtension' => ['Q3']]]),
            str_ends_with($path, '/master-data/ward') => $ok([['WardCode' => '20308', 'WardName' => 'Phường Võ Thị Sáu', 'NameExtension' => []]]),
            str_ends_with($path, '/v2/shop/all') => $ok(['shops' => [['_id' => 885, 'name' => 'ITSO', 'address' => '1 Lê Lợi']]]),
            str_ends_with($path, '/shipping-order/fee') => $ok(['total' => ($request->data()['service_type_id'] ?? 2) === 5 ? 45000 : 32000]),
            str_ends_with($path, '/shipping-order/create') => $ghn['create']
                ? $ok(['order_code' => 'GHN'.count($ghn['calls']), 'total_fee' => 32000, 'expected_delivery_time' => '2026-11-10T16:59:59Z'])
                : Http::response(['code' => 400, 'message' => 'Số điện thoại người nhận không hợp lệ', 'data' => null], 400),
            str_ends_with($path, '/shipping-order/detail') => $ok(['status' => $ghn['status'], 'cod_amount' => 500000, 'log' => $ghn['log']]),
            str_ends_with($path, '/switch-status/cancel') => $ok([['order_code' => 'x', 'result' => $ghn['cancel'], 'message' => $ghn['cancel'] ? 'OK' : 'Đơn hàng đã lấy']]),
            default => Http::response(['code' => 404, 'message' => 'not found'], 404),
        };
    });
});

function ghnCarrier(array $attributes = []): Carrier
{
    $carrier = new Carrier(['kind' => Carrier::INTEGRATED, 'driver' => 'ghn', 'code' => 'GHN', 'name' => 'Giao Hàng Nhanh', 'is_active' => true, 'settings' => ['shop_id' => '885'], ...$attributes]);
    $carrier->credentials = ['token' => 'secret-token-1234'];
    $carrier->webhook_token = str_repeat('a', 48);
    $carrier->save();

    return $carrier;
}

function shipmentInput(Carrier $carrier, array $overrides = []): array
{
    return [
        'carrier_id' => $carrier->id,
        'cod_amount' => 500000,
        'weight' => 800,
        'length' => 20,
        'width' => 15,
        'height' => 10,
        'receiver_name' => 'Nguyễn Văn An',
        'receiver_phone' => '0909123456',
        'address' => '12 Lê Quý Đôn',
        'ward' => 'Phường Võ Thị Sáu',
        'district' => 'Quận 3',
        'province' => 'TP Hồ Chí Minh',
        ...$overrides,
    ];
}

function approvedOrder(ProductVariant $item, int $quantity = 2, array $overrides = []): Order
{
    $orders = app(OrderService::class);
    $order = $orders->create(orderData([[$item, $quantity]], ['customer_id' => customer()->id, ...$overrides]), auth()->user());
    $orders->approve($order);

    return $order->refresh();
}

function ghnCalls(array $state, string $suffix): array
{
    return array_values(array_filter($state['calls'], fn (array $call): bool => str_ends_with($call['path'], $suffix)));
}

test('a GHN shipment is created from an order: the order is packed and shows the tracking code', function () {
    $this->actingAs($owner = owner());
    $order = approvedOrder(stocked(10, 100000, [], ['retail_price' => 250000, 'weight' => 400]));
    $carrier = ghnCarrier();
    $service = app(ShipmentService::class);

    $shipment = $service->create($order, $service->validate(shipmentInput($carrier, ['service_id' => 'type:2'])), $owner);

    $create = ghnCalls($this->ghn, '/shipping-order/create')[0];
    expect($shipment->code)->toBe('FUN00001')
        ->and($shipment->tracking_code)->toStartWith('GHN')
        ->and($shipment->status)->toBe(Shipment::PENDING)
        ->and($shipment->fee)->toBe(32000)
        ->and($create['headers']['Token'][0])->toBe('secret-token-1234')
        ->and($create['headers']['ShopId'][0])->toBe('885')
        ->and($create['data']['client_order_code'])->toBe('FUN00001')
        ->and($create['data']['to_district_id'])->toBe(1444)
        ->and($create['data']['to_ward_code'])->toBe('20308')
        ->and($create['data']['cod_amount'])->toBe(500000)
        ->and($create['data']['items'][0]['quantity'])->toBe(2)
        ->and($order->status)->toBe(Order::PACKED)
        ->and($order->delivery_method)->toBe('carrier')
        ->and($order->tracking_code)->toBe($shipment->tracking_code);
});

test('when GHN refuses the request nothing is kept and the order stays approved', function () {
    $this->actingAs($owner = owner());
    $order = approvedOrder(stocked(10, 100000));
    $this->ghn['create'] = false;
    $service = app(ShipmentService::class);

    expect(fn () => $service->create($order, $service->validate(shipmentInput(ghnCarrier())), $owner))
        ->toThrow(ValidationException::class, 'Số điện thoại người nhận không hợp lệ');

    expect(Shipment::query()->count())->toBe(0)
        ->and($order->refresh()->status)->toBe(Order::APPROVED);
});

test('fees of the GHN services are quoted for the order form', function () {
    $this->actingAs(owner());
    ghnCarrier();

    $this->postJson('/shipping/quotes', [...shipmentInput(new Carrier), 'branch_id' => mainBranch()->id])
        ->assertOk()
        ->assertJsonPath('quotes.0.fee', 32000)
        ->assertJsonPath('quotes.0.service_id', 'type:2')
        ->assertJsonPath('quotes.1.fee', 45000);
});

test('an unknown address is reported, not guessed', function () {
    $this->actingAs(owner());
    ghnCarrier();

    $this->postJson('/shipping/quotes', [...shipmentInput(new Carrier, ['district' => 'Quận 99']), 'branch_id' => mainBranch()->id])
        ->assertOk()
        ->assertJsonPath('quotes.0.error', 'GHN không có quận/huyện "Quận 99" ở TP Hồ Chí Minh.');
});

test('the webhook only names the parcel: its status is fetched from GHN, then the order follows', function () {
    $this->actingAs($owner = owner());
    $item = stocked(10, 100000);
    $order = approvedOrder($item, 2);
    $carrier = ghnCarrier();
    $service = app(ShipmentService::class);
    $shipment = $service->create($order, $service->validate(shipmentInput($carrier)), $owner);
    auth()->logout();

    // A wrong token is refused.
    $this->postJson('/webhooks/shipping/'.str_repeat('b', 48), ['OrderCode' => $shipment->tracking_code, 'Status' => 'delivered'])->assertNotFound();

    // A forged "delivered" while GHN says the parcel is still being picked up changes nothing.
    $this->ghn['status'] = 'picking';
    $this->postJson('/webhooks/shipping/'.$carrier->webhook_token, ['OrderCode' => $shipment->tracking_code, 'Status' => 'delivered', 'ShopID' => 885])->assertOk();
    expect($shipment->refresh()->status)->toBe(Shipment::PENDING)
        ->and($shipment->carrier_status)->toBe('picking')
        ->and($order->refresh()->status)->toBe(Order::PACKED);

    // Picked up: the goods leave the stock.
    $this->ghn['status'] = 'picked';
    $this->postJson('/webhooks/shipping/'.$carrier->webhook_token, ['OrderCode' => $shipment->tracking_code, 'ShopID' => 885])->assertOk();
    expect($shipment->refresh()->status)->toBe(Shipment::SHIPPING)
        ->and($order->refresh()->status)->toBe(Order::SHIPPED)
        ->and(stockRow($item)->on_hand)->toBe(8)
        ->and(stockRow($item)->reserved)->toBe(0);

    // Delivered: the order is completed, the COD waits for reconciliation.
    $this->ghn['status'] = 'delivered';
    $this->postJson('/webhooks/shipping/'.$carrier->webhook_token, ['OrderCode' => $shipment->tracking_code, 'ShopID' => 885])->assertOk();
    expect($shipment->refresh()->status)->toBe(Shipment::DELIVERED)
        ->and($shipment->delivered_at)->not->toBeNull()
        ->and($shipment->codStatus())->toBe('waiting')
        ->and($order->refresh()->status)->toBe(Order::COMPLETED)
        ->and($order->delivery_status)->toBe('delivered')
        ->and($order->paid)->toBe(0);

    // A late call cannot move a finished parcel back.
    $this->ghn['status'] = 'delivering';
    $this->postJson('/webhooks/shipping/'.$carrier->webhook_token, ['OrderCode' => $shipment->tracking_code, 'ShopID' => 885])->assertOk();
    expect($shipment->refresh()->status)->toBe(Shipment::DELIVERED);
});

test('a webhook for another GHN shop or another store is ignored', function () {
    $this->actingAs($owner = owner());
    $order = approvedOrder(stocked(10, 100000));
    $carrier = ghnCarrier();
    $service = app(ShipmentService::class);
    $shipment = $service->create($order, $service->validate(shipmentInput($carrier)), $owner);
    $this->ghn['status'] = 'delivered';

    $this->postJson('/webhooks/shipping/'.$carrier->webhook_token, ['OrderCode' => $shipment->tracking_code, 'ShopID' => 999])->assertOk();
    expect($shipment->refresh()->status)->toBe(Shipment::PENDING);

    // The token of a disconnected partner no longer works.
    $carrier->forceFill(['is_active' => false])->save();
    $this->postJson('/webhooks/shipping/'.$carrier->webhook_token, ['OrderCode' => $shipment->tracking_code, 'ShopID' => 885])->assertNotFound();

    // A store that is not active does not take calls.
    $carrier->forceFill(['is_active' => true])->save();
    Tenant::query()->whereKey($carrier->tenant_id)->update(['status' => 'suspended']);
    $this->postJson('/webhooks/shipping/'.$carrier->webhook_token, ['OrderCode' => $shipment->tracking_code, 'ShopID' => 885])->assertNotFound();
});

test('a failed delivery waits for redelivery, then comes back to stock', function () {
    $this->actingAs($owner = owner());
    settings()->set('shipping.auto_receive_return', true);
    $item = stocked(10, 100000);
    $order = approvedOrder($item, 3);
    $service = app(ShipmentService::class);
    $shipment = $service->create($order, $service->validate(shipmentInput(ghnCarrier())), $owner);

    $this->ghn['status'] = 'delivery_fail';
    $service->sync($shipment);
    expect($shipment->refresh()->status)->toBe(Shipment::REDELIVERY)
        ->and($order->refresh()->status)->toBe(Order::SHIPPED)
        ->and($order->delivery_status)->toBe('shipping');

    $this->ghn['status'] = 'return';
    $service->sync($shipment);
    expect($shipment->refresh()->status)->toBe(Shipment::RETURNING)
        ->and($order->refresh()->delivery_status)->toBe('returning');

    $this->ghn['status'] = 'returned';
    $service->sync($shipment);
    expect($shipment->refresh()->status)->toBe(Shipment::RETURNED)
        ->and($order->refresh()->status)->toBe(Order::PACKED)
        ->and(stockRow($item)->on_hand)->toBe(10)
        ->and(stockRow($item)->reserved)->toBe(3);
});

test('the status history keeps the steps GHN reports, once', function () {
    $this->actingAs($owner = owner());
    $order = approvedOrder(stocked(10, 100000));
    $service = app(ShipmentService::class);
    $shipment = $service->create($order, $service->validate(shipmentInput(ghnCarrier())), $owner);

    $this->ghn['status'] = 'delivering';
    $this->ghn['log'] = [
        ['status' => 'picking', 'updated_date' => '2026-11-01T02:00:00Z'],
        ['status' => 'picked', 'updated_date' => '2026-11-01T05:00:00Z'],
        ['status' => 'delivering', 'updated_date' => '2026-11-02T01:00:00Z'],
    ];
    $service->sync($shipment);
    $service->sync($shipment);

    expect($shipment->events()->whereNotNull('carrier_status')->pluck('carrier_status')->all())
        ->toBe(['delivering', 'picked', 'picking', 'ready_to_pick'])
        ->and($shipment->refresh()->status)->toBe(Shipment::SHIPPING);
});

test('cancelling a shipment asks GHN first and puts the order back to approved', function () {
    $this->actingAs($owner = owner());
    $order = approvedOrder(stocked(10, 100000));
    $service = app(ShipmentService::class);
    $carrier = ghnCarrier();
    $shipment = $service->create($order, $service->validate(shipmentInput($carrier)), $owner);

    $this->ghn['cancel'] = false;
    expect(fn () => $service->cancel($shipment, $owner))->toThrow(ValidationException::class, 'Đơn hàng đã lấy');
    expect($shipment->refresh()->status)->toBe(Shipment::PENDING);

    $this->ghn['cancel'] = true;
    $this->post("/shipments/{$shipment->id}/cancel", ['reason' => 'Khách đổi ý'])->assertRedirect();

    expect($shipment->refresh()->status)->toBe(Shipment::CANCELLED)
        ->and(ghnCalls($this->ghn, '/switch-status/cancel'))->toHaveCount(2)
        ->and($order->refresh()->status)->toBe(Order::APPROVED)
        ->and($order->tracking_code)->toBeNull();

    // A new shipment can be made.
    $service->create($order, $service->validate(shipmentInput($carrier)), $owner);
    expect(Shipment::query()->where('order_id', $order->id)->count())->toBe(2);
});

test('cancelling the order cancels its shipment at GHN', function () {
    $this->actingAs($owner = owner());
    $order = approvedOrder(stocked(10, 100000));
    $service = app(ShipmentService::class);
    $shipment = $service->create($order, $service->validate(shipmentInput(ghnCarrier())), $owner);

    app(OrderService::class)->cancel($order);

    expect($shipment->refresh()->status)->toBe(Shipment::CANCELLED)
        ->and(ghnCalls($this->ghn, '/switch-status/cancel'))->toHaveCount(1)
        ->and($order->refresh()->status)->toBe(Order::CANCELLED);
});

test('a partner the store contacts itself is followed by hand and by the order steps', function () {
    $this->actingAs($owner = owner());
    $item = stocked(10, 100000);
    $order = approvedOrder($item, 1);
    $partner = Carrier::query()->create(['kind' => Carrier::MANUAL, 'code' => 'DTVC00001', 'name' => 'Viettel Post', 'is_active' => true]);
    $service = app(ShipmentService::class);

    $shipment = $service->create($order, $service->validate(shipmentInput($partner, ['fee' => 25000, 'tracking_code' => 'VTP123'])), $owner);
    expect($shipment->fee)->toBe(25000)
        ->and($order->refresh()->delivery_method)->toBe('external')
        ->and($order->carrier_name)->toBe('Viettel Post')
        ->and(Http::recorded())->toHaveCount(0);

    // Xuất kho on the order page: the parcel is with the partner.
    app(OrderService::class)->export($order);
    expect($shipment->refresh()->status)->toBe(Shipment::SHIPPING);

    expect(fn () => $service->setStatus($shipment, Shipment::RETURNED, $owner))->toThrow(ValidationException::class);

    $this->post("/shipments/{$shipment->id}/status", ['status' => Shipment::DELIVERED])->assertRedirect();
    expect($shipment->refresh()->status)->toBe(Shipment::DELIVERED)
        ->and($order->refresh()->status)->toBe(Order::COMPLETED);
});

test('the order form approves the order and hands it to the chosen partner', function () {
    $this->actingAs(owner());
    $item = stocked(10, 100000);
    $carrier = ghnCarrier();

    $this->post('/orders', [
        'branch_id' => mainBranch()->id,
        'customer_id' => customer()->id,
        'delivery_method' => 'carrier',
        'items' => [['variant_id' => $item->id, 'quantity' => 1]],
        'shipment' => shipmentInput($carrier, ['service_id' => 'type:2', 'cod_amount' => 200000]),
    ])->assertRedirect();

    $order = Order::query()->firstOrFail();
    expect($order->status)->toBe(Order::PACKED)
        ->and(Shipment::query()->where('order_id', $order->id)->value('cod_amount'))->toBe(200000);

    // The partner refused: the order is kept, staff are told why.
    $this->ghn['create'] = false;
    $this->post('/orders', [
        'branch_id' => mainBranch()->id,
        'delivery_method' => 'carrier',
        'items' => [['variant_id' => $item->id, 'quantity' => 1]],
        'shipment' => shipmentInput($carrier),
    ])->assertRedirect()
        ->assertSessionHas(SessionKey::FLASH_DATA.'.toast.type', 'warning');

    expect(Order::query()->latest('id')->first()->status)->toBe(Order::APPROVED);
});

test('COD reconciliation pays the orders and comes out to COD minus fees', function () {
    $this->actingAs($owner = owner());
    $service = app(ShipmentService::class);
    $partner = Carrier::query()->create(['kind' => Carrier::MANUAL, 'code' => 'DTVC00001', 'name' => 'Viettel Post', 'is_active' => true]);
    $item = stocked(20, 100000, [], ['retail_price' => 250000]);
    $shipments = [];

    // Two delivered parcels (COD 500.000 / fee 30.000 and COD 250.000 / fee 25.000) and one returned (fee 20.000).
    foreach ([[2, 500000, 30000, Shipment::DELIVERED], [1, 250000, 25000, Shipment::DELIVERED], [1, 250000, 20000, Shipment::RETURNING]] as [$quantity, $cod, $fee, $status]) {
        $order = approvedOrder($item, $quantity);
        $shipment = $service->create($order, $service->validate(shipmentInput($partner, ['cod_amount' => $cod, 'fee' => $fee])), $owner);
        $service->setStatus($shipment, Shipment::SHIPPING, $owner);
        $service->setStatus($shipment, $status, $owner);

        if ($status === Shipment::RETURNING) {
            $service->setStatus($shipment, Shipment::RETURNED, $owner);
        }

        $shipments[] = $shipment->refresh();
    }

    // A parcel still on its way cannot be reconciled.
    $open = $service->create(approvedOrder($item, 1), $service->validate(shipmentInput($partner)), $owner);
    expect(fn () => app(CodReconciliationService::class)->create([
        'carrier_id' => $partner->id, 'branch_id' => mainBranch()->id, 'items' => [['shipment_id' => $open->id]],
    ], $owner))->toThrow(ValidationException::class, 'chưa giao xong');

    $this->post('/cod-reconciliations', [
        'carrier_id' => $partner->id,
        'branch_id' => mainBranch()->id,
        'items' => array_map(fn (Shipment $shipment): array => ['shipment_id' => $shipment->id], $shipments),
    ])->assertRedirect();

    $reconciliation = CodReconciliation::query()->firstOrFail();
    expect($reconciliation->code)->toBe('DSCOD00001')
        ->and($reconciliation->status)->toBe(CodReconciliation::RECONCILING)
        ->and($reconciliation->cod_total)->toBe(750000)
        ->and($reconciliation->fee_total)->toBe(75000)
        ->and($reconciliation->amount)->toBe(675000)
        ->and($shipments[0]->refresh()->codStatus())->toBe('reconciling');

    // The partner charged 2.000 more on the first parcel.
    $first = $reconciliation->items()->where('shipment_id', $shipments[0]->id)->firstOrFail();
    $this->put("/cod-reconciliations/{$reconciliation->id}/items/{$first->id}", ['partner_cod' => 500000, 'partner_fee' => 32000])->assertRedirect();
    expect($reconciliation->refresh()->amount)->toBe(673000)
        ->and($reconciliation->systemAmount())->toBe(675000);

    // Shipments of a reconciliation cannot go in another one.
    expect(fn () => app(CodReconciliationService::class)->create([
        'carrier_id' => $partner->id, 'branch_id' => mainBranch()->id, 'items' => [['shipment_id' => $shipments[1]->id]],
    ], $owner))->toThrow(ValidationException::class, 'phiếu đối soát khác');

    $bank = PaymentMethod::query()->where('type', 'bank')->firstOrFail();
    $this->post("/cod-reconciliations/{$reconciliation->id}/complete", ['payment_method_id' => $bank->id])->assertRedirect();

    $reconciliation->refresh();
    $orders = Order::query()->whereIn('id', array_map(fn (Shipment $shipment): int => $shipment->order_id, $shipments))->orderBy('id')->get();
    $receipts = CashVoucher::query()->where('kind', CashVoucher::RECEIPT)->where('status', CashVoucher::COMPLETED)->sum('amount');
    $fees = CashVoucher::query()->where('kind', CashVoucher::PAYMENT)->where('document_code', $reconciliation->code)->firstOrFail();

    expect($reconciliation->status)->toBe(CodReconciliation::DONE)
        ->and($orders[0]->paid)->toBe(500000)
        ->and($orders[0]->paymentStatus())->toBe('paid')
        ->and($orders[1]->paid)->toBe(250000)
        ->and($orders[2]->paid)->toBe(0)
        ->and((int) $receipts)->toBe(750000)
        ->and($fees->amount)->toBe(77000)
        ->and($fees->affects_profit)->toBeTrue()
        ->and((int) $receipts - $fees->amount)->toBe($reconciliation->amount)
        ->and($shipments[0]->refresh()->codStatus())->toBe('reconciled');

    // Completing twice does nothing more.
    expect(fn () => app(CodReconciliationService::class)->complete($reconciliation, $bank->id, $owner))->toThrow(ValidationException::class);
});

test('cancelling a reconciliation frees its shipments', function () {
    $this->actingAs($owner = owner());
    $service = app(ShipmentService::class);
    $partner = Carrier::query()->create(['kind' => Carrier::MANUAL, 'code' => 'DTVC00001', 'name' => 'Grab', 'is_active' => true]);
    $shipment = $service->create(approvedOrder(stocked(5, 1000)), $service->validate(shipmentInput($partner, ['fee' => 15000])), $owner);
    $service->setStatus($shipment, Shipment::SHIPPING, $owner);
    $service->setStatus($shipment, Shipment::DELIVERED, $owner);
    $reconciliation = app(CodReconciliationService::class)->create(['carrier_id' => $partner->id, 'branch_id' => mainBranch()->id, 'items' => [['shipment_id' => $shipment->id]]], $owner);

    $this->post("/cod-reconciliations/{$reconciliation->id}/cancel")->assertRedirect();

    expect($reconciliation->refresh()->status)->toBe(CodReconciliation::CANCELLED)
        ->and($shipment->refresh()->cod_reconciliation_id)->toBeNull()
        ->and($shipment->codStatus())->toBe('waiting');
});

test('connecting GHN checks the token, keeps it encrypted and never sends it to the page', function () {
    $this->actingAs(owner());

    $this->put('/shipping/partners/ghn', ['token' => 'my-real-token-9876', 'shop_id' => '885', 'is_sandbox' => true, 'is_active' => true])->assertRedirect()->assertSessionHasNoErrors();

    $carrier = Carrier::query()->where('driver', 'ghn')->firstOrFail();
    $raw = DB::table('carriers')->where('id', $carrier->id)->value('credentials');

    expect($carrier->credential('token'))->toBe('my-real-token-9876')
        ->and($raw)->not->toContain('my-real-token')
        ->and($carrier->webhook_token)->toHaveLength(48);

    $this->get('/shipping/partners')
        ->assertOk()
        ->assertDontSee('my-real-token-9876')
        ->assertInertia(fn ($page) => $page->where('integrated.0.token', '••••9876')->where('integrated.0.connected', true));

    // Saving again without a token keeps the saved one.
    $this->put('/shipping/partners/ghn', ['token' => '', 'shop_id' => '886', 'is_active' => true])->assertSessionHasNoErrors();
    expect($carrier->refresh()->credential('token'))->toBe('my-real-token-9876')
        ->and($carrier->setting('shop_id'))->toBe('886');
});

test('staff without the permissions cannot connect partners or reconcile', function () {
    $cashier = staffWithRole('Thu ngân');
    $this->actingAs($cashier);

    $this->put('/shipping/partners/ghn', ['token' => 'x', 'shop_id' => '1'])->assertForbidden();
    $this->get('/cod-reconciliations')->assertForbidden();
    $this->get('/shipments')->assertOk();
    $this->get('/shipping/partners')->assertOk();
});

test('the sync command follows open GHN shipments of every store', function () {
    $this->actingAs($owner = owner());
    $order = approvedOrder(stocked(10, 100000));
    $service = app(ShipmentService::class);
    $shipment = $service->create($order, $service->validate(shipmentInput(ghnCarrier())), $owner);

    $this->ghn['status'] = 'delivering';
    $this->artisan('shipping:sync')->assertSuccessful();

    expect($shipment->refresh()->status)->toBe(Shipment::SHIPPING)
        ->and($shipment->synced_at)->not->toBeNull()
        ->and($order->refresh()->status)->toBe(Order::SHIPPED);
});

test('shipping pages render', function () {
    $this->actingAs($owner = owner());
    $order = approvedOrder(stocked(10, 100000));
    $service = app(ShipmentService::class);
    $shipment = $service->create($order, $service->validate(shipmentInput(ghnCarrier())), $owner);

    $this->get('/shipping')->assertOk()->assertInertia(fn ($page) => $page->component('shipping/overview')->where('states.0.count', 1));
    $this->get('/shipments')->assertOk()->assertInertia(fn ($page) => $page->component('shipments/index')->where('shipments.rows.data.0.code', 'FUN00001'));
    $this->get("/shipments/{$shipment->id}")->assertOk()->assertInertia(fn ($page) => $page->component('shipments/show'));
    $this->get("/shipments/{$shipment->id}/print")->assertOk();
    $this->get('/shipping/settings')->assertOk();
    $this->get('/cod-reconciliations')->assertOk();
    $this->get('/cod-reconciliations/create')->assertOk();
    $this->get("/orders/{$order->id}")->assertOk()->assertInertia(fn ($page) => $page->where('shipping.shipments.0.code', 'FUN00001'));
    $this->get('/orders/create')->assertOk()->assertInertia(fn ($page) => $page->where('shipping.enabled', true)->where('shipping.carriers.0.name', 'Giao Hàng Nhanh'));
});
