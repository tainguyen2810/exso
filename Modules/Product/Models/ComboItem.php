<?php

namespace Modules\Product\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One component of a combo product: selling 1 combo moves quantity × variant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property int $variant_id
 * @property int $quantity
 * @property-read ProductVariant $variant
 */
#[Fillable(['product_id', 'variant_id', 'quantity'])]
class ComboItem extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
    }
}
