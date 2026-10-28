<?php

namespace Modules\Order\Models;

use App\Models\Branch;
use App\Models\OrderReason;
use App\Models\SalesSource;
use App\Models\User;
use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Customer\Models\Customer;

/**
 * A sales order ("Đơn hàng"), from the order form or the POS.
 *
 * Status moves only forward through OrderService:
 * pending (Đặt hàng) → approved (Duyệt, stock reserved) → packed (Đóng gói)
 * → shipped (Xuất kho, stock taken) → completed (delivered); cancelled
 * at any step before completed. Payment and delivery have their own status.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property string $code
 * @property string $channel
 * @property string $status
 * @property int|null $customer_id
 * @property string|null $customer_name
 * @property string|null $customer_phone
 * @property string|null $receiver_name
 * @property string|null $receiver_phone
 * @property string|null $shipping_address
 * @property int|null $source_id
 * @property string $price_column
 * @property int|null $price_list_id
 * @property string|null $price_label
 * @property string $delivery_method
 * @property string|null $delivery_status
 * @property string|null $carrier_name
 * @property string|null $tracking_code
 * @property int $subtotal
 * @property int $discount
 * @property int|null $discount_bp
 * @property int $shipping_fee
 * @property int $total
 * @property int $paid
 * @property int $returned_total
 * @property int $cost_total
 * @property int $promotion_discount
 * @property array{ids?: list<int>, coupon?: string|null, gifts?: array<string, int>, applied?: list<array{promotion_id: int|null, coupon_id: int|null, name: string, code: string|null, amount: int}>}|null $promotions
 * @property string|null $reference
 * @property string|null $tags
 * @property string|null $note
 * @property CarbonImmutable|null $expected_delivery_at
 * @property CarbonImmutable|null $sale_at
 * @property int|null $assigned_to
 * @property int|null $created_by
 * @property int|null $pos_shift_id
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $packed_at
 * @property CarbonImmutable|null $shipped_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $cancel_reason_id
 * @property string|null $cancel_note
 * @property-read Branch $branch
 * @property-read Customer|null $customer
 * @property-read SalesSource|null $source
 * @property-read User|null $assignee
 * @property-read User|null $creator
 * @property-read OrderReason|null $cancelReason
 * @property-read Collection<int, OrderItem> $items
 * @property-read Collection<int, OrderPayment> $payments
 * @property-read Collection<int, OrderHistory> $histories
 * @property-read Collection<int, OrderReturn> $returns
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['branch_id', 'code', 'channel', 'customer_id', 'customer_name', 'customer_phone', 'receiver_name', 'receiver_phone', 'shipping_address', 'source_id', 'price_column', 'price_list_id', 'price_label', 'delivery_method', 'carrier_name', 'tracking_code', 'reference', 'tags', 'note', 'expected_delivery_at', 'sale_at', 'assigned_to', 'created_by', 'pos_shift_id'])]
class Order extends Model
{
    use BelongsToTenant, LogsActivity;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const PACKED = 'packed';

    public const SHIPPED = 'shipped';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    /** Steps of the progress bar on the order page, as in Sapo. */
    public const STEPS = [
        self::PENDING => 'Đặt hàng',
        self::APPROVED => 'Duyệt',
        self::PACKED => 'Đóng gói',
        self::SHIPPED => 'Xuất kho',
        self::COMPLETED => 'Hoàn thành',
    ];

    /** Order status shown in lists (Sapo groups the middle steps as "Đang giao dịch"). */
    public const STATUSES = [
        self::PENDING => 'Đặt hàng',
        self::APPROVED => 'Đang giao dịch',
        self::PACKED => 'Đang giao dịch',
        self::SHIPPED => 'Đang giao dịch',
        self::COMPLETED => 'Hoàn thành',
        self::CANCELLED => 'Đã hủy',
    ];

    public const DELIVERY_METHODS = [
        'pickup' => 'Khách nhận tại cửa hàng',
        'later' => 'Giao hàng sau',
        'external' => 'Đẩy vận chuyển ngoài',
    ];

    public const DELIVERY_STATUSES = [
        'shipping' => 'Đang giao hàng',
        'delivered' => 'Đã giao hàng',
        'returning' => 'Hủy giao - chờ nhận',
    ];

    public const PAYMENT_STATUSES = [
        'unpaid' => 'Chưa thanh toán',
        'partial' => 'Thanh toán một phần',
        'paid' => 'Đã thanh toán',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'discount' => 'integer',
            'discount_bp' => 'integer',
            'shipping_fee' => 'integer',
            'total' => 'integer',
            'paid' => 'integer',
            'returned_total' => 'integer',
            'cost_total' => 'integer',
            'promotion_discount' => 'integer',
            'promotions' => 'array',
            'expected_delivery_at' => 'immutable_date',
            'sale_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'packed_at' => 'immutable_datetime',
            'shipped_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * Orders the user may see: in their branches, and only those they sell
     * or created unless they have orders.view_all.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query->whereIn($query->qualifyColumn('branch_id'), $user->branchIds());

        if (! $user->can('orders.view_all')) {
            $query->where(fn (Builder $query) => $query->where('assigned_to', $user->id)->orWhere('created_by', $user->id));
        }

        return $query;
    }

    public function isVisibleTo(User $user): bool
    {
        return in_array($this->branch_id, $user->branchIds(), true)
            && ($user->can('orders.view_all') || $this->assigned_to === $user->id || $this->created_by === $user->id);
    }

    /** What the customer still owes for this order (negative = to refund). */
    public function remaining(): int
    {
        return $this->total - $this->returned_total - $this->paid;
    }

    public function paymentStatus(): string
    {
        $due = $this->total - $this->returned_total;

        return match (true) {
            $this->paid >= $due => 'paid',
            $this->paid > 0 => 'partial',
            default => 'unpaid',
        };
    }

    /** Stock has left the warehouse (and not come back through cancel). */
    public function isExported(): bool
    {
        return in_array($this->status, [self::SHIPPED, self::COMPLETED], true);
    }

    /** Lines can still be changed: before export. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::PENDING, self::APPROVED, self::PACKED], true);
    }

    /** Stock is held for the order. */
    public function holdsStock(): bool
    {
        return in_array($this->status, [self::APPROVED, self::PACKED], true);
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
     * @return BelongsTo<SalesSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(SalesSource::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<OrderReason, $this>
     */
    public function cancelReason(): BelongsTo
    {
        return $this->belongsTo(OrderReason::class, 'cancel_reason_id');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<OrderPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class)->orderBy('id');
    }

    /**
     * @return HasMany<OrderHistory, $this>
     */
    public function histories(): HasMany
    {
        return $this->hasMany(OrderHistory::class)->orderBy('id');
    }

    /**
     * @return HasMany<OrderReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class)->orderBy('id');
    }

    public function activityLabel(): string
    {
        return "đơn hàng {$this->code}";
    }

    /**
     * The order keeps its own history (order_histories); totals change on
     * every step and would flood the activity log.
     *
     * @return list<string>
     */
    public function activityIgnored(): array
    {
        return ['paid', 'returned_total', 'cost_total', 'status', 'delivery_status', 'approved_at', 'packed_at', 'shipped_at', 'completed_at', 'cancelled_at', 'updated_at'];
    }
}
