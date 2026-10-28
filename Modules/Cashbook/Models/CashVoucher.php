<?php

namespace Modules\Cashbook\Models;

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phiếu thu (receipt, money in) or phiếu chi (payment, money out). The
 * amount is always positive; `kind` gives the direction. Vouchers made from
 * another document (`is_auto`) cannot be edited or cancelled by hand.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property string $kind
 * @property string $code
 * @property int $type_id
 * @property string $status
 * @property bool $is_auto
 * @property string $party_type
 * @property int|null $party_id
 * @property string $party_name
 * @property int $amount
 * @property int|null $payment_method_id
 * @property string $method_name
 * @property string $method_type
 * @property string|null $reference
 * @property string|null $origin_type
 * @property int|null $origin_id
 * @property string|null $document_type
 * @property int|null $document_id
 * @property string|null $document_code
 * @property bool $changes_debt
 * @property bool $affects_profit
 * @property string|null $description
 * @property string|null $tags
 * @property CarbonImmutable $recorded_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $cancelled_by
 * @property-read CashVoucherType $type
 * @property-read Branch $branch
 * @property-read PaymentMethod|null $paymentMethod
 * @property-read User|null $creator
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CashVoucher extends Model
{
    use BelongsToTenant, LogsActivity;

    public const RECEIPT = 'receipt';

    public const PAYMENT = 'payment';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::COMPLETED => 'Hoàn thành', self::CANCELLED => 'Đã hủy'];

    /** Nhóm người nộp / nhận, as in Sapo. */
    public const PARTIES = [
        'customer' => 'Khách hàng',
        'supplier' => 'Nhà cung cấp',
        'staff' => 'Nhân viên',
        'other' => 'Khác',
    ];

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'is_auto' => 'boolean',
            'changes_debt' => 'boolean',
            'affects_profit' => 'boolean',
            'recorded_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** Code prefix: RVN for receipts, PVN for payments (like Sapo). */
    public static function prefix(string $kind): string
    {
        return $kind === self::RECEIPT ? 'RVN' : 'PVN';
    }

    public static function label(string $kind): string
    {
        return $kind === self::RECEIPT ? 'phiếu thu' : 'phiếu chi';
    }

    /**
     * Money in (+) or out (-) of the fund.
     */
    public function signedAmount(): int
    {
        return $this->kind === self::RECEIPT ? $this->amount : -$this->amount;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    /**
     * Vouchers in the user's branches.
     *
     * @param  Builder<CashVoucher>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('branch_id', $user->branchIds());
    }

    /**
     * @return BelongsTo<CashVoucherType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(CashVoucherType::class, 'type_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function activityLabel(): string
    {
        return self::label($this->kind).' '.$this->code;
    }
}
