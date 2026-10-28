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
 * "Kiểm hàng": counted quantities; balancing sets stock to them.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property string $code
 * @property string $status
 * @property string|null $note
 * @property CarbonImmutable|null $balanced_at
 * @property int|null $balanced_by
 * @property int|null $created_by
 * @property-read Branch $branch
 * @property-read User|null $creator
 * @property-read User|null $balancer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, StocktakeItem> $items
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Stocktake extends Model
{
    use BelongsToTenant, LogsActivity;

    public const DRAFT = 'draft';

    public const BALANCED = 'balanced';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::DRAFT => 'Đang kiểm hàng',
        self::BALANCED => 'Đã cân bằng',
        self::CANCELLED => 'Đã hủy',
    ];

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['balanced_at' => 'immutable_datetime'];
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
    public function balancer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'balanced_by');
    }

    /**
     * @return HasMany<StocktakeItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StocktakeItem::class);
    }

    public function activityLabel(): string
    {
        return "phiếu kiểm hàng {$this->code}";
    }
}
