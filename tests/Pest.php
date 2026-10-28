<?php

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Customer\Models\Customer;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\InventoryService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests get a fresh database and a default store (tenant).
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * A store owner working in a default branch.
 */
function owner(): User
{
    $branch = Branch::query()->firstOrCreate(['code' => 'CN01'], ['name' => 'Chi nhánh chính', 'is_default' => true]);

    $user = User::factory()->owner()->create();
    $user->branches()->attach($branch);

    return $user;
}

/**
 * A staff member with one of the default roles ("Thu ngân", "Nhân viên kho", ...).
 */
function staffWithRole(string $role, ?Branch $branch = null): User
{
    $branch ??= Branch::query()->firstOrCreate(['code' => 'CN01'], ['name' => 'Chi nhánh chính', 'is_default' => true]);

    $user = User::factory()->create();
    $user->branches()->attach($branch);
    $user->assignRole($role);

    return $user;
}

/**
 * A product with one variant (ordinary goods unless type says otherwise).
 *
 * @param  array<string, mixed>  $product
 * @param  array<string, mixed>  $variant
 */
function variant(array $product = [], array $variant = []): ProductVariant
{
    $model = Product::query()->create(['name' => 'Chuột không dây', 'type' => Product::TYPE_NORMAL, ...$product]);

    return ProductVariant::query()->create([
        'product_id' => $model->id,
        'name' => $model->name,
        'sku' => 'SKU'.$model->id.'-'.fake()->unique()->numberBetween(1, 999999),
        'retail_price' => 200000,
        ...$variant,
    ]);
}

function mainBranch(): Branch
{
    return Branch::query()->firstOrCreate(['code' => 'CN01'], ['name' => 'Chi nhánh chính', 'is_default' => true]);
}

/*
| Sales helpers (orders, POS, returns).
*/

function stockRow(ProductVariant $variant): Inventory
{
    return Inventory::query()->where('variant_id', $variant->id)->where('branch_id', mainBranch()->id)->firstOrFail();
}

function cash(): PaymentMethod
{
    return PaymentMethod::query()->where('type', 'cash')->firstOrFail();
}

function stocked(int $quantity, int $cost, array $product = [], array $variant = []): ProductVariant
{
    $item = variant($product, $variant);
    app(InventoryService::class)->move(mainBranch()->id, $item, $quantity, StockMovement::OPENING, ['unit_cost' => $cost, 'update_cost' => true]);

    return $item->refresh();
}

function customer(array $attributes = []): Customer
{
    return Customer::query()->create(['code' => 'CUZN'.fake()->unique()->numberBetween(1000, 99999), 'name' => 'Nguyễn Văn An', 'status' => 'active', ...$attributes]);
}

/**
 * @param  list<array{0: ProductVariant, 1: int}>  $lines
 */
function orderData(array $lines, array $overrides = []): array
{
    return [
        'branch_id' => mainBranch()->id,
        'items' => array_map(fn (array $line): array => ['variant_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
        ...$overrides,
    ];
}
