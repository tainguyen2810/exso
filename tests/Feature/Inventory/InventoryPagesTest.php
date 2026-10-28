<?php

use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\InventoryService;
use Modules\Product\Models\Product;

test('the stock list shows ordinary goods with on hand, held and sellable', function () {
    $branch = mainBranch();
    $mouse = variant(['name' => 'Chuột']);
    variant(['name' => 'Cài Windows', 'type' => Product::TYPE_SERVICE]);
    app(InventoryService::class)->move($branch->id, $mouse, 8, StockMovement::OPENING, ['unit_cost' => 50000, 'update_cost' => true]);
    app(InventoryService::class)->reserve($branch->id, $mouse, 3);

    $this->actingAs(owner())->get('/inventory')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('inventory/index')
            ->has('stock.rows.data', 1)
            ->where('stock.rows.data.0.on_hand', 8)
            ->where('stock.rows.data.0.reserved', 3)
            ->where('stock.rows.data.0.available', 5)
            ->where('totals.value', 400000));

    $this->actingAs(owner())->get('/inventory?filter[stock]=out_of_stock')
        ->assertInertia(fn ($page) => $page->has('stock.rows.data', 0));
});

test('the stock card lists the movements of a variant', function () {
    $branch = mainBranch();
    $mouse = variant();
    app(InventoryService::class)->move($branch->id, $mouse, 8, StockMovement::OPENING);
    app(InventoryService::class)->move($branch->id, $mouse, -2, StockMovement::SALE, ['reference_code' => 'SON00009']);

    $this->actingAs(owner())->get("/inventory/variants/{$mouse->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('inventory/stock-card')
            ->has('movements.rows.data', 2)
            ->where('movements.rows.data.0.reference_code', 'SON00009')
            ->where('movements.rows.data.0.on_hand_after', 6));

    $this->actingAs(owner())->get("/inventory/variants/{$mouse->id}?export=xlsx")->assertOk()->assertDownload();
});

test('the warehouse role can open the stock pages', function () {
    $this->actingAs(staffWithRole('Nhân viên kho'))->get('/inventory')->assertOk();
});
