<?php

use App\Models\Branch;
use Modules\Inventory\Models\CostAdjustment;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Stocktake;
use Modules\Inventory\Models\StockTransfer;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\StockDocumentService;
use Modules\Inventory\Services\StockLedger;
use Modules\Product\Models\ProductVariant;

function stockAt(ProductVariant $variant, Branch $branch): int
{
    return (int) Inventory::query()->where('variant_id', $variant->id)->where('branch_id', $branch->id)->value('on_hand');
}

test('balancing a stocktake sets stock to the counted numbers', function () {
    $owner = owner();
    $main = mainBranch();
    $mouse = variant(['name' => 'Chuột']);
    $keyboard = variant(['name' => 'Bàn phím']);
    app(InventoryService::class)->move($main->id, $mouse, 10, StockMovement::OPENING);
    app(InventoryService::class)->move($main->id, $keyboard, 4, StockMovement::OPENING);

    $this->actingAs($owner)->post('/stocktakes', [
        'branch_id' => $main->id,
        'items' => [
            ['variant_id' => $mouse->id, 'actual_quantity' => 8, 'reason' => 'Hư hỏng'],
            ['variant_id' => $keyboard->id, 'actual_quantity' => 6],
        ],
    ])->assertSessionHasNoErrors();

    $stocktake = Stocktake::query()->sole();
    expect($stocktake->status)->toBe(Stocktake::DRAFT)->and(stockAt($mouse, $main))->toBe(10);

    // A sale while counting: the difference is taken at balance time.
    app(InventoryService::class)->move($main->id, $mouse, -1, StockMovement::SALE);

    $this->actingAs($owner)->put("/stocktakes/{$stocktake->id}", [
        'branch_id' => $main->id,
        'items' => [
            ['variant_id' => $mouse->id, 'actual_quantity' => 8, 'reason' => 'Hư hỏng'],
            ['variant_id' => $keyboard->id, 'actual_quantity' => 6],
        ],
        'balance' => true,
    ])->assertSessionHasNoErrors();

    $items = $stocktake->refresh()->items()->orderBy('id')->get();
    expect($stocktake->status)->toBe(Stocktake::BALANCED)
        ->and(stockAt($mouse, $main))->toBe(8)
        ->and(stockAt($keyboard, $main))->toBe(6)
        ->and($items[0]->system_quantity)->toBe(9)
        ->and($items[0]->difference)->toBe(-1)
        ->and($items[1]->difference)->toBe(2)
        ->and(app(StockLedger::class)->mismatches())->toBe([]);

    $this->actingAs($owner)->put("/stocktakes/{$stocktake->id}", ['branch_id' => $main->id, 'items' => [['variant_id' => $mouse->id, 'actual_quantity' => 1]]])
        ->assertSessionHasErrors('status');
});

test('a transfer leaves the sending branch when shipped and arrives when received', function () {
    $owner = owner();
    $main = mainBranch();
    $other = Branch::factory()->create(['code' => 'Q3', 'name' => 'Kho Quận 3']);
    $mouse = variant(['name' => 'Chuột']);
    app(InventoryService::class)->move($main->id, $mouse, 10, StockMovement::OPENING, ['unit_cost' => 50000, 'update_cost' => true]);

    $this->actingAs($owner)->post('/stock-transfers', [
        'from_branch_id' => $main->id,
        'to_branch_id' => $other->id,
        'items' => [['variant_id' => $mouse->id, 'quantity' => 4]],
        'ship' => true,
    ])->assertSessionHasNoErrors();

    $transfer = StockTransfer::query()->sole();
    expect($transfer->status)->toBe(StockTransfer::SHIPPING)
        ->and(stockAt($mouse, $main))->toBe(6)
        ->and(stockAt($mouse, $other))->toBe(0)
        ->and($transfer->items()->value('unit_cost'))->toBe(50000);

    $this->actingAs($owner)->post("/stock-transfers/{$transfer->id}/receive")->assertSessionHasNoErrors();
    expect($transfer->refresh()->status)->toBe(StockTransfer::RECEIVED)
        ->and(stockAt($mouse, $other))->toBe(4)
        ->and($mouse->refresh()->cost_price)->toBe(50000)
        ->and(app(StockLedger::class)->mismatches())->toBe([]);
});

test('a transfer cannot send more than is in stock, and cancelling a shipped one returns the goods', function () {
    $owner = owner();
    $main = mainBranch();
    $other = Branch::factory()->create(['code' => 'Q3', 'name' => 'Kho Quận 3']);
    $mouse = variant(['name' => 'Chuột']);
    app(InventoryService::class)->move($main->id, $mouse, 3, StockMovement::OPENING);

    $this->actingAs($owner)->post('/stock-transfers', [
        'from_branch_id' => $main->id, 'to_branch_id' => $other->id,
        'items' => [['variant_id' => $mouse->id, 'quantity' => 5]], 'ship' => true,
    ])->assertSessionHasErrors('stock');

    $transfer = StockTransfer::query()->sole();
    expect($transfer->status)->toBe(StockTransfer::DRAFT)->and(stockAt($mouse, $main))->toBe(3);

    $this->actingAs($owner)->put("/stock-transfers/{$transfer->id}", [
        'from_branch_id' => $main->id, 'to_branch_id' => $other->id,
        'items' => [['variant_id' => $mouse->id, 'quantity' => 2]], 'ship' => true,
    ])->assertSessionHasNoErrors();
    expect(stockAt($mouse, $main))->toBe(1);

    $this->actingAs($owner)->post("/stock-transfers/{$transfer->id}/cancel")->assertSessionHasNoErrors();
    expect($transfer->refresh()->status)->toBe(StockTransfer::CANCELLED)->and(stockAt($mouse, $main))->toBe(3);
});

test('the receiving branch must be another branch', function () {
    $main = mainBranch();
    $this->actingAs(owner())->post('/stock-transfers', [
        'from_branch_id' => $main->id, 'to_branch_id' => $main->id,
        'items' => [['variant_id' => variant()->id, 'quantity' => 1]],
    ])->assertSessionHasErrors('to_branch_id');
});

test('staff of the receiving branch receive, staff elsewhere cannot see the transfer', function () {
    $main = mainBranch();
    $other = Branch::factory()->create(['code' => 'Q3', 'name' => 'Kho Quận 3']);
    $third = Branch::factory()->create(['code' => 'Q7', 'name' => 'Kho Quận 7']);
    $mouse = variant();
    app(InventoryService::class)->move($main->id, $mouse, 5, StockMovement::OPENING);
    $this->actingAs(owner());
    $transfer = app(StockDocumentService::class)->saveTransfer(null, ['from_branch_id' => $main->id, 'to_branch_id' => $other->id, 'items' => [['variant_id' => $mouse->id, 'quantity' => 2]]]);
    app(StockDocumentService::class)->ship($transfer);

    $this->actingAs(staffWithRole('Nhân viên kho', $third))->get("/stock-transfers/{$transfer->id}")->assertForbidden();
    $this->actingAs(staffWithRole('Nhân viên kho', $main))->post("/stock-transfers/{$transfer->id}/receive")->assertForbidden();
    $this->actingAs(staffWithRole('Nhân viên kho', $other))->post("/stock-transfers/{$transfer->id}/receive")->assertSessionHasNoErrors();

    expect(stockAt($mouse, $other))->toBe(2);
});

test('a cost adjustment sets the average cost and shows on the stock card', function () {
    $owner = owner();
    $main = mainBranch();
    $mouse = variant();
    app(InventoryService::class)->move($main->id, $mouse, 5, StockMovement::OPENING, ['unit_cost' => 50000, 'update_cost' => true]);

    $this->actingAs($owner)->post('/cost-adjustments', [
        'branch_id' => $main->id,
        'items' => [['variant_id' => $mouse->id, 'new_cost' => 55000]],
        'apply' => true,
    ])->assertSessionHasNoErrors();

    $adjustment = CostAdjustment::query()->with('items')->sole();
    expect($adjustment->status)->toBe(CostAdjustment::APPLIED)
        ->and($adjustment->items[0]->old_cost)->toBe(50000)
        ->and($mouse->refresh()->cost_price)->toBe(55000)
        ->and(StockMovement::query()->where('type', StockMovement::COST_ADJUSTMENT)->value('cost_after'))->toBe(55000)
        ->and(app(StockLedger::class)->mismatches())->toBe([]);
});

test('cost adjustments need the cost permission', function () {
    $staff = staffWithRole('Nhân viên kho');
    $this->actingAs($staff)->get('/cost-adjustments')->assertForbidden();

    $staff->givePermissionTo('products.view_cost');
    $this->actingAs($staff)->get('/cost-adjustments')->assertOk();
});

test('minimum and maximum stock are set per branch', function () {
    $mouse = variant();
    $main = mainBranch();

    $this->actingAs(owner())->put("/inventory/variants/{$mouse->id}/levels", ['branch_id' => $main->id, 'min_quantity' => 5, 'max_quantity' => 50])->assertSessionHasNoErrors();
    expect(Inventory::query()->where('variant_id', $mouse->id)->value('min_quantity'))->toBe(5);

    $this->actingAs(owner())->put("/inventory/variants/{$mouse->id}/levels", ['branch_id' => $main->id, 'min_quantity' => 10, 'max_quantity' => 5])->assertSessionHasErrors('max_quantity');
});
