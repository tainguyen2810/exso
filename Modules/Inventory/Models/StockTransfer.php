<?php

namespace Modules\Inventory\Models;

use App\Models\Branch;
use App\Models\User;
use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "Chuyển hàng" between branches, in two steps: shipped (out of the sending
 * branch) then received (into the receiving branch).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $from_branch_id
 * @property int $to_branch_id
 * @property string $code
 * @property string $status
 * @property string|null $note
 * @property CarbonImmutable|null $shipped_at
 * @property int|null $shipped_by
 * @property CarbonImmutable|null $received_at
 * @property int|null $received_by
 * @property int|null $created_by
 * @property-read Branch $fromBranch
 * @property-read Branch $toBranch
 * @property-read User|null $creator
 * @property-read User|null $shipper
 * @property-read User|null $receiver
 * @property-read \Illuminate\Database\Eloquent\Collection<int, StockTransferItem> $items
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class StockTransfer extends Model
{
    use BelongsToTenant, LogsActivity;

    public const DRAFT = 'draft';

    public const SHIPPING = 'shipping';

    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::DRAFT => 'Chờ chuyển',
        self::SHIPPING => 'Đang chuyển',
        self::RECEIVED => 'Đã nhận',
        self::CANCELLED => 'Đã hủy',
    ];

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['shipped_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
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
    public function shipper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipped_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * @return HasMany<StockTransferItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function activityLabel(): string
    {
        return "phiếu chuyển hàng {$this->code}";
    }
}
