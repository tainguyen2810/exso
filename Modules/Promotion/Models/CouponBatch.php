<?php

namespace Modules\Promotion\Models;

use App\Models\User;
use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * "Đợt phát hành mã giảm giá": the rules shared by a set of coupon codes.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property string $value_type
 * @property int $value
 * @property int|null $max_discount
 * @property int|null $uses_per_code
 * @property int|null $uses_per_customer
 * @property bool $combinable
 * @property int $min_order
 * @property string|null $product_target_type
 * @property list<int>|null $product_ids
 * @property bool $all_branches
 * @property list<int>|null $branch_ids
 * @property bool $all_sources
 * @property list<int>|null $source_ids
 * @property bool $all_customers
 * @property list<int>|null $customer_group_ids
 * @property int $used_count
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $creator
 * @property-read Collection<int, Coupon> $coupons
 */
class CouponBatch extends Model
{
    use BelongsToTenant, LogsActivity, SoftDeletes;

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id', 'used_count'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'value' => 'integer',
            'max_discount' => 'integer',
            'uses_per_code' => 'integer',
            'uses_per_customer' => 'integer',
            'combinable' => 'boolean',
            'min_order' => 'integer',
            'product_ids' => 'array',
            'branch_ids' => 'array',
            'source_ids' => 'array',
            'customer_group_ids' => 'array',
            'all_branches' => 'boolean',
            'all_sources' => 'boolean',
            'all_customers' => 'boolean',
            'used_count' => 'integer',
        ];
    }

    /**
     * @return HasMany<Coupon, $this>
     */
    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class, 'batch_id');
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
            ! $this->is_active => 'paused',
            $this->ends_at !== null && $this->ends_at->lt($at) => 'expired',
            $this->starts_at->gt($at) => 'scheduled',
            default => 'running',
        };
    }

    /** "Giảm 10%" / "Giảm 50.000đ". */
    public function valueLabel(): string
    {
        return $this->value_type === 'percent'
            ? 'Giảm '.rtrim(rtrim(number_format($this->value / 100, 2, ',', '.'), '0'), ',').'%'.($this->max_discount ? ' (tối đa '.number_format($this->max_discount, 0, ',', '.').'đ)' : '')
            : 'Giảm '.number_format($this->value, 0, ',', '.').'đ';
    }

    public function activityLabel(): string
    {
        return 'đợt phát hành mã giảm giá '.$this->code;
    }
}
