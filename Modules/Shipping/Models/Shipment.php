<?php

namespace Modules\Shipping\Models;

use App\Models\Branch;
use App\Models\User;
use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Order\Models\Order;

/**
 * A shipment ("phiếu giao hàng") of an order, handed to a shipping partner.
 * Its status is the common one Sapo shows, whatever the carrier calls it;
 * only ShipmentService changes it.
 *
 * pending (Chờ lấy hàng) → shipping (Đang giao hàng) ⇄ redelivery (Chờ giao
 * lại) → delivered (Đã giao hàng); a failed parcel goes returning (Hủy giao
 * - chờ nhận) → returned (Hủy giao - đã nhận); cancelled = Hủy đóng gói.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property int $order_id
 * @property int $carrier_id
 * @property string $code
 * @property string|null $tracking_code
 * @property string $status
 * @property string|null $carrier_status
 * @property string|null $service_id
 * @property string|null $service_name
 * @property int $cod_amount
 * @property int $fee
 * @property string $fee_payer
 * @property int $insurance_value
 * @property int $weight
 * @property int $length
 * @property int $width
 * @property int $height
 * @property string $receiver_name
 * @property string $receiver_phone
 * @property string $address
 * @property string|null $ward
 * @property string|null $district
 * @property string|null $province
 * @property string|null $required_note
 * @property string|null $note
 * @property CarbonImmutable|null $expected_delivery_at
 * @property CarbonImmutable|null $picked_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $returned_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $synced_at
 * @property int|null $cod_reconciliation_id
 * @property CarbonImmutable|null $reconciled_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Branch $branch
 * @property-read Order $order
 * @property-read Carrier $carrier
 * @property-read User|null $creator
 * @property-read CodReconciliation|null $reconciliation
 * @property-read Collection<int, ShipmentEvent> $events
 */
class Shipment extends Model
{
    use BelongsToTenant, LogsActivity;

    public const PENDING = 'pending';

    public const SHIPPING = 'shipping';

    public const REDELIVERY = 'redelivery';

    public const DELIVERED = 'delivered';

    public const RETURNING = 'returning';

    public const RETURNED = 'returned';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::PENDING => 'Chờ lấy hàng',
        self::SHIPPING => 'Đang giao hàng',
        self::REDELIVERY => 'Chờ giao lại',
        self::DELIVERED => 'Đã giao hàng',
        self::RETURNING => 'Hủy giao - chờ nhận',
        self::RETURNED => 'Hủy giao - đã nhận',
        self::CANCELLED => 'Hủy đóng gói',
    ];

    /** Statuses of a parcel still out with the partner. */
    public const ACTIVE = [self::PENDING, self::SHIPPING, self::REDELIVERY, self::RETURNING];

    /** Still with the partner and on its way to the receiver: COD may be collected. */
    public const COLLECTING = [self::PENDING, self::SHIPPING, self::REDELIVERY];

    /** What staff may set by hand for a partner the store contacts itself. */
    public const MANUAL_STEPS = [
        self::PENDING => [self::SHIPPING, self::CANCELLED],
        self::SHIPPING => [self::DELIVERED, self::REDELIVERY, self::RETURNING],
        self::REDELIVERY => [self::SHIPPING, self::DELIVERED, self::RETURNING],
        self::RETURNING => [self::RETURNED, self::SHIPPING],
    ];

    /** "Yêu cầu khi giao" (GHN codes, used for every partner). */
    public const REQUIRED_NOTES = [
        'CHOXEMHANGKHONGTHU' => 'Cho xem hàng, không cho thử',
        'CHOTHUHANG' => 'Cho thử hàng',
        'KHONGCHOXEMHANG' => 'Không cho xem hàng',
    ];

    public const FEE_PAYERS = [
        'shop' => 'Shop trả',
        'receiver' => 'Người nhận trả',
    ];

    /** Reconciliation of the COD and fee ("Đối soát"). */
    public const COD_STATUSES = [
        'none' => 'Không thu hộ',
        'collecting' => 'Đang thu hộ',
        'waiting' => 'Chờ đối soát',
        'reconciling' => 'Đang đối soát',
        'reconciled' => 'Đã đối soát',
    ];

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cod_amount' => 'integer',
            'fee' => 'integer',
            'insurance_value' => 'integer',
            'weight' => 'integer',
            'length' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'expected_delivery_at' => 'immutable_datetime',
            'picked_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
            'reconciled_at' => 'immutable_datetime',
        ];
    }

    /**
     * Shipments the user may see: in their branches.
     *
     * @param  Builder<Shipment>  $query
     * @return Builder<Shipment>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn($query->qualifyColumn('branch_id'), $user->branchIds());
    }

    public function isVisibleTo(User $user): bool
    {
        return in_array($this->branch_id, $user->branchIds(), true);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /** Delivered or back at the store: ready for "đối soát". */
    public function isFinished(): bool
    {
        return in_array($this->status, [self::DELIVERED, self::RETURNED], true);
    }

    /** COD the partner owes for this parcel: nothing unless delivered. */
    public function collectedCod(): int
    {
        return $this->status === self::DELIVERED ? $this->cod_amount : 0;
    }

    public function codStatus(): string
    {
        return match (true) {
            $this->reconciled_at !== null => 'reconciled',
            $this->cod_reconciliation_id !== null => 'reconciling',
            $this->status === self::CANCELLED => 'none',
            $this->isFinished() => 'waiting',
            $this->cod_amount > 0 && in_array($this->status, self::COLLECTING, true) => 'collecting',
            default => 'none',
        };
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Carrier, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<CodReconciliation, $this>
     */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(CodReconciliation::class, 'cod_reconciliation_id');
    }

    /**
     * @return HasMany<ShipmentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /** Full address on one line. */
    public function fullAddress(): string
    {
        return implode(', ', array_filter([$this->address, $this->ward, $this->district, $this->province]));
    }

    public function activityLabel(): string
    {
        return "phiếu giao hàng {$this->code}";
    }
}
