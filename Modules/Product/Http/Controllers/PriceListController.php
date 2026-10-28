<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\DataTable\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Product\Models\PriceList;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\VariantPrice;

/**
 * "Chính sách giá": the built-in retail / wholesale / import prices plus
 * custom lists with a price per variant.
 */
class PriceListController extends Controller
{
    public function index(Request $request): Response
    {
        $table = DataTable::for(PriceList::query()->withCount('prices'), $request, 'price-lists')
            ->search(['name', 'code'])
            ->filterExact('status')
            ->sortable(['code', 'name', 'prices_count', 'created_at'], 'name');

        return Inertia::render('products/price-lists/index', [
            'priceLists' => $table->paginate(fn (PriceList $list): array => [
                'id' => $list->id,
                'code' => $list->code,
                'name' => $list->name,
                'status' => $list->status,
                'prices_count' => (int) $list->prices_count,
            ]),
            'builtIn' => array_map(fn (string $key, string $name): array => ['key' => $key, 'name' => $name], array_keys(PriceList::BUILT_IN), PriceList::BUILT_IN),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $list = PriceList::query()->create($this->validated($request));

        $this->toast("Đã thêm chính sách giá {$list->name}.");

        return to_route('price-lists.show', $list);
    }

    public function update(Request $request, PriceList $priceList): RedirectResponse
    {
        $priceList->update($this->validated($request, $priceList));

        $this->toast("Đã lưu chính sách giá {$priceList->name}.");

        return back();
    }

    public function destroy(PriceList $priceList): RedirectResponse
    {
        $priceList->prices()->delete();
        $priceList->delete();

        $this->toast("Đã xóa chính sách giá {$priceList->name}.");

        return to_route('price-lists.index');
    }

    /**
     * Variants with their price in this list, editable row by row.
     */
    public function show(Request $request, PriceList $priceList): Response
    {
        $query = ProductVariant::query()
            ->whereHas('product')
            ->addSelect([
                'list_price' => VariantPrice::query()->select('price')->whereColumn('variant_id', 'product_variants.id')->where('price_list_id', $priceList->id)->limit(1),
            ]);

        $table = DataTable::for($query, $request, 'price-list-items')
            ->search(['name', 'sku', 'barcode'])
            ->filter('priced', fn (Builder $query, mixed $value) => $value === '1'
                ? $query->whereHas('prices', fn (Builder $prices) => $prices->where('price_list_id', $priceList->id))
                : $query->whereDoesntHave('prices', fn (Builder $prices) => $prices->where('price_list_id', $priceList->id)))
            ->sortable(['name', 'sku', 'retail_price', 'list_price'], 'name');

        return Inertia::render('products/price-lists/show', [
            'priceList' => $priceList->only('id', 'code', 'name', 'status'),
            'variants' => $table->paginate(fn (ProductVariant $variant): array => [
                'id' => $variant->id,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'unit' => $variant->unit,
                'retail_price' => $variant->retail_price,
                'wholesale_price' => $variant->wholesale_price,
                'import_price' => $request->user()?->can('products.view_cost') ? $variant->import_price : null,
                'list_price' => $variant->getAttribute('list_price') === null ? null : (int) $variant->getAttribute('list_price'),
            ]),
        ]);
    }

    /**
     * Set (or clear with null) the price of one variant.
     */
    public function setPrice(Request $request, PriceList $priceList): RedirectResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->where('tenant_id', tenancy()->id())],
            'price' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ], [], ['price' => 'giá']);

        if ($data['price'] === null) {
            VariantPrice::query()->where('price_list_id', $priceList->id)->where('variant_id', $data['variant_id'])->delete();
        } else {
            VariantPrice::query()->updateOrCreate(
                ['price_list_id' => $priceList->id, 'variant_id' => $data['variant_id']],
                ['price' => $data['price']],
            );
        }

        return back();
    }

    /**
     * @return array{code: string, name: string, status: string}
     */
    protected function validated(Request $request, ?PriceList $priceList = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('price_lists')->where('tenant_id', tenancy()->id())->ignore($priceList)],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ], [], ['code' => 'mã', 'name' => 'tên chính sách giá']);

        $data['code'] = strtoupper($data['code']);

        /** @var array{code: string, name: string, status: string} $data */
        return $data;
    }
}
