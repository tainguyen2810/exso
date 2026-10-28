<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Support\Branches\CurrentBranch;
use App\Support\DataTable\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Brand;
use Modules\Product\Models\Category;
use Modules\Product\Models\PriceList;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Services\ProductExcel;
use Modules\Product\Services\ProductService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @phpstan-import-type ProductData from ProductService
 */
class ProductController extends Controller
{
    public function __construct(protected ProductService $products) {}

    public function index(Request $request): Response|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $branchIds = $user->branchIds();

        $baseVariants = fn () => ProductVariant::query()->select('id')->whereColumn('product_id', 'products.id')->whereNull('base_variant_id');

        $query = Product::query()
            ->with(['category:id,name', 'brand:id,name', 'variants' => fn ($query) => $query->whereNull('base_variant_id')->select('id', 'product_id', 'sku', 'retail_price')])
            ->withCount(['variants' => fn ($query) => $query->whereNull('base_variant_id')])
            ->addSelect([
                'on_hand' => Inventory::query()->selectRaw('COALESCE(SUM(on_hand), 0)')->whereIn('variant_id', $baseVariants())->whereIn('branch_id', $branchIds),
                'available' => Inventory::query()->selectRaw('COALESCE(SUM(on_hand - reserved), 0)')->whereIn('variant_id', $baseVariants())->whereIn('branch_id', $branchIds),
            ]);

        $table = DataTable::for($query, $request, 'products')
            ->search(['name', 'variants.sku', 'variants.barcode'])
            ->filterExact('type')
            ->filterExact('category_id')
            ->filterExact('brand_id')
            ->filter('is_sellable', fn (Builder $query, mixed $value) => $query->where('is_sellable', $value === '1'))
            ->filter('stock', function (Builder $query, mixed $value) use ($branchIds): void {
                // Products whose total stock in the user's branches matches a condition.
                $totals = fn (bool $negative) => Inventory::query()
                    ->join('product_variants', 'product_variants.id', '=', 'inventories.variant_id')
                    ->whereIn('inventories.branch_id', $branchIds)
                    ->groupBy('product_variants.product_id')
                    ->havingRaw($negative ? 'SUM(inventories.on_hand) < 0' : 'SUM(inventories.on_hand) > 0')
                    ->select('product_variants.product_id');
                $query->where('type', Product::TYPE_NORMAL);

                match ($value) {
                    'in_stock' => $query->whereIn('products.id', $totals(false)),
                    'out_of_stock' => $query->whereNotIn('products.id', $totals(false)),
                    'negative' => $query->whereIn('products.id', $totals(true)),
                    default => null,
                };
            })
            ->filterDateRange('created_at')
            ->sortable(['name', 'on_hand', 'available', 'created_at']);

        if ($request->query('export') === 'xlsx') {
            return ProductExcel::export($table->query(), $branchIds, $user->can('products.view_cost'));
        }

        return Inertia::render('products/index', [
            'products' => $table->paginate(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'type' => $product->type,
                'image_url' => $product->image_url,
                'category' => $product->category?->name,
                'brand' => $product->brand?->name,
                'sku' => $product->variants->first()?->sku,
                'price' => $product->variants->first()?->retail_price,
                'variants_count' => (int) $product->getAttribute('variants_count'),
                'on_hand' => $product->tracksInventory() ? (int) $product->getAttribute('on_hand') : null,
                'available' => $product->tracksInventory() ? (int) $product->getAttribute('available') : null,
                'is_sellable' => $product->is_sellable,
                'created_at' => $product->created_at?->toIso8601String(),
            ]),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'types' => self::typeOptions(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('products/form', [
            'product' => null,
            ...$this->formOptions($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $product = $this->products->save(null, $this->validated($request, null));

        $this->toast("Đã thêm sản phẩm {$product->name}.");

        return $request->boolean('stay')
            ? to_route('products.create')
            : to_route('products.edit', $product);
    }

    public function edit(Request $request, Product $product): Response
    {
        /** @var User $user */
        $user = $request->user();
        $product->load(['category', 'brand', 'variants.prices', 'comboItems.variant']);
        $branchIds = $user->branchIds();
        $showCost = $user->can('products.view_cost');

        $stock = Inventory::query()
            ->with('branch:id,name')
            ->whereIn('variant_id', $product->variants->pluck('id'))
            ->whereIn('branch_id', $branchIds)
            ->get();

        $bases = $product->variants->whereNull('base_variant_id')->values();

        return Inertia::render('products/form', [
            'product' => [
                'id' => $product->id,
                'type' => $product->type,
                'name' => $product->name,
                'category' => $product->category?->name,
                'brand' => $product->brand?->name,
                'description' => $product->description,
                'tags' => $product->tags,
                'unit' => $product->unit,
                'image_url' => $product->image_url,
                'is_sellable' => $product->is_sellable,
                'allow_negative_stock' => $product->allow_negative_stock,
                'options' => $product->optionNames(),
                'variants' => $bases->map(fn (ProductVariant $variant): array => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'options' => array_slice([$variant->option1, $variant->option2, $variant->option3], 0, count($product->optionNames())),
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'retail_price' => $variant->retail_price,
                    'wholesale_price' => $variant->wholesale_price,
                    'import_price' => $showCost ? $variant->import_price : null,
                    'cost_price' => $showCost ? $variant->cost_price : null,
                    'weight' => $variant->weight,
                    'prices' => $variant->prices->mapWithKeys(fn ($price) => [(string) $price->price_list_id => $price->price])->all(),
                    'stock' => $stock->where('variant_id', $variant->id)->values()->map(fn (Inventory $inventory): array => [
                        'branch' => $inventory->branch->name,
                        'on_hand' => $inventory->on_hand,
                        'available' => $inventory->available(),
                    ])->all(),
                ])->all(),
                'units' => $product->variants->whereNotNull('base_variant_id')->values()->map(fn (ProductVariant $variant): array => [
                    'id' => $variant->id,
                    'unit' => (string) $variant->unit,
                    'ratio' => $variant->unit_ratio,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'retail_price' => $variant->retail_price,
                    'wholesale_price' => $variant->wholesale_price,
                ])->all(),
                'combo_items' => $product->comboItems->map(fn ($item): array => [
                    'variant_id' => $item->variant_id,
                    'name' => $item->variant->name,
                    'sku' => $item->variant->sku,
                    'retail_price' => $item->variant->retail_price,
                    'quantity' => $item->quantity,
                ])->all(),
                'created_at' => $product->created_at?->toIso8601String(),
            ],
            ...$this->formOptions($request),
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->products->save($product, $this->validated($request, $product));

        $this->toast("Đã lưu sản phẩm {$product->name}.");

        return to_route('products.edit', $product);
    }

    public function destroy(Product $product): RedirectResponse
    {
        try {
            $this->products->delete($product);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return back();
        }

        $this->toast("Đã xóa sản phẩm {$product->name}.");

        return to_route('products.index');
    }

    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['sellable', 'unsellable', 'delete'])],
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        if ($data['action'] === 'delete') {
            Gate::authorize('products.delete');
        }

        $products = Product::query()->whereKey($data['ids'])->get();
        $done = 0;
        $skipped = [];

        foreach ($products as $product) {
            if ($data['action'] === 'delete') {
                try {
                    $this->products->delete($product);
                    $done++;
                } catch (ValidationException) {
                    $skipped[] = $product->name;
                }

                continue;
            }

            $product->update(['is_sellable' => $data['action'] === 'sellable']);
            $done++;
        }

        $message = "Đã cập nhật {$done} sản phẩm.";

        if ($skipped !== []) {
            $message .= ' Bỏ qua '.count($skipped).' sản phẩm còn tồn kho.';
        }

        $this->toast($message, $skipped === [] ? 'success' : 'warning');

        return back();
    }

    /**
     * Variant lookup for combo components, barcode labels and (later) orders.
     */
    public function searchVariants(Request $request): JsonResponse
    {
        $term = mb_substr($request->string('q')->trim()->toString(), 0, 100);
        $types = array_values(array_intersect((array) $request->query('types', []), array_keys(Product::TYPES)));

        $variants = ProductVariant::query()
            ->with('product:id,name,type')
            ->whereHas('product', fn (Builder $query) => $types === [] ? $query : $query->whereIn('type', $types))
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', $term)))
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json($variants->map(fn (ProductVariant $variant): array => [
            'id' => $variant->id,
            'product_id' => $variant->product_id,
            'name' => $variant->name,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'unit' => $variant->unit,
            'retail_price' => $variant->retail_price,
            'type' => $variant->product->type,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function formOptions(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        return [
            'categories' => Category::query()->orderBy('name')->pluck('name'),
            'brands' => Brand::query()->orderBy('name')->pluck('name'),
            'priceLists' => PriceList::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::query()->whereIn('id', $user->branchIds())->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'currentBranchId' => app(CurrentBranch::class)->id($user),
            'types' => self::typeOptions(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function typeOptions(): array
    {
        return array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys(Product::TYPES), Product::TYPES);
    }

    /**
     * @return ProductData
     */
    protected function validated(Request $request, ?Product $product): array
    {
        /** @var User $user */
        $user = $request->user();
        $money = ['nullable', 'integer', 'min:0', 'max:999999999999'];

        $data = $request->validate([
            'type' => [$product === null ? 'required' : 'nullable', Rule::in(array_keys(Product::TYPES))],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'tags' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:30'],
            'image_url' => ['nullable', 'url', 'max:500'],
            'is_sellable' => ['boolean'],
            'allow_negative_stock' => ['boolean'],
            'options' => ['array', 'max:3'],
            'options.*' => ['required', 'string', 'max:50', 'distinct'],
            'variants' => ['required', 'array', 'min:1', 'max:200'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.options' => ['array'],
            'variants.*.options.*' => ['nullable', 'string', 'max:100'],
            'variants.*.sku' => ['nullable', 'string', 'max:50'],
            'variants.*.barcode' => ['nullable', 'string', 'max:50'],
            'variants.*.retail_price' => $money,
            'variants.*.wholesale_price' => $money,
            'variants.*.import_price' => $money,
            'variants.*.weight' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'variants.*.prices' => ['array'],
            'variants.*.prices.*' => $money,
            'variants.*.opening_quantity' => ['nullable', 'integer', 'min:-1000000000', 'max:1000000000'],
            'variants.*.opening_cost' => $money,
            'units' => ['array', 'max:10'],
            'units.*.id' => ['nullable', 'integer'],
            'units.*.unit' => ['required', 'string', 'max:30'],
            'units.*.ratio' => ['required', 'integer', 'min:2', 'max:1000000'],
            'units.*.sku' => ['nullable', 'string', 'max:50'],
            'units.*.barcode' => ['nullable', 'string', 'max:50'],
            'units.*.retail_price' => $money,
            'units.*.wholesale_price' => $money,
            'combo_items' => ['array', 'max:50'],
            'combo_items.*.variant_id' => ['required', 'integer'],
            'combo_items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'opening_branch_id' => ['nullable', 'integer', Rule::in($user->branchIds())],
        ], [], [
            'name' => 'tên sản phẩm',
            'options.*' => 'tên thuộc tính',
            'variants.*.sku' => 'mã SKU',
            'variants.*.barcode' => 'mã vạch',
            'units.*.unit' => 'đơn vị quy đổi',
            'units.*.ratio' => 'tỷ lệ quy đổi',
            'combo_items.*.quantity' => 'số lượng thành phần',
        ]);

        $type = $product === null ? $data['type'] : $product->type;
        $options = $type === Product::TYPE_NORMAL ? array_values($data['options'] ?? []) : [];
        $variants = $type === Product::TYPE_NORMAL && $options !== [] ? $data['variants'] : [$data['variants'][0]];
        $units = $type === Product::TYPE_NORMAL && $options === [] ? array_values($data['units'] ?? []) : [];

        $this->checkVariants($product, $options, $variants, $units);

        $ownIds = $product === null ? [] : $product->variants()->pluck('id')->all();
        // Without the cost permission the purchase price is neither shown nor
        // changed: existing variants keep theirs.
        $showCost = $user->can('products.view_cost');
        $importPrices = $product === null ? [] : $product->variants()->pluck('import_price', 'id')->all();

        return [
            'type' => $type,
            'name' => $data['name'],
            'category_id' => $this->lookup(Category::class, $data['category'] ?? null),
            'brand_id' => $this->lookup(Brand::class, $data['brand'] ?? null),
            'description' => $data['description'] ?? null,
            'tags' => $data['tags'] ?? null,
            'unit' => $data['unit'] ?? null,
            'image_url' => $data['image_url'] ?? null,
            'is_sellable' => (bool) ($data['is_sellable'] ?? true),
            'allow_negative_stock' => (bool) ($data['allow_negative_stock'] ?? false),
            'options' => $options,
            'variants' => array_map(fn (array $variant): array => [
                'id' => $id = isset($variant['id']) && in_array((int) $variant['id'], $ownIds, true) ? (int) $variant['id'] : null,
                'options' => array_values($variant['options'] ?? []),
                'sku' => $variant['sku'] ?? null,
                'barcode' => $variant['barcode'] ?? null,
                'retail_price' => (int) ($variant['retail_price'] ?? 0),
                'wholesale_price' => (int) ($variant['wholesale_price'] ?? 0),
                'import_price' => $showCost ? (int) ($variant['import_price'] ?? 0) : (int) ($importPrices[$id] ?? 0),
                'weight' => (int) ($variant['weight'] ?? 0),
                'prices' => $this->prices($variant['prices'] ?? []),
                'opening_quantity' => (int) ($variant['opening_quantity'] ?? 0),
                'opening_cost' => $showCost && isset($variant['opening_cost']) ? (int) $variant['opening_cost'] : null,
            ], array_values($variants)),
            'units' => array_map(fn (array $unit): array => [
                'id' => isset($unit['id']) && in_array((int) $unit['id'], $ownIds, true) ? (int) $unit['id'] : null,
                'unit' => $unit['unit'],
                'ratio' => (int) $unit['ratio'],
                'sku' => $unit['sku'] ?? null,
                'barcode' => $unit['barcode'] ?? null,
                'retail_price' => (int) ($unit['retail_price'] ?? 0),
                'wholesale_price' => (int) ($unit['wholesale_price'] ?? 0),
            ], $units),
            'combo_items' => array_map(fn (array $item): array => ['variant_id' => (int) $item['variant_id'], 'quantity' => (int) $item['quantity']], array_values($data['combo_items'] ?? [])),
            'opening_branch_id' => $product === null && $type === Product::TYPE_NORMAL
                ? (int) ($data['opening_branch_id'] ?? app(CurrentBranch::class)->id($user))
                : null,
        ];
    }

    /**
     * SKU and barcode: unique in the form and among all other variants
     * (deleted ones included, so old codes are never reused).
     *
     * @param  list<string>  $options
     * @param  array<int, array<string, mixed>>  $variants
     * @param  list<array<string, mixed>>  $units
     */
    protected function checkVariants(?Product $product, array $options, array $variants, array $units): void
    {
        $errors = [];
        $ownIds = $product === null ? [] : $product->variants()->withTrashed()->pluck('id')->all();

        foreach (['sku' => 'Mã SKU', 'barcode' => 'Mã vạch'] as $field => $label) {
            $codes = [];

            foreach ([...array_values($variants), ...$units] as $row) {
                $code = trim((string) ($row[$field] ?? ''));

                if ($code === '') {
                    continue;
                }

                if (isset($codes[mb_strtolower($code)])) {
                    $errors[$field][] = "{$label} {$code} bị trùng trong sản phẩm.";
                }

                $codes[mb_strtolower($code)] = $code;
            }

            if ($codes !== []) {
                $taken = ProductVariant::query()->withTrashed()
                    ->whereIn($field, array_values($codes))
                    ->whereNotIn('id', $ownIds)
                    ->pluck($field);

                foreach ($taken as $code) {
                    $errors[$field][] = "{$label} {$code} đã được dùng cho sản phẩm khác.";
                }
            }
        }

        if ($options !== []) {
            $combinations = [];

            foreach ($variants as $row) {
                $values = array_map(fn (mixed $value): string => trim((string) $value), array_slice(array_pad((array) ($row['options'] ?? []), count($options), ''), 0, count($options)));

                if (in_array('', $values, true)) {
                    $errors['variants'][] = 'Mỗi phiên bản cần đủ giá trị cho các thuộc tính.';

                    break;
                }

                $key = mb_strtolower(implode('|', $values));

                if (isset($combinations[$key])) {
                    $errors['variants'][] = 'Phiên bản '.implode(' / ', $values).' bị trùng.';
                }

                $combinations[$key] = true;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(fn (array $messages): string => implode(' ', array_unique($messages)), $errors));
        }
    }

    /**
     * Find a category/brand by name, creating it when new.
     *
     * @param  class-string<Category|Brand>  $model
     */
    protected function lookup(string $model, ?string $name): ?int
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        return $model::query()->firstOrCreate(['name' => $name])->id;
    }

    /**
     * @param  array<int|string, mixed>  $prices
     * @return array<int, int|null>
     */
    protected function prices(array $prices): array
    {
        $ids = PriceList::query()->whereKey(array_keys($prices))->pluck('id')->all();
        $result = [];

        foreach ($prices as $id => $price) {
            if (in_array((int) $id, $ids, true)) {
                $result[(int) $id] = $price === null || $price === '' ? null : (int) $price;
            }
        }

        return $result;
    }
}
