<?php

use App\Models\OrderReason;
use App\Support\Settings\StoreDefaults;
use Illuminate\Validation\ValidationException;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerGroup;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\StockLedger;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderPayment;
use Modules\Order\Services\OrderService;
use Modules\Order\Services\ReturnService;

beforeEach(function () {
    StoreDefaults::install();
});

test('an order goes through the 5 steps with stock, payment and debt in step', function () {
    $owner = owner();
    $this->actingAs($owner);
    $mouse = stocked(10, 80000, ['name' => 'Chuột'], ['retail_price' => 150000]);
    $keyboard = stocked(5, 200000, ['name' => 'Bàn phím'], ['retail_price' => 300000]);
    $an = customer();
    $orders = app(OrderService::class);

    $order = $orders->create(orderData([[$mouse, 2], [$keyboard, 1]], ['customer_id' => $an->id, 'shipping_fee' => 30000, 'discount' => 20000]), $owner);

    expect($order->code)->toBe('SON00001')
        ->and($order->status)->toBe(Order::PENDING)
        ->and($order->subtotal)->toBe(600000)
        ->and($order->total)->toBe(610000)
        ->and(stockRow($mouse)->reserved)->toBe(0);

    $orders->approve($order);
    expect($order->status)->toBe(Order::APPROVED)
        ->and(stockRow($mouse)->reserved)->toBe(2)
        ->and(app(InventoryService::class)->stockOf($mouse, [mainBranch()->id])['available'])->toBe(8);

    $orders->pack($order);
    expect($order->status)->toBe(Order::PACKED);

    $orders->export($order);
    $order->refresh();
    expect($order->status)->toBe(Order::SHIPPED)
        ->and($order->delivery_status)->toBe('shipping')
        ->and(stockRow($mouse)->on_hand)->toBe(8)
        ->and(stockRow($mouse)->reserved)->toBe(0)
        ->and(stockRow($keyboard)->on_hand)->toBe(4)
        ->and($order->cost_total)->toBe(2 * 80000 + 200000)
        ->and($an->refresh()->debt)->toBe(610000);

    $orders->pay($order, cash()->id, 610000);
    $orders->markDelivered($order);
    $order->refresh();
    $an->refresh();

    expect($order->status)->toBe(Order::COMPLETED)
        ->and($order->paymentStatus())->toBe('paid')
        ->and($an->debt)->toBe(0)
        ->and($an->total_spent)->toBe(610000)
        ->and($an->orders_count)->toBe(1)
        ->and($order->histories()->pluck('action')->all())->toBe(['created', 'approved', 'packed', 'shipped', 'paid', 'delivered', 'completed'])
        ->and(app(StockLedger::class)->mismatches())->toBe([]);
});

test('cancelling at the packing step releases the stock and refunds the payment', function () {
    $owner = owner();
    $this->actingAs($owner);
    $mouse = stocked(10, 80000, [], ['retail_price' => 150000]);
    $an = customer();
    $orders = app(OrderService::class);

    $order = $orders->create(orderData([[$mouse, 3]], ['customer_id' => $an->id, 'payments' => [['payment_method_id' => cash()->id, 'amount' => 200000]]]), $owner, 'approve');
    $orders->pack($order);
    expect(stockRow($mouse)->reserved)->toBe(3)
        ->and($an->refresh()->debt)->toBe(-200000);

    $reason = OrderReason::query()->where('type', 'cancel')->firstOrFail();
    $orders->cancel($order, $reason->id, 'Khách gọi hủy');
    $order->refresh();

    expect($order->status)->toBe(Order::CANCELLED)
        ->and($order->cancel_reason_id)->toBe($reason->id)
        ->and($order->paid)->toBe(0)
        ->and(OrderPayment::query()->where('order_id', $order->id)->sum('amount'))->toBe(0)
        ->and(OrderPayment::query()->where('order_id', $order->id)->where('kind', 'refund')->value('amount'))->toBe(-200000)
        ->and(stockRow($mouse)->on_hand)->toBe(10)
        ->and(stockRow($mouse)->reserved)->toBe(0)
        ->and($an->refresh()->debt)->toBe(0)
        ->and($an->orders_count)->toBe(0)
        ->and(StockMovement::query()->where('reference_type', $order->getMorphClass())->count())->toBe(0);

    expect(fn () => $orders->approve($order))->toThrow(ValidationException::class);
});

test('a return after completion puts the goods back and refunds the customer', function () {
    $owner = owner();
    $this->actingAs($owner);
    $mouse = stocked(10, 80000, [], ['retail_price' => 150000]);
    $keyboard = stocked(5, 200000, [], ['retail_price' => 300000]);
    $an = customer();
    $orders = app(OrderService::class);

    // 2 × 150.000 + 1 × 300.000 = 600.000, order discount 60.000 (10%), customer picks up and pays.
    $order = $orders->create(orderData([[$mouse, 2], [$keyboard, 1]], [
        'customer_id' => $an->id,
        'delivery_method' => 'pickup',
        'discount_bp' => 1000,
        'payments' => [['payment_method_id' => cash()->id, 'amount' => 540000]],
    ]), $owner, 'export');

    expect($order->status)->toBe(Order::COMPLETED)
        ->and($order->total)->toBe(540000)
        ->and(stockRow($mouse)->on_hand)->toBe(8);

    // Return 1 mouse: worth 150.000 less its 10% share of the discount = 135.000.
    $mouseLine = $order->items->firstWhere('variant_id', $mouse->id);
    $service = app(ReturnService::class);
    $return = $service->create($order, [$mouseLine->id => 1], null, 'Lỗi chuột', [
        'payment_method_id' => cash()->id,
        'amount' => $service->suggestedRefund($order->refresh(), 135000),
    ]);
    $order->refresh();
    $an->refresh();

    expect($return->code)->toBe('SRN00001')
        ->and($return->total)->toBe(135000)
        ->and($return->refunded)->toBe(135000)
        ->and($order->returned_total)->toBe(135000)
        ->and($order->paid)->toBe(405000)
        ->and($order->paymentStatus())->toBe('paid')
        ->and(stockRow($mouse)->on_hand)->toBe(9)
        ->and($mouse->refresh()->cost_price)->toBe(80000)
        ->and($an->debt)->toBe(0)
        ->and($an->total_spent)->toBe(405000)
        ->and($an->returned_quantity)->toBe(1)
        ->and((int) OrderPayment::query()->where('order_id', $order->id)->sum('amount'))->toBe(405000)
        ->and(app(StockLedger::class)->mismatches())->toBe([]);

    // Cannot return more than what is left.
    expect(fn () => $service->create($order, [$mouseLine->id => 2]))->toThrow(ValidationException::class);
});

test('cancelling an exported order brings the goods back', function () {
    $owner = owner();
    $this->actingAs($owner);
    $mouse = stocked(5, 80000, [], ['retail_price' => 150000]);
    $an = customer();
    $orders = app(OrderService::class);

    $order = $orders->create(orderData([[$mouse, 2]], ['customer_id' => $an->id]), $owner, 'approve');
    $orders->export($order);
    expect(stockRow($mouse)->on_hand)->toBe(3)->and($an->refresh()->debt)->toBe(300000);

    $orders->cancel($order);

    expect($order->refresh()->status)->toBe(Order::CANCELLED)
        ->and(stockRow($mouse)->on_hand)->toBe(5)
        ->and($an->refresh()->debt)->toBe(0)
        ->and(app(StockLedger::class)->mismatches())->toBe([]);
});

test('a failed delivery comes back into stock and can be shipped again', function () {
    $owner = owner();
    $this->actingAs($owner);
    $mouse = stocked(5, 80000);
    $orders = app(OrderService::class);

    $order = $orders->create(orderData([[$mouse, 1]], ['delivery_method' => 'external', 'carrier_name' => 'Ahamove']), $owner, 'approve');
    $orders->export($order);
    $orders->markDeliveryFailed($order, 'Khách không nghe máy');
    expect($order->refresh()->delivery_status)->toBe('returning');

    $orders->receiveBack($order);
    expect($order->refresh()->status)->toBe(Order::PACKED)
        ->and(stockRow($mouse)->on_hand)->toBe(5)
        ->and(stockRow($mouse)->reserved)->toBe(1);

    $orders->export($order);
    expect(stockRow($mouse)->on_hand)->toBe(4)->and(stockRow($mouse)->reserved)->toBe(0);
});

test('orders are refused when the branch lacks stock, unless the store allows it', function () {
    $owner = owner();
    $this->actingAs($owner);
    $mouse = stocked(2, 80000);
    $orders = app(OrderService::class);

    $orders->create(orderData([[$mouse, 2]]), $owner, 'approve');

    expect(fn () => $orders->create(orderData([[$mouse, 1]]), $owner))
        ->toThrow(ValidationException::class);

    settings()->set('sales.allow_negative_order', true);
    $pending = $orders->create(orderData([[$mouse, 1]]), $owner);

    expect($pending->status)->toBe(Order::PENDING)
        ->and(fn () => $orders->approve($pending))->toThrow(ValidationException::class);
});

test('a customer in the "Đại lý" group gets the wholesale price, and the server prices the lines', function () {
    $owner = owner();
    $this->actingAs($owner);
    CustomerGroup::installDefaults();
    $mouse = stocked(10, 80000, [], ['retail_price' => 150000, 'wholesale_price' => 120000]);
    $agent = customer(['group_id' => CustomerGroup::query()->where('code', 'DAILY')->value('id')]);
    settings()->set('sales.allow_edit_price', false);
    $cashier = staffWithRole('Thu ngân');

    $order = app(OrderService::class)->create([
        ...orderData([[$mouse, 2]], ['customer_id' => $agent->id]),
        'items' => [['variant_id' => $mouse->id, 'quantity' => 2, 'price' => 1000]],
    ], $cashier);

    expect($order->price_column)->toBe('wholesale_price')
        ->and($order->price_label)->toBe('Giá bán buôn')
        ->and($order->items->first()->price)->toBe(120000)
        ->and($order->total)->toBe(240000);
});
