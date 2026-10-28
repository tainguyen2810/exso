<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\DataTable\DataTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Product\Models\Brand;
use Modules\Product\Models\Category;

/**
 * "Loại sản phẩm" and "Nhãn hiệu": name-only lists edited in a dialog.
 */
class CatalogListController extends Controller
{
    /** @var array<string, array{model: class-string<Category|Brand>, title: string, label: string}> */
    protected const LISTS = [
        'categories' => ['model' => Category::class, 'title' => 'Loại sản phẩm', 'label' => 'loại sản phẩm'],
        'brands' => ['model' => Brand::class, 'title' => 'Nhãn hiệu', 'label' => 'nhãn hiệu'],
    ];

    public function index(Request $request, string $list): Response
    {
        $definition = $this->definition($list);

        $row = fn (Category|Brand $item): array => [
            'id' => $item->id,
            'name' => $item->name,
            'products_count' => (int) $item->products_count,
            'created_at' => $item->created_at?->toIso8601String(),
        ];

        $items = $list === 'categories'
            ? DataTable::for(Category::query()->withCount('products'), $request, 'product-categories')
                ->search(['name'])
                ->sortable(['name', 'products_count', 'created_at'], 'name')
                ->paginate($row)
            : DataTable::for(Brand::query()->withCount('products'), $request, 'product-brands')
                ->search(['name'])
                ->sortable(['name', 'products_count', 'created_at'], 'name')
                ->paginate($row);

        return Inertia::render('products/catalog-list', [
            'list' => $list,
            'title' => $definition['title'],
            'items' => $items,
        ]);
    }

    public function store(Request $request, string $list): RedirectResponse
    {
        $definition = $this->definition($list);
        $item = $definition['model']::query()->create($this->validated($request, $list));

        $this->toast("Đã thêm {$definition['label']} {$item->name}.");

        return back();
    }

    public function update(Request $request, string $list, int $id): RedirectResponse
    {
        $definition = $this->definition($list);
        $item = $definition['model']::query()->findOrFail($id);
        $item->update($this->validated($request, $list, $item));

        $this->toast("Đã lưu {$definition['label']} {$item->name}.");

        return back();
    }

    public function destroy(string $list, int $id): RedirectResponse
    {
        $definition = $this->definition($list);
        $item = $definition['model']::query()->findOrFail($id);

        // Products keep working without a category/brand.
        $item->products()->update([$list === 'categories' ? 'category_id' : 'brand_id' => null]);
        $item->delete();

        $this->toast("Đã xóa {$definition['label']} {$item->name}.");

        return back();
    }

    /**
     * @return array{model: class-string<Category|Brand>, title: string, label: string}
     */
    protected function definition(string $list): array
    {
        abort_unless(isset(self::LISTS[$list]), 404);

        return self::LISTS[$list];
    }

    /**
     * @return array{name: string}
     */
    protected function validated(Request $request, string $list, ?Model $item = null): array
    {
        /** @var array{name: string} */
        return $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique($list === 'categories' ? 'product_categories' : 'brands')
                    ->where('tenant_id', tenancy()->id())
                    ->whereNull('deleted_at')
                    ->ignore($item),
            ],
        ], [], ['name' => 'tên']);
    }
}
