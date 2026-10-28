<?php

namespace Modules\Promotion\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Customer\Models\Customer;

/**
 * Points balance and tier of a customer. Changed only by LoyaltyService.
 *
 * @property int $id
 * @property int $customer_id
 * @property int $points
 * @property int $earned_total
 * @property int $used_total
 * @property int|null $tier_id
 * @property-read LoyaltyTier|null $tier
 * @property-read Customer $customer
 */
class LoyaltyAccount extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = ['customer_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['points' => 'integer', 'earned_total' => 'integer', 'used_total' => 'integer'];
    }

    /**
     * @return BelongsTo<LoyaltyTier, $this>
     */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class, 'tier_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
