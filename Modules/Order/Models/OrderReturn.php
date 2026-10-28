<?php

namespace Modules\Order\Models;

use App\Models\Branch;
use App\Models\OrderReason;
use App\Models\User;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Customer\Models\Customer;

/**
 * "Đơn trả hàng" (SRN...): goods a customer brings back after export.
 * The goods go back into stock and the money is refunded or kept as credit.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $order_id
 * @property int|null $customer_id
 * @property string $code
 * @property string $status
 * @property int|null $reason_id
 * @property string|null $note
 * @property int $quantity
 * @property int $total
 * @property int $refunded
 * @property CarbonImmutable|null $received_at
 * @property int|null $created_by
 * @property-read Order $order
 * @property-read Branch $branch
 * @property-read Customer|null $customer
 * @property-read OrderReason|null $reason
 * @property-read User|null $creator
 * @property-read Collection<int, OrderReturnItem> $items
 * @property CarbonImmutable|null $created_at
 */
class OrderReturn extends Model
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
            'quantity' => 'integer',
            'total' => 'integer',
            'refunded' => 'integer',
            'received_at' => 'immutable_datetime',
        ];
    }

    public function refundStatus(): string
    {
        return match (true) {
            $this->refunded >= $this->total => 'refunded',
            $this->refunded > 0 => 'partial',
            default => 'none',
        };
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<OrderReason, $this>
     */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(OrderReason::class, 'reason_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<OrderReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderReturnItem::class)->orderBy('id');
    }
}
