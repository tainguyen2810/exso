<?php

namespace Modules\Shipping\Models;

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "Phiếu đối soát" of a shipping partner: the COD it collected and the fees
 * it charges for a set of finished shipments. Completing it records the COD
 * as payments of the orders and the fees as an expense.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property int $carrier_id
 * @property string $code
 * @property string $status
 * @property int $shipments_count
 * @property int $cod_total
 * @property int $fee_total
 * @property int $partner_cod_total
 * @property int $partner_fee_total
 * @property int $amount
 * @property int|null $payment_method_id
 * @property string|null $note
 * @property string|null $tags
 * @property int|null $created_by
 * @property int|null $completed_by
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Branch $branch
 * @property-read Carrier $carrier
 * @property-read PaymentMethod|null $paymentMethod
 * @property-read User|null $creator
 * @property-read User|null $completer
 * @property-read Collection<int, CodReconciliationItem> $items
 */
class CodReconciliation extends Model
{
    use BelongsToTenant, LogsActivity;

    public const RECONCILING = 'reconciling';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::RECONCILING => 'Đang đối soát',
        self::DONE => 'Đã đối soát',
        self::CANCELLED => 'Đã hủy',
    ];

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shipments_count' => 'integer',
            'cod_total' => 'integer',
            'fee_total' => 'integer',
            'partner_cod_total' => 'integer',
            'partner_fee_total' => 'integer',
            'amount' => 'integer',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<CodReconciliation>  $query
     * @return Builder<CodReconciliation>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn($query->qualifyColumn('branch_id'), $user->branchIds());
    }

    public function isVisibleTo(User $user): bool
    {
        return in_array($this->branch_id, $user->branchIds(), true);
    }

    /** What the system expects the partner to pay: COD - fees. */
    public function systemAmount(): int
    {
        return $this->cod_total - $this->fee_total;
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Carrier, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class)->withTrashed();
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * @return HasMany<CodReconciliationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CodReconciliationItem::class)->orderBy('id');
    }

    public function activityLabel(): string
    {
        return "phiếu đối soát {$this->code}";
    }
}
