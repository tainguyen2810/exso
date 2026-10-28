<?php

namespace Modules\Purchase\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Models\ProductVariant;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $purchase_return_id
 * @property int $purchase_receipt_item_id
 * @property int $variant_id
 * @property int $quantity
 * @property int $price
 * @property int $total
 * @property-read ProductVariant $variant
 */
class PurchaseReturnItem extends Model
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
