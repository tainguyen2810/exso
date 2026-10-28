<?php

namespace Modules\Purchase\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Models\ProductVariant;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $purchase_receipt_id
 * @property int $variant_id
 * @property int $quantity
 * @property int $returned_quantity
 * @property int $price
 * @property int $discount
 * @property int $total
 * @property int $unit_cost
 * @property-read ProductVariant $variant
 * @property-read PurchaseReceipt $receipt
 */
class PurchaseReceiptItem extends Model
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

    /**
     * @return BelongsTo<PurchaseReceipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }
}
