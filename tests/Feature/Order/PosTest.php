<?php

use App\Models\PaymentMethod;
use App\Support\Settings\StoreDefaults;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Order\Models\Order;
use Modules\Order\Models\PosShift;

beforeEach(function () {
    StoreDefaults::install();
});

test('the counter asks to open a shift, then sells 3 items paid in cash in one step', function () {
    $cashier = staffWithRole('Thu ngân');
    $mouse = stocked(10, 80000, ['name' => 'Chuột'], ['retail_price' => 150000, 'barcode' => '893000001']);
    $pad = stocked(10, 20000, ['name' => 'Lót chuột'], ['retail_price' => 50000]);
    $cable = stocked(10, 30000, ['name' => 'Cáp'], ['retail_price' => 70000]);

    $this->actingAs($cashier)->get('/pos')->assertInertia(fn (Assert $page) => $page
        ->component('pos/index')
        ->where('shift', null));

    $this->post('/pos/shifts', ['branch_id' => mainBranch()->id, 'opening_cash' => 500000])->assertRedirect();
    $shift = PosShift::query()->firstOrFail();

    // The scanner sends the barcode: the exact product comes first.
    $this->getJson('/orders/variants/search?q=893000001&branch_id='.mainBranch()->id)
        ->assertOk()
        ->assertJsonPath('0.id', $mouse->id)
        ->assertJsonPath('0.exact', true)
        ->assertJsonPath('0.available', 10);

    $response = $this->postJson('/pos/orders', [
        'items' => [
            ['variant_id' => $mouse->id, 'quantity' => 1],
            ['variant_id' => $pad->id, 'quantity' => 2],
            ['variant_id' => $cable->id, 'quantity' => 1],
        ],
        // Cash given 500.000 for 320.000: only the total is recorded.
        'payments' => [['payment_method_id' => cash()->id, 'amount' => 500000]],
    ])->assertOk();

    $order = Order::query()->findOrFail($response->json('order.id'));

    expect($order->channel)->toBe('pos')
        ->and($order->status)->toBe(Order::COMPLETED)
        ->and($order->total)->toBe(320000)
        ->and($order->paid)->toBe(320000)
        ->and($order->pos_shift_id)->toBe($shift->id)
        ->and(stockRow($mouse)->on_hand)->toBe(9)
        ->and(stockRow($pad)->on_hand)->toBe(8)
        ->and($response->json('shift.cash_in'))->toBe(320000)
        ->and($response->json('shift.expected_cash'))->toBe(820000)
        ->and($response->json('print.code'))->toBe($order->code);

    $this->post("/pos/shifts/{$shift->id}/close", ['counted_cash' => 810000])->assertRedirect();
    $shift->refresh();

    expect($shift->status)->toBe('closed')
        ->and($shift->expected_cash)->toBe(820000)
        ->and($shift->difference)->toBe(-10000)
        ->and($shift->orders_count)->toBe(1);
});

test('a walk-in customer must pay in full and nobody sells without a shift', function () {
    $cashier = staffWithRole('Thu ngân');
    $mouse = stocked(5, 80000, [], ['retail_price' => 150000]);
    $sale = ['items' => [['variant_id' => $mouse->id, 'quantity' => 1]], 'payments' => [['payment_method_id' => cash()->id, 'amount' => 100000]]];

    $this->actingAs($cashier)->postJson('/pos/orders', $sale)->assertStatus(422)->assertJsonValidationErrors('shift');

    $this->post('/pos/shifts', ['branch_id' => mainBranch()->id, 'opening_cash' => 0]);
    $this->postJson('/pos/orders', $sale)->assertStatus(422);

    expect(Order::query()->count())->toBe(0)
        ->and(stockRow($mouse)->on_hand)->toBe(5);

    // A known customer may leave the rest as debt.
    $an = customer();
    $this->postJson('/pos/orders', [...$sale, 'customer_id' => $an->id])->assertOk();

    expect($an->refresh()->debt)->toBe(50000);
});

test('the counter shows a VietQR for a bank transfer method', function () {
    $cashier = staffWithRole('Thu ngân');
    $bank = PaymentMethod::query()->create(['name' => 'Chuyển khoản VCB', 'type' => 'bank', 'bank_bin' => '970436', 'account_number' => '0123456789', 'account_name' => 'CONG TY ITSO', 'is_active' => true]);

    $this->actingAs($cashier)->getJson("/pos/qr?payment_method_id={$bank->id}&amount=320000")
        ->assertOk()
        ->assertJsonPath('amount', 320000)
        ->assertJsonPath('account_number', '0123456789');

    expect($this->getJson('/pos/qr?payment_method_id='.cash()->id.'&amount=320000')->assertOk()->json())->toBeEmpty();
});
