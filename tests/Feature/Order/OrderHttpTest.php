<?php

use App\Models\Branch;
use App\Models\OrderReason;
use App\Models\Role;
use App\Models\User;
use App\Support\Settings\StoreDefaults;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Customer\Models\CustomerGroup;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderReturn;
use Modules\Order\Services\OrderService;

beforeEach(function () {
    StoreDefaults::install();
});

/** A sales person who only sees the orders and customers in their charge. */
function seller(): User
{
    Role::findOrCreate('Bán hàng', 'web')->syncPermissions(['orders.view', 'orders.create', 'orders.update', 'customers.view', 'customers.create']);

    return staffWithRole('Bán hàng');
}

test('the order form saves, approves and exports in one go when allowed', function () {
    $cashier = staffWithRole('Thu ngân');
    $mouse = stocked(10, 80000, [], ['retail_price' => 150000]);

    $this->actingAs($cashier)->post('/orders', [
        ...orderData([[$mouse, 2]]),
        'delivery_method' => 'pickup',
        'next_step' => 'export',
        // Cash given 500.000: the order records only what it costs.
        'payments' => [['payment_method_id' => cash()->id, 'amount' => 500000]],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $order = Order::query()->firstOrFail();

    expect($order->status)->toBe(Order::COMPLETED)
        ->and($order->total)->toBe(300000)
        ->and($order->paid)->toBe(300000)
        ->and(stockRow($mouse)->on_hand)->toBe(8);
});

test('a seller cannot approve, pay or see orders of others', function () {
    $mouse = stocked(10, 80000, [], ['retail_price' => 150000]);
    $owner = owner();
    $theirs = app(OrderService::class)->create(orderData([[$mouse, 1]]), $owner);
    $seller = seller();

    $this->actingAs($seller)->post('/orders', [...orderData([[$mouse, 1]]), 'next_step' => 'approve'])->assertForbidden();
    $this->post('/orders', [...orderData([[$mouse, 1]]), 'payments' => [['payment_method_id' => cash()->id, 'amount' => 1000]]])->assertForbidden();
    $this->post('/orders', orderData([[$mouse, 1]]))->assertRedirect();
    $mine = Order::query()->where('created_by', $seller->id)->firstOrFail();

    $this->get("/orders/{$theirs->id}")->assertForbidden();
    $this->post("/orders/{$mine->id}/approve")->assertForbidden();
    $this->get('/orders')->assertInertia(fn (Assert $page) => $page
        ->component('orders/index')
        ->has('orders.rows.data', 1)
        ->where('orders.rows.data.0.id', $mine->id));
});

test('orders of a branch the user does not work in are out of reach', function () {
    $other = Branch::query()->create(['code' => 'CN02', 'name' => 'Chi nhánh 2']);
    settings()->set('sales.allow_negative_order', true);
    $mouse = variant();
    $owner = owner();
    $owner->branches()->attach($other);
    $order = app(OrderService::class)->create([...orderData([[$mouse, 1]]), 'branch_id' => $other->id], $owner);
    $cashier = staffWithRole('Thu ngân');

    $this->actingAs($cashier)->get("/orders/{$order->id}")->assertForbidden();
    $this->post('/orders', [...orderData([[$mouse, 1]]), 'branch_id' => $other->id])->assertSessionHasErrors('branch_id');
});

test('an exported order cannot be edited and a cancel reason is required when set', function () {
    $owner = owner();
    $mouse = stocked(10, 80000);
    $orders = app(OrderService::class);
    $order = $orders->create(orderData([[$mouse, 1]]), $owner, 'export');

    $this->actingAs($owner)->get("/orders/{$order->id}/edit")->assertRedirect("/orders/{$order->id}");
    $this->put("/orders/{$order->id}", orderData([[$mouse, 3]]))->assertSessionHasErrors('status');

    settings()->set('reasons.require_cancel', true);
    $pending = $orders->create(orderData([[$mouse, 1]]), $owner);
    $this->post("/orders/{$pending->id}/cancel", [])->assertSessionHasErrors();
    $this->post("/orders/{$pending->id}/cancel", ['reason_id' => OrderReason::query()->where('type', 'cancel')->value('id')])->assertSessionHasNoErrors();

    expect($pending->refresh()->status)->toBe(Order::CANCELLED);
});

test('the order page, list and print show what the order holds', function () {
    $owner = owner();
    $mouse = stocked(10, 80000, ['name' => 'Chuột'], ['retail_price' => 150000]);
    $order = app(OrderService::class)->create(orderData([[$mouse, 2]], ['customer_id' => customer()->id]), $owner, 'approve');

    $this->actingAs($owner)->get("/orders/{$order->id}")->assertInertia(fn (Assert $page) => $page
        ->component('orders/show')
        ->where('order.code', $order->code)
        ->where('order.remaining', 300000)
        ->has('order.items', 1)
        ->where('order.items.0.available', 8));

    $this->get('/orders?filter[queue]=packing')->assertInertia(fn (Assert $page) => $page
        ->has('orders.rows.data', 1)
        ->where('queues.2.key', 'packing')
        ->where('queues.2.count', 1));

    $this->get("/orders/{$order->id}/print?size=80")->assertInertia(fn (Assert $page) => $page
        ->component('orders/print')
        ->where('size', '80')
        ->where('orders.0.total', 300000));

    $this->get('/orders?export=xlsx')->assertOk();
});

test('a customer return over HTTP restocks and refunds', function () {
    $owner = owner();
    $mouse = stocked(10, 80000, [], ['retail_price' => 150000]);
    $orders = app(OrderService::class);
    $order = $orders->create([...orderData([[$mouse, 2]]), 'delivery_method' => 'pickup', 'payments' => [['payment_method_id' => cash()->id, 'amount' => 300000]]], $owner, 'export');
    $item = $order->items()->firstOrFail();

    $this->actingAs($owner)->get("/returns/create?code={$order->code}")->assertInertia(fn (Assert $page) => $page
        ->component('returns/create')
        ->where('order.id', $order->id)
        ->where('order.items.0.refund_price', 150000));

    $this->post('/returns', [
        'order_id' => $order->id,
        'items' => [$item->id => 1],
        'refund_method_id' => cash()->id,
        'refund_amount' => 150000,
    ])->assertRedirect();

    $return = OrderReturn::query()->firstOrFail();

    expect($return->total)->toBe(150000)
        ->and($return->refunded)->toBe(150000)
        ->and(stockRow($mouse)->on_hand)->toBe(9)
        ->and($order->refresh()->paid)->toBe(150000);

    $this->get("/returns/{$return->id}")->assertInertia(fn (Assert $page) => $page->component('returns/show')->where('orderReturn.code', $return->code));
    $this->get('/returns')->assertInertia(fn (Assert $page) => $page->has('returns.rows.data', 1));
});

test('client prices count only when the store lets staff edit prices', function () {
    $cashier = staffWithRole('Thu ngân');
    $mouse = stocked(10, 80000, [], ['retail_price' => 150000]);
    $line = ['items' => [['variant_id' => $mouse->id, 'quantity' => 1, 'price' => 1000]]];

    settings()->set('sales.allow_edit_price', false);
    $this->actingAs($cashier)->post('/orders', [...orderData([]), ...$line]);
    settings()->set('sales.allow_edit_price', true);
    $this->post('/orders', [...orderData([]), ...$line]);

    expect(Order::query()->orderBy('id')->pluck('total')->all())->toBe([150000, 1000]);
});

test('putting a customer in the "Đại lý" group prices new orders at wholesale', function () {
    settings()->set('sales.allow_edit_price', false);
    $cashier = staffWithRole('Thu ngân');
    $mouse = stocked(10, 80000, [], ['retail_price' => 150000, 'wholesale_price' => 120000]);
    $an = customer();

    $this->actingAs(owner())->put("/customers/{$an->id}", ['name' => $an->name, 'group_id' => CustomerGroup::query()->where('code', 'DAILY')->value('id')])->assertRedirect();

    $this->actingAs($cashier)->getJson("/customers/search?q={$an->code}")->assertJsonPath('0.price_policy', 'wholesale_price');
    $this->post('/orders', [...orderData([[$mouse, 2]]), 'customer_id' => $an->id])->assertRedirect();

    expect(Order::query()->firstOrFail()->total)->toBe(240000);
});

test('the dashboard sales block counts today\'s sales', function () {
    $owner = owner();
    $mouse = stocked(10, 80000, [], ['retail_price' => 150000]);
    app(OrderService::class)->create([...orderData([[$mouse, 2]]), 'delivery_method' => 'pickup'], $owner, 'export');

    $this->actingAs($owner)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('sales.ready', true)
        ->where('sales.today.revenue', 300000)
        ->where('sales.top.0.quantity', 2));
});
