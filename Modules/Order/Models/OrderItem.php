<?php

namespace Modules\Order\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;

/**
 * A line of an order, with the product's name and price copied at sale time.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $variant_id
 * @property int|null $product_id
 * @property string $product_type
 * @property string $name
 * @property string|null $sku
 * @property string|null $unit
 * @property int $quantity
 * @property int $price
 * @property int $discount
 * @property int $total
 * @property int $cost
 * @property int $returned_quantity
 * @property string|null $note
 * @property-read ProductVariant|null $variant
 * @property-read Product|null $product
 */
class OrderItem extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price' => 'integer',
            'discount' => 'integer',
            'total' => 'integer',
            'cost' => 'integer',
            'returned_quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** Moves stock: a catalog product that is not a service. */
    public function tracksStock(): bool
    {
        return $this->variant_id !== null && $this->product_type !== Product::TYPE_SERVICE;
    }
}
