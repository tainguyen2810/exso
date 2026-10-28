<?php

namespace Modules\Promotion\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One coupon code of a batch. Codes are stored upper case.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $batch_id
 * @property string $code
 * @property bool $is_active
 * @property int $used_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read CouponBatch $batch
 */
class Coupon extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = ['batch_id', 'code', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'used_count' => 'integer'];
    }

    /**
     * @return BelongsTo<CouponBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(CouponBatch::class, 'batch_id')->withTrashed();
    }

    public static function normalize(string $code): string
    {
        return mb_strtoupper(trim($code));
    }
}
