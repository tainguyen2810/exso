<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
