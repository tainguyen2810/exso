<?php

use App\Models\Branch;
use App\Models\User;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\InventoryService;
use Modules\Product\Models\ProductVariant;
use Spatie\Permission\Models\Role;

/** Warehouse staff who may manage products but not see purchase prices or cost. */
function staffWithoutCost(?Branch $branch = null): User
{
    Role::findOrCreate('Kho không xem giá vốn', 'web')
        ->syncPermissions(['products.view', 'products.create', 'products.update', 'inventory.view']);

    return staffWithRole('Kho không xem giá vốn', $branch);
}

function costedVariant(): ProductVariant
{
    $variant = variant(['name' => 'Bàn phím cơ'], ['import_price' => 900000]);
    app(InventoryService::class)->move(mainBranch()->id, $variant, 5, StockMovement::OPENING, ['unit_cost' => 850000, 'update_cost' => true]);

    return $variant->refresh();
}

test('cost and purchase price are hidden from staff without the cost permission', function () {
    $variant = costedVariant();
    $staff = staffWithoutCost();

    $this->actingAs($staff)->get("/products/{$variant->product_id}/edit")->assertOk()
        ->assertInertia(fn ($page) => $page->where('product.variants.0.import_price', null)
            ->where('product.variants.0.cost_price', null));

    $this->actingAs($staff)->get('/inventory')->assertOk()
        ->assertInertia(fn ($page) => $page->where('totals.value', null)
            ->where('stock.rows.data.0.cost_price', null));

    $this->actingAs($staff)->get("/inventory/variants/{$variant->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('variant.cost_price', null)
            ->where('movements.rows.data.0.unit_cost', null)
            ->where('movements.rows.data.0.cost_after', null));

    $this->actingAs(owner())->get('/inventory')->assertOk()
        ->assertInertia(fn ($page) => $page->where('totals.value', 5 * 850000));
});

test('saving a product without the cost permission keeps its purchase price', function () {
    $variant = costedVariant();

    $this->actingAs(staffWithoutCost())->put("/products/{$variant->product_id}", [
        'name' => 'Bàn phím cơ',
        'options' => [],
        'variants' => [['id' => $variant->id, 'sku' => $variant->sku, 'retail_price' => 1300000, 'import_price' => 1]],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($variant->refresh()->retail_price)->toBe(1300000)
        ->and($variant->import_price)->toBe(900000)
        ->and($variant->cost_price)->toBe(850000);
});

test('staff with the cost permission see it', function () {
    $variant = costedVariant();

    $this->actingAs(staffWithRole('Nhân viên kho'))->get("/products/{$variant->product_id}/edit")->assertOk()
        ->assertInertia(fn ($page) => $page->where('product.variants.0.import_price', 900000)
            ->where('product.variants.0.cost_price', 850000));
});

test('importing skips stock of branches the user does not work at', function () {
    $main = mainBranch();
    $other = Branch::factory()->create(['code' => 'Q3', 'name' => 'Kho Quận 3']);

    $this->actingAs(staffWithoutCost($main))->post('/products/import', ['file' => productFile(
        ['Tên sản phẩm', 'Mã SKU', 'Giá nhập', 'LC_Chi nhánh chính_Tồn kho ban đầu', 'LC_Kho Quận 3_Tồn kho ban đầu', 'LC_Chi nhánh chính_Giá vốn khởi tạo'],
        [['Chuột', 'CH-01', 70000, 4, 9, 65000]],
    )])->assertRedirect('/products')->assertSessionHasNoErrors();

    $variant = ProductVariant::query()->where('sku', 'CH-01')->sole();
    expect(Inventory::query()->where('variant_id', $variant->id)->where('branch_id', $main->id)->value('on_hand'))->toBe(4)
        ->and(Inventory::query()->where('variant_id', $variant->id)->where('branch_id', $other->id)->exists())->toBeFalse()
        ->and($variant->import_price)->toBe(0)
        ->and($variant->cost_price)->toBe(0);
});
