<?php

use App\Models\Branch;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\StockLedger;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseReceipt;
use Modules\Purchase\Models\Supplier;
use Modules\Purchase\Models\SupplierDebt;
use Modules\Purchase\Services\PurchaseService;

function supplier(array $attributes = []): Supplier
{
    return Supplier::query()->create(['code' => 'NCC'.fake()->unique()->numberBetween(1000, 9999), 'name' => 'Công ty Phân phối ABC', 'status' => 'active', ...$attributes]);
}

function onHand(ProductVariant $variant, ?Branch $branch = null): int
{
    return (int) Inventory::query()->where('variant_id', $variant->id)->where('branch_id', ($branch ?? mainBranch())->id)->value('on_hand');
}

/**
 * @param  list<array{0: ProductVariant, 1: int, 2: int, 3?: int}>  $lines  [variant, quantity, price, discount]
 * @return array<string, mixed>
 */
function receiptPayload(Supplier $supplier, array $lines, array $overrides = []): array
{
    return [
        'supplier_id' => $supplier->id,
        'branch_id' => mainBranch()->id,
        'items' => array_map(fn (array $line): array => ['variant_id' => $line[0]->id, 'quantity' => $line[1], 'price' => $line[2], 'discount' => $line[3] ?? 0], $lines),
        ...$overrides,
    ];
}

test('order discount and purchase costs are spread over the lines by value', function () {
    $service = app(PurchaseService::class);
    $result = $service->calculate([
        ['variant_id' => 1, 'quantity' => 10, 'price' => 100000],
        ['variant_id' => 2, 'quantity' => 3, 'price' => 200000, 'discount' => 0],
    ], 16000, [['name' => 'Vận chuyển', 'amount' => 48000]]);

    // subtotal 1.600.000; costs - discount = 32.000 split 1.000.000 : 600.000 => 20.000 / 12.000
    expect($result['subtotal'])->toBe(1600000)
        ->and($result['total'])->toBe(1632000)
        ->and($result['items'][0]['unit_cost'])->toBe(102000)
        ->and($result['items'][1]['unit_cost'])->toBe(204000);
});

test('receiving a receipt adds stock, averages cost with purchase costs and records the debt', function () {
    $owner = owner();
    $variant = variant(['name' => 'Chuột Logitech'], ['import_price' => 90000]);
    app(InventoryService::class)->move(mainBranch()->id, $variant, 10, StockMovement::OPENING, ['unit_cost' => 90000, 'update_cost' => true]);
    $abc = supplier();

    $this->actingAs($owner)->post('/purchase-receipts', receiptPayload($abc, [[$variant, 10, 100000]], [
        'costs' => [['name' => 'Vận chuyển', 'amount' => 20000]],
        'receive' => true,
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $receipt = PurchaseReceipt::query()->sole();
    $variant->refresh();

    expect($receipt->status)->toBe(PurchaseReceipt::RECEIVED)
        ->and($receipt->code)->toBe('PN00001')
        ->and($receipt->total)->toBe(1020000)
        ->and(onHand($variant))->toBe(20)
        // (10 × 90.000 + 10 × 102.000) / 20
        ->and($variant->cost_price)->toBe(96000)
        ->and($variant->import_price)->toBe(100000)
        ->and($abc->refresh()->debt)->toBe(1020000)
        ->and(app(StockLedger::class)->mismatches())->toBe([]);
});

test('a draft receipt changes nothing until received, and totals come from the server', function () {
    $owner = owner();
    $variant = variant();
    $abc = supplier();

    $this->actingAs($owner)->post('/purchase-receipts', receiptPayload($abc, [[$variant, 5, 50000]], [
        'total' => 1, 'subtotal' => 1,
    ]))->assertSessionHasNoErrors();

    $receipt = PurchaseReceipt::query()->sole();
    expect($receipt->status)->toBe(PurchaseReceipt::DRAFT)
        ->and($receipt->total)->toBe(250000)
        ->and(onHand($variant))->toBe(0)
        ->and($abc->refresh()->debt)->toBe(0);

    $this->actingAs($owner)->post("/purchase-receipts/{$receipt->id}/receive")->assertSessionHasNoErrors();
    expect(onHand($variant))->toBe(5)->and($abc->refresh()->debt)->toBe(250000);

    // Received receipts cannot be received again or edited.
    $this->actingAs($owner)->post("/purchase-receipts/{$receipt->id}/receive")->assertSessionHasErrors('status');
    expect(onHand($variant))->toBe(5);
});

test('payments lower the debt and cannot exceed what is owed', function () {
    $owner = owner();
    $abc = supplier();
    $this->actingAs($owner)->post('/purchase-receipts', receiptPayload($abc, [[variant(), 2, 300000]], ['receive' => true]));
    $receipt = PurchaseReceipt::query()->sole();

    $this->actingAs($owner)->post("/purchase-receipts/{$receipt->id}/pay", ['amount' => 200000, 'method' => 'cash'])->assertSessionHasNoErrors();
    expect($receipt->refresh()->paymentStatus())->toBe('partial')->and($abc->refresh()->debt)->toBe(400000);

    $this->actingAs($owner)->post("/purchase-receipts/{$receipt->id}/pay", ['amount' => 500000, 'method' => 'transfer'])->assertSessionHasErrors('amount');
    $this->actingAs($owner)->post("/purchase-receipts/{$receipt->id}/pay", ['amount' => 400000, 'method' => 'transfer'])->assertSessionHasNoErrors();

    expect($receipt->refresh()->paymentStatus())->toBe('paid')
        ->and($abc->refresh()->debt)->toBe(0)
        ->and(SupplierDebt::query()->where('supplier_id', $abc->id)->sum('amount'))->toEqual(0);
});

test('returning goods takes them out of stock and lowers the debt', function () {
    $owner = owner();
    $variant = variant();
    $abc = supplier();
    $this->actingAs($owner)->post('/purchase-receipts', receiptPayload($abc, [[$variant, 10, 100000]], ['discount' => 100000, 'receive' => true]));
    $receipt = PurchaseReceipt::query()->with('items')->sole();

    $this->actingAs($owner)->post("/purchase-receipts/{$receipt->id}/return", ['items' => [$receipt->items[0]->id => 4]])->assertSessionHasNoErrors();

    // 900.000 after discount / 10 = 90.000 each
    expect(onHand($variant))->toBe(6)
        ->and($abc->refresh()->debt)->toBe(900000 - 360000)
        ->and($receipt->refresh()->returned_total)->toBe(360000)
        ->and($receipt->items()->value('returned_quantity'))->toBe(4);

    $this->actingAs($owner)->post("/purchase-receipts/{$receipt->id}/return", ['items' => [$receipt->items[0]->id => 7]])->assertSessionHasErrors('items');
    expect(onHand($variant))->toBe(6);
});

test('cancelling a received receipt takes the goods back out, unless they were sold', function () {
    $owner = owner();
    $variant = variant();
    $abc = supplier();
    $this->actingAs($owner)->post('/purchase-receipts', receiptPayload($abc, [[$variant, 5, 100000]], ['receive' => true]));
    $receipt = PurchaseReceipt::query()->sole();

    app(InventoryService::class)->move(mainBranch()->id, $variant, -3, StockMovement::SALE);
    $this->actingAs($owner)->post("/purchase-receipts/{$receipt->id}/cancel")->assertSessionHasErrors('stock');
    expect($receipt->refresh()->status)->toBe(PurchaseReceipt::RECEIVED)->and(onHand($variant))->toBe(2);

    app(InventoryService::class)->move(mainBranch()->id, $variant, 3, StockMovement::SALE_RETURN);
    $this->actingAs($owner)->post("/purchase-receipts/{$receipt->id}/cancel")->assertSessionHasNoErrors();

    expect($receipt->refresh()->status)->toBe(PurchaseReceipt::CANCELLED)
        ->and(onHand($variant))->toBe(0)
        ->and($abc->refresh()->debt)->toBe(0);
});

test('a purchase order is filled by receipts and completes when everything arrived', function () {
    $owner = owner();
    $variant = variant();
    $abc = supplier();

    $this->actingAs($owner)->post('/purchase-orders', receiptPayload($abc, [[$variant, 10, 50000]], ['expected_at' => '15/11/2026']))->assertSessionHasNoErrors();
    $order = PurchaseOrder::query()->sole();
    expect($order->code)->toBe('DHN00001')->and($order->expected_at?->toDateString())->toBe('2026-11-15');

    $this->actingAs($owner)->get("/purchase-receipts/create?order={$order->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('receipt.items.0.quantity', 10));

    $this->actingAs($owner)->post('/purchase-receipts', receiptPayload($abc, [[$variant, 6, 50000]], ['purchase_order_id' => $order->id, 'receive' => true]))->assertSessionHasNoErrors();
    expect($order->refresh()->load('items')->receiveStatus())->toBe('partial')->and($order->status)->toBe(PurchaseOrder::PENDING);

    $this->actingAs($owner)->post('/purchase-receipts', receiptPayload($abc, [[$variant, 4, 50000]], ['purchase_order_id' => $order->id, 'receive' => true]))->assertSessionHasNoErrors();
    expect($order->refresh()->status)->toBe(PurchaseOrder::COMPLETED)->and(onHand($variant))->toBe(10);
});

test('closing an order without receipts cancels it', function () {
    $owner = owner();
    $this->actingAs($owner)->post('/purchase-orders', receiptPayload(supplier(), [[variant(), 3, 1000]]));
    $order = PurchaseOrder::query()->sole();

    $this->actingAs($owner)->post("/purchase-orders/{$order->id}/close")->assertSessionHasNoErrors();
    expect($order->refresh()->status)->toBe(PurchaseOrder::CANCELLED);
});

test('combos cannot be purchased and conversion units add base stock', function () {
    $owner = owner();
    $paper = variant(['name' => 'Giấy A4'], ['unit' => 'Ram']);
    $box = ProductVariant::query()->create(['product_id' => $paper->product_id, 'name' => 'Giấy A4 - Thùng', 'sku' => 'A4-THUNG', 'unit' => 'Thùng', 'base_variant_id' => $paper->id, 'unit_ratio' => 5]);
    $combo = variant(['name' => 'Combo', 'type' => Product::TYPE_COMBO]);

    $this->actingAs($owner)->post('/purchase-receipts', receiptPayload(supplier(), [[$combo, 1, 1000]]))->assertSessionHasErrors('items');

    $this->actingAs($owner)->post('/purchase-receipts', receiptPayload(supplier(), [[$box, 2, 500000]], ['receive' => true]))->assertSessionHasNoErrors();
    expect(onHand($paper))->toBe(10)->and($paper->refresh()->cost_price)->toBe(100000);
});

test('staff only see receipts of their branches', function () {
    $owner = owner();
    $other = Branch::factory()->create(['code' => 'Q3', 'name' => 'Kho Quận 3']);
    $abc = supplier();
    $this->actingAs($owner)->post('/purchase-receipts', receiptPayload($abc, [[variant(), 1, 1000]], ['branch_id' => $other->id]));
    $receipt = PurchaseReceipt::query()->sole();

    $staff = staffWithRole('Nhân viên kho', mainBranch());
    $this->actingAs($staff)->get("/purchase-receipts/{$receipt->id}")->assertForbidden();
    $this->actingAs($staff)->post("/purchase-receipts/{$receipt->id}/receive")->assertForbidden();
    $this->actingAs($staff)->get('/purchase-receipts')->assertOk()
        ->assertInertia(fn ($page) => $page->has('receipts.rows.data', 0));
    $this->actingAs($staff)->post('/purchase-receipts', receiptPayload($abc, [[variant(), 1, 1000]], ['branch_id' => $other->id]))->assertSessionHasErrors('branch_id');
});

test('suppliers with receipts or debt cannot be deleted', function () {
    $owner = owner();
    $abc = supplier();
    $this->actingAs($owner)->post('/purchase-receipts', receiptPayload($abc, [[variant(), 1, 1000]], ['receive' => true]));

    $this->actingAs($owner)->delete("/suppliers/{$abc->id}")->assertSessionHasErrors('supplier');
    expect(Supplier::query()->whereKey($abc->id)->exists())->toBeTrue();

    $this->actingAs($owner)->post('/suppliers', ['name' => 'NCC mới', 'status' => 'active'])->assertSessionHasNoErrors();
    expect(Supplier::query()->where('name', 'NCC mới')->value('code'))->toBe('NCC00001');

    $this->actingAs($owner)->get("/suppliers/{$abc->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('supplier.debt', 1000)->has('debts', 1)->has('receipts', 1));
});
