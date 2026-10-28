<?php

namespace Modules\Order\Models;

use App\Models\Branch;
use App\Models\User;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cashier's shift at the POS ("Ca làm việc"): cash at opening, cash
 * received and refunded during the shift, the count at closing.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $user_id
 * @property string $status open | closed
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $closed_at
 * @property int $opening_cash
 * @property int $cash_in
 * @property int $cash_out
 * @property int $expected_cash
 * @property int|null $counted_cash
 * @property int $difference
 * @property int $orders_count
 * @property int $sales_total
 * @property string|null $note
 * @property-read User $user
 * @property-read Branch $branch
 */
class PosShift extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'opening_cash' => 'integer',
            'cash_in' => 'integer',
            'cash_out' => 'integer',
            'expected_cash' => 'integer',
            'counted_cash' => 'integer',
            'difference' => 'integer',
            'orders_count' => 'integer',
            'sales_total' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }
}
