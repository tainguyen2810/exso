<?php

namespace Modules\Inventory\Models;

use App\Models\Branch;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Models\ProductVariant;

/**
 * Current stock of one variant in one branch. Never update on_hand directly:
 * go through InventoryService so a stock movement is written.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property int $variant_id
 * @property int $on_hand
 * @property int $reserved
 * @property int|null $min_quantity
 * @property int|null $max_quantity
 * @property-read Branch $branch
 * @property-read ProductVariant $variant
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['branch_id', 'variant_id', 'min_quantity', 'max_quantity'])]
class Inventory extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'on_hand' => 'integer',
            'reserved' => 'integer',
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
        ];
    }

    /**
     * "Có thể bán" = in stock minus what is held for orders.
     */
    public function available(): int
    {
        return $this->on_hand - $this->reserved;
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
    }
}
