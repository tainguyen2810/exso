<?php

namespace Modules\Purchase\Models;

use App\Models\Branch;
use App\Models\User;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "Trả hàng nhập": goods sent back to the supplier from a receipt.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property int $supplier_id
 * @property int $purchase_receipt_id
 * @property string $code
 * @property int $total
 * @property string|null $note
 * @property int|null $created_by
 * @property-read Branch $branch
 * @property-read User|null $creator
 * @property-read Collection<int, PurchaseReturnItem> $items
 * @property CarbonImmutable|null $created_at
 */
class PurchaseReturn extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<PurchaseReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }
}
