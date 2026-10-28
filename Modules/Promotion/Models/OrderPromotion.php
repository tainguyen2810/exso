<?php

namespace Modules\Promotion\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Order\Models\Order;

/**
 * A program or coupon used by an order; one row per use, removed when the
 * order is cancelled or edited, so counting rows counts uses.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $promotion_id
 * @property int|null $coupon_id
 * @property int|null $coupon_batch_id
 * @property int|null $customer_id
 * @property string $name
 * @property string|null $code
 * @property int $amount
 * @property CarbonImmutable|null $created_at
 * @property-read Order $order
 */
class OrderPromotion extends Model
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
        return ['amount' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
