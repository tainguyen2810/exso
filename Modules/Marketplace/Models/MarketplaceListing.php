<?php

namespace Modules\Marketplace\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Models\ProductVariant;

/**
 * A product (or one variation of it) on a marketplace shop, linked to an
 * EXSO variant so its orders become EXSO lines and its stock follows
 * "Có thể bán".
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $shop_id
 * @property string $item_id
 * @property string $model_id
 * @property string $name
 * @property string|null $model_name
 * @property string|null $sku
 * @property string|null $image_url
 * @property int $price
 * @property int $stock
 * @property string|null $status
 * @property int|null $variant_id
 * @property CarbonImmutable|null $linked_at
 * @property bool $stock_dirty
 * @property int|null $pushed_stock
 * @property CarbonImmutable|null $pushed_at
 * @property string|null $push_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read MarketplaceShop $shop
 * @property-read ProductVariant|null $variant
 */
class MarketplaceListing extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = ['shop_id', 'item_id', 'model_id', 'name', 'model_name', 'sku', 'image_url', 'price', 'stock', 'status'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'stock' => 'integer',
            'stock_dirty' => 'boolean',
            'pushed_stock' => 'integer',
            'linked_at' => 'immutable_datetime',
            'pushed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<MarketplaceShop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(MarketplaceShop::class, 'shop_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class)->withTrashed();
    }

    public function fullName(): string
    {
        return $this->model_name === null || $this->model_name === '' ? $this->name : "{$this->name} - {$this->model_name}";
    }
}
