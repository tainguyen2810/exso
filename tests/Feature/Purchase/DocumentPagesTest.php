<?php

use App\Models\Branch;
use Modules\Inventory\Models\CostAdjustment;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Stocktake;
use Modules\Inventory\Models\StockTransfer;
use Modules\Inventory\Services\InventoryService;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseReceipt;
use Modules\Purchase\Models\Supplier;

test('every purchase and stock document page renders', function () {
    $owner = owner();
    $main = mainBranch();
    $other = Branch::factory()->create(['code' => 'Q3', 'name' => 'Kho Quận 3']);
    $variant = variant(['name' => 'Chuột Logitech'], ['import_price' => 90000]);
    app(InventoryService::class)->move($main->id, $variant, 10, StockMovement::OPENING, ['unit_cost' => 90000, 'update_cost' => true]);
    $supplier = Supplier::query()->create(['code' => 'NCC00001', 'name' => 'Công ty ABC', 'status' => 'active']);
    $line = ['variant_id' => $variant->id, 'quantity' => 2, 'price' => 100000];

    $this->actingAs($owner);
    $this->post('/purchase-orders', ['supplier_id' => $supplier->id, 'branch_id' => $main->id, 'expected_at' => '05/11/2026', 'items' => [$line]])->assertSessionHasNoErrors();
    $this->post('/purchase-receipts', ['supplier_id' => $supplier->id, 'branch_id' => $main->id, 'items' => [$line], 'receive' => true])->assertSessionHasNoErrors();
    $this->post('/purchase-receipts', ['supplier_id' => $supplier->id, 'branch_id' => $main->id, 'items' => [$line]])->assertSessionHasNoErrors();
    $this->post('/stocktakes', ['branch_id' => $main->id, 'items' => [['variant_id' => $variant->id, 'actual_quantity' => 9]]])->assertSessionHasNoErrors();
    $this->post('/stock-transfers', ['from_branch_id' => $main->id, 'to_branch_id' => $other->id, 'items' => [['variant_id' => $variant->id, 'quantity' => 1]], 'ship' => true])->assertSessionHasNoErrors();
    $this->post('/cost-adjustments', ['branch_id' => $main->id, 'items' => [['variant_id' => $variant->id, 'new_cost' => 95000]]])->assertSessionHasNoErrors();

    $order = PurchaseOrder::query()->sole();
    [$received, $draft] = PurchaseReceipt::query()->orderBy('id')->get()->all();

    $pages = [
        '/suppliers' => 'suppliers/index',
        '/suppliers/create' => 'suppliers/form',
        "/suppliers/{$supplier->id}" => 'suppliers/form',
        '/purchase-orders' => 'purchase-orders/index',
        '/purchase-orders/create' => 'purchase-orders/form',
        "/purchase-orders/{$order->id}" => 'purchase-orders/show',
        "/purchase-orders/{$order->id}/edit" => 'purchase-orders/form',
        '/purchase-receipts' => 'purchase-receipts/index',
        '/purchase-receipts/create' => 'purchase-receipts/form',
        "/purchase-receipts/{$received->id}" => 'purchase-receipts/show',
        "/purchase-receipts/{$draft->id}/edit" => 'purchase-receipts/form',
        '/stocktakes' => 'stocktakes/index',
        '/stocktakes/create' => 'stocktakes/form',
        '/stocktakes/'.Stocktake::query()->sole()->id => 'stocktakes/form',
        '/stock-transfers' => 'stock-transfers/index',
        '/stock-transfers/create' => 'stock-transfers/form',
        '/stock-transfers/'.StockTransfer::query()->sole()->id => 'stock-transfers/form',
        '/cost-adjustments' => 'cost-adjustments/index',
        '/cost-adjustments/create' => 'cost-adjustments/form',
        '/cost-adjustments/'.CostAdjustment::query()->sole()->id => 'cost-adjustments/form',
    ];

    foreach ($pages as $url => $component) {
        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->component($component));
    }

    $this->get("/purchase-receipts/{$received->id}")->assertInertia(fn ($page) => $page
        ->where('receipt.status', 'received')
        ->where('receipt.remaining', 200000)
        ->where('receipt.items.0.return_price', 100000));
});
