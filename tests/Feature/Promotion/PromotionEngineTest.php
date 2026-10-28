<?php

use App\Models\PaymentMethod;
use App\Support\Settings\SettingsRepository;
use App\Support\Settings\StoreDefaults;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Customer\Models\CustomerGroup;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Services\OrderService;
use Modules\Order\Services\PosService;
use Modules\Order\Services\ReturnService;
use Modules\Product\Models\Category;
use Modules\Promotion\Models\Coupon;
use Modules\Promotion\Models\CouponBatch;
use Modules\Promotion\Models\LoyaltyPoint;
use Modules\Promotion\Models\LoyaltyTier;
use Modules\Promotion\Models\Promotion;
use Modules\Promotion\Services\LoyaltyService;
use Modules\Promotion\Services\PromotionEngine;

beforeEach(function () {
    StoreDefaults::install();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function promotion(string $method, array $conditions, array $attributes = []): Promotion
{
    $promotion = new Promotion([
        'name' => 'KM '.$method,
        'kind' => Promotion::METHODS[$method][0],
        'method' => $method,
        'status' => Promotion::ACTIVE,
        'starts_at' => now()->subDay(),
        'conditions' => $conditions,
        ...$attributes,
    ]);
    $promotion->code = 'KM'.fake()->unique()->numberBetween(10000, 99999);
    $promotion->save();

    return $promotion;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function couponBatch(array $attributes = [], string $code = 'GIAM10'): Coupon
{
    $batch = new CouponBatch([
        'name' => 'Giảm 10%',
        'starts_at' => now()->subDay(),
        'value_type' => 'percent',
        'value' => 1000,
        ...$attributes,
    ]);
    $batch->code = 'DPH'.fake()->unique()->numberBetween(10000, 99999);
    $batch->save();

    return $batch->coupons()->create(['code' => $code]);
}

test('a discount on the order total uses the highest tier reached and counts one use', function () {
    $owner = owner();
    $this->actingAs($owner);
    $mouse = stocked(10, 80000, ['name' => 'Chuột'], ['retail_price' => 150000]);
    $promo = promotion('order_total', [
        ['min' => 300000, 'max' => null, 'type' => 'percent', 'value' => 500],
        ['min' => 500000, 'max' => null, 'type' => 'percent', 'value' => 1000],
    ], ['usage_limit' => 5]);
    $orders = app(OrderService::class);

    $order = $orders->create(orderData([[$mouse, 4]], ['promotion_ids' => [$promo->id], 'shipping_fee' => 20000]), $owner);

    expect($order->subtotal)->toBe(600000)
        ->and($order->promotion_discount)->toBe(60000)
        ->and($order->discount)->toBe(60000)
        ->and($order->total)->toBe(560000)
        ->and($order->promotions['ids'])->toBe([$promo->id])
        ->and($promo->refresh()->used_count)->toBe(1);

    // A typed discount adds to the promotion, never past the subtotal.
    $orders->update($order, orderData([[$mouse, 4]], ['promotion_ids' => [$promo->id], 'discount' => 40000]), $owner);
    expect($order->refresh()->discount)->toBe(100000)
        ->and($order->promotion_discount)->toBe(60000)
        ->and($promo->refresh()->used_count)->toBe(1);

    $orders->cancel($order);
    expect($promo->refresh()->used_count)->toBe(0);
});

test('a product discount by category needs a minimum quantity and stops at its limit', function () {
    $owner = owner();
    $this->actingAs($owner);
    $category = Category::query()->create(['name' => 'Phụ kiện']);
    $mouse = stocked(10, 80000, ['name' => 'Chuột', 'category_id' => $category->id], ['retail_price' => 100000]);
    $pad = stocked(10, 10000, ['name' => 'Lót chuột', 'category_id' => $category->id], ['retail_price' => 50000]);
    $laptop = stocked(10, 9000000, ['name' => 'Laptop'], ['retail_price' => 10000000]);
    $promo = promotion('product', [['ids' => [$category->id], 'min_qty' => 3, 'limit_qty' => 4, 'type' => 'percent', 'value' => 2000]], ['target_type' => 'category']);
    $orders = app(OrderService::class);

    // Only 2 accessories: not enough.
    expect(fn () => $orders->create(orderData([[$mouse, 1], [$pad, 1], [$laptop, 1]], ['promotion_ids' => [$promo->id]]), $owner))
        ->toThrow(ValidationException::class);

    // 3 mice + 2 pads = 5 accessories, discounted on 4 units in cart order.
    $order = $orders->create(orderData([[$mouse, 3], [$pad, 2], [$laptop, 1]], ['promotion_ids' => [$promo->id]]), $owner);
    $lines = $order->items->keyBy('variant_id');

    expect($lines[$mouse->id]->promotion_discount)->toBe(3 * 20000)
        ->and($lines[$pad->id]->promotion_discount)->toBe(1 * 10000)
        ->and($lines[$laptop->id]->promotion_discount)->toBe(0)
        ->and($lines[$mouse->id]->total)->toBe(240000)
        ->and($order->subtotal)->toBe(300000 - 60000 + 100000 - 10000 + 10000000)
        ->and($order->promotion_discount)->toBe(0);
});

test('quantity tiers and buy X get Y discount only the right units', function () {
    $owner = owner();
    $this->actingAs($owner);
    $mouse = stocked(20, 80000, ['name' => 'Chuột'], ['retail_price' => 100000]);
    $pad = stocked(20, 10000, ['name' => 'Lót chuột'], ['retail_price' => 50000]);
    $orders = app(OrderService::class);

    $tiers = promotion('quantity', [['ids' => [$mouse->id], 'tiers' => [
        ['from' => 2, 'to' => 4, 'type' => 'amount', 'value' => 10000],
        ['from' => 5, 'to' => null, 'type' => 'amount', 'value' => 20000],
    ]]]);
    $order = $orders->create(orderData([[$mouse, 5]], ['promotion_ids' => [$tiers->id]]), $owner);
    expect($order->items[0]->promotion_discount)->toBe(100000);

    // Buy 2 mice, get one pad at 50% off, again for every 2 mice.
    $buyGet = promotion('buy_get', [['buy_ids' => [$mouse->id], 'buy_qty' => 2, 'get_ids' => [$pad->id], 'get_qty' => 1, 'type' => 'percent', 'value' => 5000]], ['repeatable' => true]);
    $order = $orders->create(orderData([[$mouse, 5], [$pad, 3]], ['promotion_ids' => [$buyGet->id]]), $owner);
    $lines = $order->items->keyBy('variant_id');
    expect($lines[$mouse->id]->promotion_discount)->toBe(0)
        ->and($lines[$pad->id]->promotion_discount)->toBe(2 * 25000);

    // The same product bought and discounted: the qualifying units are not discounted.
    $same = promotion('buy_get', [['buy_ids' => [$mouse->id], 'buy_qty' => 2, 'get_ids' => [$mouse->id], 'get_qty' => 1, 'type' => 'percent', 'value' => 10000]]);
    expect(fn () => $orders->create(orderData([[$mouse, 2]], ['promotion_ids' => [$same->id]]), $owner))
        ->toThrow(ValidationException::class);
    $order = $orders->create(orderData([[$mouse, 3]], ['promotion_ids' => [$same->id]]), $owner);
    expect($order->items[0]->promotion_discount)->toBe(100000);
});

test('a gift program adds a free line that leaves the stock like any other', function () {
    $owner = owner();
    $this->actingAs($owner);
    $mouse = stocked(10, 80000, ['name' => 'Chuột'], ['retail_price' => 300000]);
    $pad = stocked(10, 10000, ['name' => 'Lót chuột'], ['retail_price' => 50000]);
    $bag = stocked(10, 30000, ['name' => 'Túi'], ['retail_price' => 90000]);
    $promo = promotion('gift_order', [['min_order' => 500000, 'gift_ids' => [$pad->id, $bag->id], 'gift_qty' => 2]]);
    $orders = app(OrderService::class);

    $order = $orders->create(orderData([[$mouse, 2]], ['promotion_ids' => [$promo->id], 'gifts' => ["{$promo->id}-0" => $bag->id]]), $owner, 'export');
    $gift = $order->items->firstWhere('is_gift', true);

    expect($gift)->not->toBeNull()
        ->and($gift->variant_id)->toBe($bag->id)
        ->and($gift->quantity)->toBe(2)
        ->and($gift->price)->toBe(0)
        ->and($gift->total)->toBe(0)
        ->and($order->total)->toBe(600000)
        ->and(stockRow($bag)->on_hand)->toBe(8)
        ->and($order->cost_total)->toBe(2 * 80000 + 2 * 30000);

    // Under the threshold the program does not apply.
    expect(fn () => $orders->create(orderData([[$mouse, 1]], ['promotion_ids' => [$promo->id]]), $owner))
        ->toThrow(ValidationException::class);
});

test('one program per order unless the store allows several; a line keeps the best discount', function () {
    $owner = owner();
    $this->actingAs($owner);
    $mouse = stocked(10, 80000, ['name' => 'Chuột'], ['retail_price' => 100000]);
    $a = promotion('product', [['ids' => [$mouse->id], 'min_qty' => 1, 'type' => 'percent', 'value' => 1000]]);
    $b = promotion('product', [['ids' => [$mouse->id], 'min_qty' => 1, 'type' => 'percent', 'value' => 1500]]);
    $c = promotion('order_total', [['min' => 0, 'type' => 'amount', 'value' => 5000]]);
    $orders = app(OrderService::class);

    expect(fn () => $orders->create(orderData([[$mouse, 1]], ['promotion_ids' => [$a->id, $c->id]]), $owner))
        ->toThrow(ValidationException::class, 'một chương trình');

    app(SettingsRepository::class)->set('promotions.apply_mode', 'many');
    $order = $orders->create(orderData([[$mouse, 1]], ['promotion_ids' => [$a->id, $b->id, $c->id]]), $owner);

    expect($order->items[0]->promotion_discount)->toBe(15000)
        ->and($order->items[0]->promotion_id)->toBe($b->id)
        ->and($order->promotion_discount)->toBe(5000)
        ->and($order->total)->toBe(80000)
        // $a lost every line to $b, so it is not counted as used.
        ->and(array_column($order->promotions['applied'], 'promotion_id'))->toBe([$b->id, $c->id])
        ->and($a->refresh()->used_count)->toBe(0);
});

test('limits, dates, hours, branches and customer groups decide who gets a program', function () {
    $owner = owner();
    $this->actingAs($owner);
    $mouse = stocked(10, 80000, ['name' => 'Chuột'], ['retail_price' => 100000]);
    $orders = app(OrderService::class);
    $rows = [['min' => 0, 'type' => 'amount', 'value' => 10000]];

    $once = promotion('order_total', $rows, ['usage_limit' => 1]);
    $orders->create(orderData([[$mouse, 1]], ['promotion_ids' => [$once->id]]), $owner);
    expect(fn () => $orders->create(orderData([[$mouse, 1]], ['promotion_ids' => [$once->id]]), $owner))
        ->toThrow(ValidationException::class);

    $later = promotion('order_total', $rows, ['starts_at' => now()->addDay()]);
    $paused = promotion('order_total', $rows, ['status' => Promotion::PAUSED]);
    $vip = CustomerGroup::query()->create(['name' => 'Khách VIP', 'code' => 'KVIP']);
    $vipOnly = promotion('order_total', $rows, ['all_customers' => false, 'customer_group_ids' => [$vip->id]]);
    $otherBranch = promotion('order_total', $rows, ['all_branches' => false, 'branch_ids' => [999999]]);

    foreach ([$later, $paused, $vipOnly, $otherBranch] as $promotion) {
        expect(fn () => $orders->create(orderData([[$mouse, 1]], ['promotion_ids' => [$promotion->id]]), $owner))
            ->toThrow(ValidationException::class);
    }

    $member = customer(['group_id' => $vip->id]);
    $order = $orders->create(orderData([[$mouse, 1]], ['promotion_ids' => [$vipOnly->id], 'customer_id' => $member->id]), $owner);
    expect($order->promotion_discount)->toBe(10000);

    // "Hiển thị nâng cao": weekdays and an hour window.
    $engine = app(PromotionEngine::class);
    $monday = CarbonImmutable::parse('2026-11-02 09:30', 'Asia/Ho_Chi_Minh');
    $schedule = ['time_from' => '08:00', 'time_to' => '11:00', 'weekdays' => [1, 3]];
    expect($engine->inSchedule($monday->subYear(), null, $schedule, $monday))->toBeTrue()
        ->and($engine->inSchedule($monday->subYear(), null, $schedule, $monday->setTime(12, 0)))->toBeFalse()
        ->and($engine->inSchedule($monday->subYear(), null, $schedule, $monday->addDay()))->toBeFalse()
        ->and($engine->inSchedule($monday->subYear(), null, ['time_from' => '22:00', 'time_to' => '02:00'], $monday->setTime(1, 0)))->toBeTrue();
});

test('an edited order keeps a program that ended since it was created', function () {
    $owner = owner();
    $this->actingAs($owner);
    $mouse = stocked(10, 80000, ['name' => 'Chuột'], ['retail_price' => 100000]);
    $promo = promotion('order_total', [['min' => 0, 'type' => 'amount', 'value' => 10000]], ['usage_limit' => 1]);
    $orders = app(OrderService::class);

    $order = $orders->create(orderData([[$mouse, 1]], ['promotion_ids' => [$promo->id]]), $owner);
    $promo->forceFill(['status' => Promotion::PAUSED])->save();

    $orders->update($order, orderData([[$mouse, 2]], ['promotion_ids' => [$promo->id]]), $owner);

    expect($order->refresh()->promotion_discount)->toBe(10000)
        ->and($order->total)->toBe(190000)
        ->and($promo->refresh()->used_count)->toBe(1);
});

test('a coupon applies after programs, with its own limits', function () {
    $owner = owner();
    $this->actingAs($owner);
    $mouse = stocked(20, 80000, ['name' => 'Chuột'], ['retail_price' => 100000]);
    $coupon = couponBatch(['max_discount' => 30000, 'uses_per_code' => 2, 'uses_per_customer' => 1, 'min_order' => 200000]);
    $promo = promotion('order_total', [['min' => 0, 'type' => 'amount', 'value' => 10000]]);
    $orders = app(OrderService::class);
    $an = customer();

    // Under the minimum order value.
    expect(fn () => $orders->create(orderData([[$mouse, 1]], ['coupon_code' => 'giam10', 'customer_id' => $an->id]), $owner))
        ->toThrow(ValidationException::class, 'Mã giảm giá áp dụng cho đơn từ 200.000đ.');

    // 10% of 500.000 capped at 30.000; codes are not case sensitive.
    $order = $orders->create(orderData([[$mouse, 5]], ['coupon_code' => 'giam10', 'customer_id' => $an->id]), $owner);
    expect($order->promotion_discount)->toBe(30000)
        ->and($order->promotions['coupon'])->toBe('GIAM10')
        ->and($coupon->refresh()->used_count)->toBe(1)
        ->and($coupon->batch->refresh()->used_count)->toBe(1);

    // Once per customer.
    expect(fn () => $orders->create(orderData([[$mouse, 5]], ['coupon_code' => 'GIAM10', 'customer_id' => $an->id]), $owner))
        ->toThrow(ValidationException::class, 'Khách hàng đã dùng hết lượt của mã này.');

    // Not combined with a program unless the batch allows it.
    $binh = customer(['name' => 'Bình']);
    expect(fn () => $orders->create(orderData([[$mouse, 5]], ['coupon_code' => 'GIAM10', 'customer_id' => $binh->id, 'promotion_ids' => [$promo->id]]), $owner))
        ->toThrow(ValidationException::class, 'không dùng chung');

    $coupon->batch->forceFill(['combinable' => true])->save();
    $order = $orders->create(orderData([[$mouse, 5]], ['coupon_code' => 'GIAM10', 'customer_id' => $binh->id, 'promotion_ids' => [$promo->id]]), $owner);
    // 10.000 from the program, then 10% of the 490.000 left = 49.000 → capped 30.000.
    expect($order->promotion_discount)->toBe(40000);

    // The code is used up (2 uses); cancelling an order gives one back.
    $chi = customer(['name' => 'Chi']);
    expect(fn () => $orders->create(orderData([[$mouse, 5]], ['coupon_code' => 'GIAM10', 'customer_id' => $chi->id]), $owner))
        ->toThrow(ValidationException::class, 'hết lượt');
    $orders->cancel($order);
    $orders->create(orderData([[$mouse, 5]], ['coupon_code' => 'GIAM10', 'customer_id' => $chi->id]), $owner);
    expect($coupon->refresh()->used_count)->toBe(2);
});

test('the order form and POS get the same result from "Áp dụng chương trình khuyến mại"', function () {
    $cashier = staffWithRole('Thu ngân');
    $mouse = stocked(10, 80000, ['name' => 'Chuột'], ['retail_price' => 150000]);
    $pad = stocked(10, 10000, ['name' => 'Lót chuột'], ['retail_price' => 50000]);
    $discount = promotion('product', [['ids' => [$mouse->id], 'min_qty' => 2, 'type' => 'percent', 'value' => 1000]]);
    $gift = promotion('gift_order', [['min_order' => 200000, 'gift_ids' => [$pad->id], 'gift_qty' => 1]]);
    $items = [['variant_id' => $mouse->id, 'quantity' => 2]];

    $offer = $this->actingAs($cashier)->postJson('/orders/promotions', ['branch_id' => mainBranch()->id, 'items' => $items])
        ->assertOk()
        ->json();

    // The best value comes first: a 50.000 gift before a 30.000 discount.
    expect(array_column($offer['available'], 'id'))->toBe([$gift->id, $discount->id])
        ->and($offer['available'][0]['gift_value'])->toBe(50000)
        ->and($offer['available'][0]['gifts'][0]['options'][0]['id'])->toBe($pad->id)
        ->and($offer['available'][1]['discount'])->toBe(30000);

    $preview = $this->postJson('/orders/promotions', ['pos' => true, 'items' => $items, 'promotion_ids' => [$discount->id]])->assertOk()->json();
    expect($preview['lines'][0]['promotion_discount'])->toBe(30000)
        ->and($preview['totals']['total'])->toBe(270000);

    // Saving at the counter computes the same.
    $this->post('/pos/shifts', ['branch_id' => mainBranch()->id, 'opening_cash' => 0])->assertRedirect();
    $sold = $this->postJson('/pos/orders', [
        'items' => $items,
        'promotion_ids' => [$discount->id],
        'payments' => [['payment_method_id' => cash()->id, 'amount' => 300000]],
    ])->assertOk()->json();

    expect($sold['order']['total'])->toBe($preview['totals']['total']);

    // And the order form too.
    $owner = owner();
    $this->actingAs($owner)->post('/orders', [
        'branch_id' => mainBranch()->id,
        'items' => $items,
        'promotion_ids' => [$discount->id],
    ])->assertRedirect();
    expect(Order::query()->latest('id')->first()->total)->toBe(270000);

    // A coupon problem is reported, not thrown, in the preview.
    $preview = $this->postJson('/orders/promotions', ['branch_id' => mainBranch()->id, 'items' => $items, 'coupon_code' => 'KHONGCO'])->assertOk()->json();
    expect($preview['errors']['coupon_code'])->toContain('không tồn tại');
});

test('a returned line gives back its share of promotions; gifts refund nothing', function () {
    $owner = owner();
    $this->actingAs($owner);
    $mouse = stocked(10, 80000, ['name' => 'Chuột'], ['retail_price' => 100000]);
    $pad = stocked(10, 10000, ['name' => 'Lót chuột'], ['retail_price' => 50000]);
    $promo = promotion('gift_product', [['buy_ids' => [$mouse->id], 'buy_qty' => 1, 'gift_ids' => [$pad->id], 'gift_qty' => 1]]);
    $orders = app(OrderService::class);

    $order = $orders->create(orderData([[$mouse, 2]], ['promotion_ids' => [$promo->id], 'discount' => 20000]), $owner, 'export');
    $gift = $order->items->firstWhere('is_gift', true);
    expect($gift->quantity)->toBe(1);

    $return = app(ReturnService::class)->create($order, [$order->items->firstWhere('variant_id', $mouse->id)->id => 1, $gift->id => 1]);
    // 100.000 less half the 20.000 order discount; the gift is worth 0.
    expect($return->total)->toBe(90000)
        ->and(stockRow($pad)->on_hand)->toBe(10);
});

test('points are earned when an order completes, taken back on returns and can pay', function () {
    $owner = owner();
    $this->actingAs($owner);
    $settings = app(SettingsRepository::class);
    $settings->setMany(['loyalty.enabled' => true, 'loyalty.earn_amount' => 10000, 'loyalty.redeem_value' => 1000]);
    LoyaltyTier::query()->create(['name' => 'Bạc', 'min_spent' => 0, 'earn_multiplier_bp' => 10000]);
    LoyaltyTier::query()->create(['name' => 'Vàng', 'min_spent' => 1000000, 'earn_multiplier_bp' => 20000]);
    $mouse = stocked(20, 80000, ['name' => 'Chuột'], ['retail_price' => 100000]);
    $an = customer();
    $orders = app(OrderService::class);
    $loyalty = app(LoyaltyService::class);

    // 300.000 + 20.000 shipping: points on 300.000 → 30 points, after delivery only.
    $order = $orders->create(orderData([[$mouse, 3]], ['customer_id' => $an->id, 'shipping_fee' => 20000]), $owner, 'export');
    expect($loyalty->account($an->id))->toBeNull();
    $orders->markDelivered($order);
    expect($loyalty->account($an->id)->points)->toBe(30)
        ->and($loyalty->account($an->id)->tier->name)->toBe('Bạc');

    // Returning one mouse takes 10 points back.
    app(ReturnService::class)->create($order->refresh(), [$order->items[0]->id => 1]);
    expect($loyalty->account($an->id)->points)->toBe(20);

    // Paying 15.000 with points uses 15 points; too much is refused.
    $points = PaymentMethod::query()->where('type', 'points')->firstOrFail();
    $next = $orders->create(orderData([[$mouse, 1]], ['customer_id' => $an->id]), $owner);
    $orders->pay($next, $points->id, 15000);
    expect($loyalty->account($an->id)->points)->toBe(5);
    expect(fn () => $orders->pay($next, $points->id, 6000))->toThrow(ValidationException::class, 'tối đa 5.000đ');

    // Cancelling gives the points back.
    $orders->cancel($next);
    expect($loyalty->account($an->id)->points)->toBe(20)
        ->and(LoyaltyPoint::query()->where('customer_id', $an->id)->pluck('type')->all())
        ->toBe([LoyaltyPoint::EARN, LoyaltyPoint::RETURN, LoyaltyPoint::REDEEM, LoyaltyPoint::REFUND]);

    // A big spender reaches the gold tier and earns double.
    $big = $orders->create(orderData([[$mouse, 12]], ['customer_id' => $an->id, 'delivery_method' => 'pickup']), $owner, 'export');
    expect($big->refresh()->status)->toBe(Order::COMPLETED)
        ->and($loyalty->account($an->id)->tier->name)->toBe('Vàng')
        ->and($loyalty->account($an->id)->points)->toBe(20 + 240);

    // A walk-in customer cannot pay with points.
    $walkIn = $orders->create(orderData([[$mouse, 1]]), $owner);
    expect(fn () => $orders->pay($walkIn, $points->id, 1000))->toThrow(ValidationException::class, 'Chọn khách hàng');
});

test('POS sells with a gift and a coupon in one step', function () {
    $cashier = staffWithRole('Thu ngân');
    $mouse = stocked(10, 80000, ['name' => 'Chuột'], ['retail_price' => 300000]);
    $pad = stocked(10, 10000, ['name' => 'Lót chuột'], ['retail_price' => 50000]);
    $gift = promotion('gift_order', [['min_order' => 200000, 'gift_ids' => [$pad->id], 'gift_qty' => 1]]);
    couponBatch(['value_type' => 'amount', 'value' => 20000, 'combinable' => true], 'TET2027');
    $this->actingAs($cashier);
    $shift = app(PosService::class)->openShift($cashier, mainBranch()->id, 0);

    $order = app(PosService::class)->sell([
        'items' => [['variant_id' => $mouse->id, 'quantity' => 1]],
        'promotion_ids' => [$gift->id],
        'coupon_code' => 'TET2027',
        'payments' => [['payment_method_id' => cash()->id, 'amount' => 300000]],
    ], $cashier, $shift);

    expect($order->total)->toBe(280000)
        ->and($order->paid)->toBe(280000)
        ->and($order->status)->toBe(Order::COMPLETED)
        ->and(OrderItem::query()->where('order_id', $order->id)->where('is_gift', true)->value('variant_id'))->toBe($pad->id)
        ->and(stockRow($pad)->on_hand)->toBe(9);
});
