<?php

namespace Modules\Product\Models;

use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A sellable SKU. A conversion unit ("Thùng 24") is a variant whose stock is
 * counted on its base variant: selling 1 deducts unit_ratio base units.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property string $name
 * @property string $sku
 * @property string|null $barcode
 * @property string|null $option1
 * @property string|null $option2
 * @property string|null $option3
 * @property string|null $unit
 * @property int|null $base_variant_id
 * @property int $unit_ratio
 * @property int $retail_price
 * @property int $wholesale_price
 * @property int $import_price
 * @property int $cost_price
 * @property int $weight
 * @property-read Product $product
 * @property-read ProductVariant|null $baseVariant
 * @property-read Collection<int, ProductVariant> $units
 * @property-read Collection<int, VariantPrice> $prices
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable(['product_id', 'name', 'sku', 'barcode', 'option1', 'option2', 'option3', 'unit', 'base_variant_id', 'unit_ratio', 'retail_price', 'wholesale_price', 'import_price', 'weight'])]
class ProductVariant extends Model
{
    use BelongsToTenant, LogsActivity, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_ratio' => 'integer',
            'retail_price' => 'integer',
            'wholesale_price' => 'integer',
            'import_price' => 'integer',
            'cost_price' => 'integer',
            'weight' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function baseVariant(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_variant_id');
    }

    /**
     * Conversion units built on this variant.
     *
     * @return HasMany<ProductVariant, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(self::class, 'base_variant_id');
    }

    /**
     * @return HasMany<VariantPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(VariantPrice::class, 'variant_id');
    }

    public function isUnit(): bool
    {
        return $this->base_variant_id !== null;
    }

    /**
     * Label for lists: "Áo thun - Đỏ / M" or "Nước suối (Thùng)".
     */
    public function fullName(): string
    {
        return $this->name;
    }

    public function activityLabel(): string
    {
        return "phiên bản {$this->sku}";
    }

    /**
     * Cost is recalculated on every receipt; logging it would flood the log.
     *
     * @return list<string>
     */
    public function activityIgnored(): array
    {
        return ['cost_price'];
    }
}
