<?php

use App\Models\Tenant;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\InventoryService;
use Modules\Product\Models\Category;
use Modules\Product\Models\PriceList;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function productPayload(array $overrides = []): array
{
    return [
        'type' => 'normal',
        'name' => 'Bàn phím cơ',
        'category' => 'Phụ kiện',
        'brand' => 'Logitech',
        'unit' => 'Cái',
        'is_sellable' => true,
        'allow_negative_stock' => false,
        'options' => [],
        'variants' => [[
            'sku' => 'BP-01', 'barcode' => '8930000000001',
            'retail_price' => 1200000, 'wholesale_price' => 1100000, 'import_price' => 900000,
            'opening_quantity' => 5, 'opening_cost' => 850000,
        ]],
        ...$overrides,
    ];
}

test('the product list opens with stock and filters', function () {
    $owner = owner();
    $variant = variant(['name' => 'Chuột Logitech']);
    app(InventoryService::class)->move(mainBranch()->id, $variant, 7, StockMovement::OPENING);

    $this->actingAs($owner)->get('/products?search=Logitech')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('products/index')
            ->has('products.rows.data', 1)
            ->where('products.rows.data.0.on_hand', 7)
            ->where('products.rows.data.0.sku', $variant->sku));
});

test('a simple product is created with opening stock in the current branch', function () {
    $owner = owner();

    $this->actingAs($owner)->post('/products', productPayload())->assertRedirect()->assertSessionHasNoErrors();

    $product = Product::query()->with('variants', 'category', 'brand')->sole();
    $variant = $product->variants->sole();

    expect($product->category->name)->toBe('Phụ kiện')
        ->and($product->brand->name)->toBe('Logitech')
        ->and($product->created_by)->toBe($owner->id)
        ->and($variant->sku)->toBe('BP-01')
        ->and($variant->retail_price)->toBe(1200000)
        ->and($variant->cost_price)->toBe(850000)
        ->and(Inventory::query()->where('variant_id', $variant->id)->value('on_hand'))->toBe(5)
        ->and(StockMovement::query()->where('type', StockMovement::OPENING)->sum('quantity'))->toEqual(5);
});

test('a product with attributes gets one variant per combination', function () {
    $this->actingAs(owner())->post('/products', productPayload([
        'name' => 'Áo thun',
        'options' => ['Màu', 'Size'],
        'variants' => [
            ['options' => ['Đỏ', 'M'], 'sku' => 'AT-DO-M', 'retail_price' => 150000, 'opening_quantity' => 3],
            ['options' => ['Đỏ', 'L'], 'sku' => '', 'retail_price' => 160000],
        ],
    ]))->assertSessionHasNoErrors();

    $product = Product::query()->sole();
    $variants = $product->variants;

    expect($product->option1)->toBe('Màu')->and($product->option2)->toBe('Size')
        ->and($variants)->toHaveCount(2)
        ->and($variants[0]->name)->toBe('Áo thun - Đỏ / M')
        ->and($variants[1]->sku)->toStartWith('PVN')
        ->and($variants[1]->option2)->toBe('L');
});

test('sku and barcode must be unique', function () {
    variant([], ['sku' => 'BP-01', 'barcode' => '111']);

    $this->actingAs(owner())->post('/products', productPayload())->assertSessionHasErrors('sku');

    $this->actingAs(owner())->post('/products', productPayload([
        'variants' => [['sku' => 'NEW-1', 'barcode' => '111']],
    ]))->assertSessionHasErrors('barcode');

    $this->actingAs(owner())->post('/products', productPayload([
        'options' => ['Màu'],
        'variants' => [['options' => ['Đỏ'], 'sku' => 'X1'], ['options' => ['Xanh'], 'sku' => 'x1']],
    ]))->assertSessionHasErrors('sku');

    expect(Product::query()->count())->toBe(1);
});

test('conversion units are saved on the base variant', function () {
    $this->actingAs(owner())->post('/products', productPayload([
        'name' => 'Nước suối',
        'unit' => 'Chai',
        'variants' => [['sku' => 'NS', 'retail_price' => 10000, 'import_price' => 6000]],
        'units' => [['unit' => 'Thùng', 'ratio' => 24, 'sku' => 'NS-T', 'retail_price' => 220000]],
    ]))->assertSessionHasNoErrors();

    $unit = ProductVariant::query()->where('sku', 'NS-T')->sole();
    $base = ProductVariant::query()->where('sku', 'NS')->sole();

    expect($unit->base_variant_id)->toBe($base->id)
        ->and($unit->unit_ratio)->toBe(24)
        ->and($unit->name)->toBe('Nước suối (Thùng)')
        ->and($unit->import_price)->toBe(144000);
});

test('a combo is made of other products and has no stock of its own', function () {
    $mouse = variant(['name' => 'Chuột']);
    $pad = variant(['name' => 'Lót chuột']);

    $this->actingAs(owner())->post('/products', productPayload([
        'type' => 'combo',
        'name' => 'Combo văn phòng',
        'variants' => [['sku' => 'CB-01', 'retail_price' => 300000, 'opening_quantity' => 9]],
        'combo_items' => [['variant_id' => $mouse->id, 'quantity' => 1], ['variant_id' => $pad->id, 'quantity' => 2]],
    ]))->assertSessionHasNoErrors();

    $combo = Product::query()->where('type', 'combo')->with('comboItems')->sole();

    expect($combo->comboItems)->toHaveCount(2)
        ->and($combo->comboItems[1]->quantity)->toBe(2)
        ->and(StockMovement::query()->count())->toBe(0);
});

test('a combo needs components', function () {
    $this->actingAs(owner())->post('/products', productPayload(['type' => 'combo', 'combo_items' => []]))
        ->assertSessionHasErrors('combo_items');
});

test('updating keeps stock and changes prices', function () {
    $owner = owner();
    $this->actingAs($owner)->post('/products', productPayload());
    $product = Product::query()->sole();
    $variant = $product->variants->sole();
    $list = PriceList::query()->create(['code' => 'DAILY', 'name' => 'Giá đại lý']);

    $this->actingAs($owner)->get("/products/{$product->id}/edit")->assertOk()
        ->assertInertia(fn ($page) => $page->component('products/form')->where('product.variants.0.stock.0.on_hand', 5));

    $this->actingAs($owner)->put("/products/{$product->id}", productPayload([
        'name' => 'Bàn phím cơ K2',
        'category' => 'Bàn phím',
        'variants' => [[
            'id' => $variant->id, 'sku' => 'BP-01', 'barcode' => '8930000000001', 'retail_price' => 1300000,
            'prices' => [$list->id => 1000000], 'opening_quantity' => 99,
        ]],
    ]))->assertSessionHasNoErrors();

    $variant->refresh();

    expect($variant->name)->toBe('Bàn phím cơ K2')
        ->and($variant->retail_price)->toBe(1300000)
        ->and($variant->prices()->value('price'))->toBe(1000000)
        ->and(Inventory::query()->where('variant_id', $variant->id)->value('on_hand'))->toBe(5)
        ->and(Category::query()->count())->toBe(2);
});

test('a product with stock cannot be deleted', function () {
    $owner = owner();
    $this->actingAs($owner)->post('/products', productPayload());
    $product = Product::query()->sole();

    $this->actingAs($owner)->delete("/products/{$product->id}")->assertRedirect();
    expect($product->fresh()->trashed())->toBeFalse();

    $empty = variant(['name' => 'Hết hàng']);
    $this->actingAs($owner)->delete("/products/{$empty->product_id}")->assertRedirect('/products');
    expect(Product::query()->find($empty->product_id))->toBeNull();
});

test('bulk actions stop selling products', function () {
    $a = variant(['name' => 'A']);
    $b = variant(['name' => 'B']);

    $this->actingAs(owner())->post('/products/bulk', ['action' => 'unsellable', 'ids' => [$a->product_id, $b->product_id]])
        ->assertRedirect();

    expect(Product::query()->where('is_sellable', true)->count())->toBe(0);
});

test('a cashier can view but not create products, and cannot open the stock pages', function () {
    $cashier = staffWithRole('Thu ngân');

    $this->actingAs($cashier)->get('/products')->assertOk();
    $this->actingAs($cashier)->post('/products', productPayload())->assertForbidden();
    $this->actingAs($cashier)->get('/inventory')->assertForbidden();
});

test('variant search finds by name, sku and barcode', function () {
    variant(['name' => 'Chuột Logitech'], ['sku' => 'CH-LOGI', 'barcode' => '893111']);
    variant(['name' => 'Bàn phím']);

    $this->actingAs(owner())->getJson('/products/variants/search?q=893111')->assertOk()->assertJsonCount(1)->assertJsonPath('0.sku', 'CH-LOGI');
    $this->actingAs(owner())->getJson('/products/variants/search?q=chuột')->assertJsonCount(1);
});

test('products of another store are not visible', function () {
    $mine = variant(['name' => 'Của tôi']);
    $other = Tenant::factory()->create(['slug' => 'other']);
    $theirs = tenancy()->run($other, fn () => variant(['name' => 'Của cửa hàng khác']));

    $this->actingAs(owner())->get('/products')->assertInertia(fn ($page) => $page->has('products.rows.data', 1));
    $this->actingAs(owner())->get("/products/{$theirs->product_id}/edit")->assertNotFound();
    expect($mine->exists)->toBeTrue();
});
