<?php

namespace Modules\Promotion\Models;

use App\Models\User;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Customer\Models\Customer;

/**
 * One change of a customer's points, with the balance after it.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $type
 * @property int $points
 * @property int $balance_after
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $reference_code
 * @property string|null $note
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read Customer $customer
 * @property-read User|null $creator
 */
class LoyaltyPoint extends Model
{
    use BelongsToTenant;

    public const EARN = 'earn';

    public const RETURN = 'return';

    public const REDEEM = 'redeem';

    public const REFUND = 'refund';

    public const ADJUST = 'adjust';

    public const TYPES = [
        self::EARN => 'Tích điểm đơn hàng',
        self::RETURN => 'Trừ điểm hàng trả',
        self::REDEEM => 'Thanh toán bằng điểm',
        self::REFUND => 'Hoàn điểm',
        self::ADJUST => 'Điều chỉnh',
    ];

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['points' => 'integer', 'balance_after' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
