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
 * "Điều chỉnh giá vốn": sets the average cost of variants by hand.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property string $code
 * @property string $status
 * @property string|null $note
 * @property CarbonImmutable|null $applied_at
 * @property int|null $applied_by
 * @property int|null $created_by
 * @property-read Branch $branch
 * @property-read User|null $creator
 * @property-read User|null $applier
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CostAdjustmentItem> $items
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CostAdjustment extends Model
{
    use BelongsToTenant, LogsActivity;

    public const DRAFT = 'draft';

    public const APPLIED = 'applied';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::DRAFT => 'Chưa điều chỉnh',
        self::APPLIED => 'Đã điều chỉnh',
        self::CANCELLED => 'Đã hủy',
    ];

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['applied_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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
    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    /**
     * @return HasMany<CostAdjustmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CostAdjustmentItem::class);
    }

    public function activityLabel(): string
    {
        return "phiếu điều chỉnh giá vốn {$this->code}";
    }
}
