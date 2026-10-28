<?php

namespace Modules\Inventory\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Models\ProductVariant;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $cost_adjustment_id
 * @property int $variant_id
 * @property int $old_cost
 * @property int $new_cost
 * @property-read ProductVariant $variant
 */
class CostAdjustmentItem extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class)->withTrashed();
    }
}
