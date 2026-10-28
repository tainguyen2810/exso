<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\InventoryService;
use Modules\Product\Models\ComboItem;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\VariantPrice;

/**
 * Creates and updates a product with its variants, conversion units, combo
 * components, extra prices and opening stock in one transaction. Used by the
 * product form and the Excel import.
 *
 * @phpstan-type VariantData array{id?: int|null, options?: list<string|null>, sku?: string|null, barcode?: string|null, retail_price?: int, wholesale_price?: int, import_price?: int, weight?: int, prices?: array<int, int|null>, opening_quantity?: int, opening_cost?: int|null}
 * @phpstan-type UnitData array{id?: int|null, unit: string, ratio: int, sku?: string|null, barcode?: string|null, retail_price?: int, wholesale_price?: int}
 * @phpstan-type ProductData array{type?: string, name: string, category_id?: int|null, brand_id?: int|null, description?: string|null, tags?: string|null, unit?: string|null, image_url?: string|null, is_sellable?: bool, allow_negative_stock?: bool, options?: list<string>, variants: list<VariantData>, units?: list<UnitData>, combo_items?: list<array{variant_id: int, quantity: int}>, opening_branch_id?: int|null}
 */
class ProductService
{
    public function __construct(protected InventoryService $inventory) {}

    /**
     * @param  ProductData  $data
     */
    public function save(?Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data): Product {
            $creating = $product === null;
            $product ??= new Product(['type' => $data['type'] ?? Product::TYPE_NORMAL, 'created_by' => auth()->id()]);

            $options = array_values(array_filter(array_slice($data['options'] ?? [], 0, 3), fn (string $name): bool => trim($name) !== ''));

            $product->fill([
                'name' => $data['name'],
                'category_id' => $data['category_id'] ?? null,
                'brand_id' => $data['brand_id'] ?? null,
                'description' => $data['description'] ?? null,
                'tags' => $data['tags'] ?? null,
                'unit' => $data['unit'] ?? null,
                'image_url' => $data['image_url'] ?? $product->image_url,
                'is_sellable' => $data['is_sellable'] ?? true,
                'allow_negative_stock' => $product->type === Product::TYPE_NORMAL && ($data['allow_negative_stock'] ?? false),
                'option1' => $options[0] ?? null,
                'option2' => $options[1] ?? null,
                'option3' => $options[2] ?? null,
            ])->save();

            $openings = $this->saveVariants($product, $data['variants'], count($options));

            // Units and combo components are left as they are when not given (Excel import).
            $units = $data['units'] ?? null;
            $comboItems = $data['combo_items'] ?? null;

            if ($product->type !== Product::TYPE_NORMAL) {
                $this->saveUnits($product, []);
            } elseif ($units !== null) {
                $this->saveUnits($product, $units);
            }

            if ($product->type === Product::TYPE_COMBO && ($creating || $comboItems !== null)) {
                $this->saveComboItems($product, $comboItems ?? []);
            }

            $branchId = $data['opening_branch_id'] ?? null;

            if ($product->tracksInventory() && $branchId !== null) {
                foreach ($openings as [$variant, $quantity, $cost]) {
                    $this->inventory->move($branchId, $variant, $quantity, StockMovement::OPENING, [
                        'unit_cost' => $cost,
                        'update_cost' => true,
                        'reference' => $product,
                        'note' => $creating ? 'Khởi tạo kho khi tạo sản phẩm' : 'Khởi tạo kho',
                        'allow_negative' => true,
                    ]);
                }
            }

            return $product->refresh();
        });
    }

    /**
     * Delete a product (soft). Refused while any of its variants has stock.
     */
    public function delete(Product $product): void
    {
        $variantIds = $product->variants()->pluck('id');

        if (Inventory::query()->whereIn('variant_id', $variantIds)->where('on_hand', '<>', 0)->exists()) {
            throw ValidationException::withMessages(['product' => "Sản phẩm {$product->name} còn tồn kho, không xóa được. Hãy kiểm hàng về 0 trước."]);
        }

        DB::transaction(function () use ($product): void {
            $product->variants()->get()->each(fn (ProductVariant $variant) => $variant->delete());
            $product->delete();
        });
    }

    /**
     * @param  list<VariantData>  $rows
     * @return list<array{0: ProductVariant, 1: int, 2: int}> new variants with opening stock
     */
    protected function saveVariants(Product $product, array $rows, int $optionCount): array
    {
        if ($rows === []) {
            throw ValidationException::withMessages(['variants' => 'Sản phẩm cần ít nhất một phiên bản.']);
        }

        if ($product->type !== Product::TYPE_NORMAL || $optionCount === 0) {
            $rows = [$rows[0]];
        }

        $existing = $product->variants()->whereNull('base_variant_id')->get()->keyBy('id');
        $keep = [];
        $openings = [];

        foreach ($rows as $row) {
            $values = array_slice(array_pad($row['options'] ?? [], 3, null), 0, 3);

            for ($i = $optionCount; $i < 3; $i++) {
                $values[$i] = null;
            }

            $variant = isset($row['id']) && $existing->has($row['id']) ? $existing->get($row['id']) : new ProductVariant(['product_id' => $product->id]);
            $isNew = ! $variant->exists;

            $variant->fill([
                'name' => $this->variantName($product->name, array_filter($values, fn (?string $value): bool => $value !== null && $value !== '')),
                'barcode' => $this->blankToNull($row['barcode'] ?? null),
                'option1' => $values[0],
                'option2' => $values[1],
                'option3' => $values[2],
                'unit' => $product->unit,
                'retail_price' => $row['retail_price'] ?? 0,
                'wholesale_price' => $row['wholesale_price'] ?? 0,
                'import_price' => $row['import_price'] ?? 0,
                'weight' => $row['weight'] ?? 0,
            ]);

            $this->assignSku($variant, $row['sku'] ?? null);

            if ($isNew) {
                $variant->cost_price = $row['opening_cost'] ?? $row['import_price'] ?? 0;
            }

            $variant->save();
            $keep[] = $variant->id;

            $this->savePrices($variant, $row['prices'] ?? null);

            if ($isNew && ($row['opening_quantity'] ?? 0) !== 0) {
                $openings[] = [$variant, (int) $row['opening_quantity'], (int) ($row['opening_cost'] ?? $variant->import_price)];
            }
        }

        foreach ($existing->except($keep) as $variant) {
            $this->deleteVariant($variant);
        }

        return $openings;
    }

    /**
     * Conversion units hang on the first (only) base variant.
     *
     * @param  list<UnitData>  $rows
     */
    protected function saveUnits(Product $product, array $rows): void
    {
        $bases = $product->variants()->whereNull('base_variant_id')->get();
        $existing = $product->variants()->whereNotNull('base_variant_id')->get()->keyBy('id');
        $keep = [];

        if ($bases->count() === 1) {
            /** @var ProductVariant $base */
            $base = $bases->first();

            foreach ($rows as $row) {
                $variant = isset($row['id']) && $existing->has($row['id']) ? $existing->get($row['id']) : new ProductVariant(['product_id' => $product->id]);

                $variant->fill([
                    'name' => "{$product->name} ({$row['unit']})",
                    'barcode' => $this->blankToNull($row['barcode'] ?? null),
                    'unit' => $row['unit'],
                    'base_variant_id' => $base->id,
                    'unit_ratio' => max(1, $row['ratio']),
                    'retail_price' => $row['retail_price'] ?? 0,
                    'wholesale_price' => $row['wholesale_price'] ?? 0,
                    'import_price' => $base->import_price * max(1, $row['ratio']),
                    'weight' => $base->weight * max(1, $row['ratio']),
                ]);
                $variant->cost_price = $base->cost_price * $variant->unit_ratio;

                $this->assignSku($variant, $row['sku'] ?? null);
                $variant->save();
                $keep[] = $variant->id;
            }
        }

        foreach ($existing->except($keep) as $variant) {
            $variant->delete();
        }
    }

    /**
     * @param  list<array{variant_id: int, quantity: int}>  $items
     */
    protected function saveComboItems(Product $product, array $items): void
    {
        if ($items === []) {
            throw ValidationException::withMessages(['combo_items' => 'Combo cần ít nhất một sản phẩm thành phần.']);
        }

        $product->comboItems()->delete();

        foreach ($items as $item) {
            $component = ProductVariant::query()->with('product')->find($item['variant_id']);

            if ($component === null || $component->product->type === Product::TYPE_COMBO) {
                throw ValidationException::withMessages(['combo_items' => 'Thành phần combo phải là sản phẩm thường hoặc dịch vụ.']);
            }

            ComboItem::query()->create([
                'product_id' => $product->id,
                'variant_id' => $component->id,
                'quantity' => max(1, $item['quantity']),
            ]);
        }
    }

    /**
     * @param  array<int, int|null>|null  $prices  price_list_id => price (null removes)
     */
    protected function savePrices(ProductVariant $variant, ?array $prices): void
    {
        foreach ($prices ?? [] as $priceListId => $price) {
            if ($price === null) {
                VariantPrice::query()->where('variant_id', $variant->id)->where('price_list_id', $priceListId)->delete();

                continue;
            }

            VariantPrice::query()->updateOrCreate(
                ['variant_id' => $variant->id, 'price_list_id' => $priceListId],
                ['price' => $price],
            );
        }
    }

    protected function deleteVariant(ProductVariant $variant): void
    {
        if (Inventory::query()->where('variant_id', $variant->id)->where('on_hand', '<>', 0)->exists()) {
            throw ValidationException::withMessages(['variants' => "Phiên bản {$variant->sku} còn tồn kho, không xóa được."]);
        }

        $variant->units()->get()->each(fn (ProductVariant $unit) => $unit->delete());
        $variant->delete();
    }

    /**
     * Use the given SKU, or generate PVN00001-style codes like Sapo.
     */
    protected function assignSku(ProductVariant $variant, ?string $sku): void
    {
        $sku = $this->blankToNull($sku);

        if ($sku !== null) {
            $variant->sku = $sku;

            return;
        }

        if ($variant->exists && $variant->sku !== '') {
            return;
        }

        $next = (int) ProductVariant::query()->withTrashed()->max('id') + 1;

        do {
            $candidate = 'PVN'.str_pad((string) $next++, 5, '0', STR_PAD_LEFT);
        } while (ProductVariant::query()->withTrashed()->where('sku', $candidate)->exists());

        $variant->sku = $candidate;
    }

    /**
     * @param  array<int, string|null>  $values
     */
    protected function variantName(string $productName, array $values): string
    {
        return $values === [] ? $productName : $productName.' - '.implode(' / ', $values);
    }

    protected function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
