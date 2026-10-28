<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Support\DataTable\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Brand;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Quản lý kho": stock of every variant (ordinary goods) in the branches the
 * user works in, with Tồn kho, Đang giữ and Có thể bán.
 */
class InventoryController extends Controller
{
    public function index(Request $request): Response|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $allowed = $user->branchIds();
        $showCost = $user->can('products.view_cost');
        $filter = $request->query('filter');
        $selected = is_array($filter) && isset($filter['branch_id']) ? array_map('intval', explode(',', (string) $filter['branch_id'])) : [];
        $branchIds = array_values(array_intersect($selected, $allowed)) ?: $allowed;

        $inBranches = fn () => Inventory::query()
            ->whereColumn('variant_id', 'product_variants.id')
            ->whereIn('branch_id', $branchIds);

        // Variants whose total stock in the branches matches a condition.
        $totals = fn (bool $negative) => Inventory::query()
            ->select('variant_id')
            ->whereIn('branch_id', $branchIds)
            ->groupBy('variant_id')
            ->havingRaw($negative ? 'SUM(on_hand) < 0' : 'SUM(on_hand) > 0');

        $query = ProductVariant::query()
            ->whereNull('base_variant_id')
            ->whereHas('product', fn (Builder $query) => $query->where('type', Product::TYPE_NORMAL))
            ->with('product:id,name,category_id,brand_id,image_url,allow_negative_stock', 'product.category:id,name')
            ->addSelect([
                'on_hand' => $inBranches()->selectRaw('COALESCE(SUM(on_hand), 0)'),
                'reserved' => $inBranches()->selectRaw('COALESCE(SUM(reserved), 0)'),
                'min_quantity' => Inventory::query()->selectRaw('SUM(min_quantity)')->whereColumn('variant_id', 'product_variants.id')->whereIn('branch_id', $branchIds),
            ]);

        $table = DataTable::for($query, $request, 'inventory')
            ->search(['name', 'sku', 'barcode'])
            ->filter('branch_id', fn () => null)
            ->filter('category_id', fn (Builder $query, mixed $value) => $query->whereHas('product', fn (Builder $product) => $product->whereIn('category_id', explode(',', (string) $value))))
            ->filter('brand_id', fn (Builder $query, mixed $value) => $query->whereHas('product', fn (Builder $product) => $product->whereIn('brand_id', explode(',', (string) $value))))
            ->filter('stock', function (Builder $query, mixed $value) use ($totals, $branchIds): void {
                match ($value) {
                    'in_stock' => $query->whereIn('product_variants.id', $totals(false)),
                    'out_of_stock' => $query->whereNotIn('product_variants.id', $totals(false)),
                    'negative' => $query->whereIn('product_variants.id', $totals(true)),
                    // Below the minimum set for that branch.
                    'below_min' => $query->whereExists(fn ($exists) => $exists->from('inventories')
                        ->whereColumn('inventories.variant_id', 'product_variants.id')
                        ->whereIn('inventories.branch_id', $branchIds)
                        ->whereNotNull('inventories.min_quantity')
                        ->whereColumn('inventories.on_hand', '<', 'inventories.min_quantity')),
                    default => null,
                };
            })
            ->sortable($showCost ? ['name', 'sku', 'on_hand', 'reserved', 'cost_price'] : ['name', 'sku', 'on_hand', 'reserved'], 'name');

        if ($request->query('export') === 'xlsx') {
            return $table->export('ton-kho-'.now()->format('Ymd-His'), array_merge([
                'Mã SKU' => fn (ProductVariant $variant) => $variant->sku,
                'Tên phiên bản' => fn (ProductVariant $variant) => $variant->name,
                'Mã vạch' => fn (ProductVariant $variant) => $variant->barcode,
                'Đơn vị' => fn (ProductVariant $variant) => $variant->unit,
                'Loại sản phẩm' => fn (ProductVariant $variant) => $variant->product->category?->name,
                'Tồn kho' => fn (ProductVariant $variant) => (int) $variant->getAttribute('on_hand'),
                'Đang giữ' => fn (ProductVariant $variant) => (int) $variant->getAttribute('reserved'),
                'Có thể bán' => fn (ProductVariant $variant) => (int) $variant->getAttribute('on_hand') - (int) $variant->getAttribute('reserved'),
            ], $showCost ? [
                'Giá vốn' => fn (ProductVariant $variant) => $variant->cost_price,
                'Giá trị tồn' => fn (ProductVariant $variant) => (int) $variant->getAttribute('on_hand') * $variant->cost_price,
            ] : []));
        }

        $totals = Inventory::query()
            ->join('product_variants', 'product_variants.id', '=', 'inventories.variant_id')
            ->whereIn('inventories.branch_id', $branchIds)
            ->selectRaw('COALESCE(SUM(inventories.on_hand), 0) as on_hand, COALESCE(SUM(CASE WHEN inventories.on_hand > 0 THEN inventories.on_hand * product_variants.cost_price ELSE 0 END), 0) as value')
            ->first();

        return Inertia::render('inventory/index', [
            'stock' => $table->paginate(fn (ProductVariant $variant): array => [
                'id' => $variant->id,
                'product_id' => $variant->product_id,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'unit' => $variant->unit,
                'image_url' => $variant->product->image_url,
                'category' => $variant->product->category?->name,
                'on_hand' => (int) $variant->getAttribute('on_hand'),
                'reserved' => (int) $variant->getAttribute('reserved'),
                'available' => (int) $variant->getAttribute('on_hand') - (int) $variant->getAttribute('reserved'),
                'min_quantity' => $variant->getAttribute('min_quantity') === null ? null : (int) $variant->getAttribute('min_quantity'),
                'cost_price' => $showCost ? $variant->cost_price : null,
            ]),
            'totals' => [
                'on_hand' => (int) ($totals?->getAttribute('on_hand') ?? 0),
                'value' => $showCost ? (int) ($totals?->getAttribute('value') ?? 0) : null,
            ],
            'branches' => Branch::query()->whereIn('id', $allowed)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
