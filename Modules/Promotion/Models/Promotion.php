<?php

namespace Modules\Promotion\Models;

use App\Models\User;
use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A promotion program ("Chương trình khuyến mại"), like Sapo: a discount
 * or a gift, given by one of seven methods whose rows live in
 * `conditions` (see METHODS), limited by dates, hours, branches, sources
 * and customer groups, and by the number of orders that may use it.
 *
 * Rows by method (ids are of `target_type` / `get_target_type`, percent
 * values in basis points):
 *  - order_total: {min, max|null, type, value}
 *  - product: {ids, min_qty, limit_qty|null, type, value}
 *  - buy_more: {min_order, ids, max_qty, type, value}
 *  - quantity: {ids, tiers: [{from, to|null, type, value}]}
 *  - buy_get: {buy_ids, buy_qty, get_ids, get_qty, type, value}
 *  - gift_order: {min_order, gift_ids (variants), gift_qty}
 *  - gift_product: {buy_ids, buy_qty, gift_ids (variants), gift_qty}
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $kind
 * @property string $method
 * @property string $status
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property array{time_from?: string|null, time_to?: string|null, weekdays?: list<int>, months?: list<int>, days?: list<int>}|null $schedule
 * @property int|null $usage_limit
 * @property int $used_count
 * @property string $target_type
 * @property string $get_target_type
 * @property bool $repeatable
 * @property list<array<string, mixed>> $conditions
 * @property bool $all_branches
 * @property list<int>|null $branch_ids
 * @property bool $all_sources
 * @property list<int>|null $source_ids
 * @property bool $all_customers
 * @property list<int>|null $customer_group_ids
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read User|null $creator
 */
class Promotion extends Model
{
    use BelongsToTenant, LogsActivity, SoftDeletes;

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const STOPPED = 'stopped';

    public const KINDS = ['discount' => 'Chiết khấu', 'gift' => 'Tặng quà'];

    /** Method => [kind, label], in Sapo's order. */
    public const METHODS = [
        'order_total' => ['discount', 'Chiết khấu theo tổng giá trị đơn hàng'],
        'product' => ['discount', 'Chiết khấu theo từng sản phẩm'],
        'buy_more' => ['discount', 'Chiết khấu sản phẩm mua thêm theo tổng giá trị đơn hàng'],
        'quantity' => ['discount', 'Chiết khấu theo số lượng sản phẩm'],
        'buy_get' => ['discount', 'Mua sản phẩm - giảm giá sản phẩm'],
        'gift_order' => ['gift', 'Tặng quà theo tổng giá trị đơn hàng'],
        'gift_product' => ['gift', 'Tặng quà theo sản phẩm mua'],
    ];

    public const TARGETS = ['variant' => 'Phiên bản sản phẩm', 'category' => 'Loại sản phẩm', 'brand' => 'Nhãn hiệu sản phẩm'];

    /** Shown status, from the status and the dates. */
    public const STATES = [
        'running' => 'Đang áp dụng',
        'scheduled' => 'Chưa áp dụng',
        'paused' => 'Tạm ngừng',
        'expired' => 'Hết hạn',
        'stopped' => 'Đã kết thúc',
    ];

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id', 'used_count'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'schedule' => 'array',
            'conditions' => 'array',
            'branch_ids' => 'array',
            'source_ids' => 'array',
            'customer_group_ids' => 'array',
            'usage_limit' => 'integer',
            'used_count' => 'integer',
            'repeatable' => 'boolean',
            'all_branches' => 'boolean',
            'all_sources' => 'boolean',
            'all_customers' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function state(?CarbonImmutable $at = null): string
    {
        $at ??= CarbonImmutable::now();

        return match (true) {
            $this->status === self::STOPPED => 'stopped',
            $this->status === self::PAUSED => 'paused',
            $this->ends_at !== null && $this->ends_at->lt($at) => 'expired',
            $this->starts_at->gt($at) => 'scheduled',
            default => 'running',
        };
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method][1] ?? $this->method;
    }

    public function hasRoom(): bool
    {
        return $this->usage_limit === null || $this->used_count < $this->usage_limit;
    }

    public function activityLabel(): string
    {
        return 'chương trình khuyến mại '.$this->code;
    }
}
