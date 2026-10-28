<?php

namespace Modules\Inventory\Models;

use App\Models\Branch;
use App\Models\User;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Product\Models\ProductVariant;

/**
 * One line of the stock ledger ("thẻ kho"). Rows are only inserted, never
 * changed: the sum of quantity per variant × branch equals inventories.on_hand.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property int $variant_id
 * @property string $type
 * @property int $quantity
 * @property int $on_hand_after
 * @property int $unit_cost
 * @property int $cost_after
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $reference_code
 * @property string|null $note
 * @property int|null $created_by
 * @property-read Branch $branch
 * @property-read ProductVariant $variant
 * @property-read User|null $creator
 * @property CarbonImmutable|null $created_at
 */
class StockMovement extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const OPENING = 'opening';

    public const PURCHASE = 'purchase';

    public const PURCHASE_RETURN = 'purchase_return';

    public const SALE = 'sale';

    public const SALE_RETURN = 'sale_return';

    public const STOCKTAKE = 'stocktake';

    public const TRANSFER_OUT = 'transfer_out';

    public const TRANSFER_IN = 'transfer_in';

    public const ADJUSTMENT = 'adjustment';

    public const COST_ADJUSTMENT = 'cost_adjustment';

    public const TYPES = [
        self::OPENING => 'Khởi tạo tồn',
        self::PURCHASE => 'Nhập hàng',
        self::PURCHASE_RETURN => 'Trả hàng nhà cung cấp',
        self::SALE => 'Bán hàng',
        self::SALE_RETURN => 'Khách trả hàng',
        self::STOCKTAKE => 'Kiểm hàng',
        self::TRANSFER_OUT => 'Chuyển hàng đi',
        self::TRANSFER_IN => 'Nhận hàng chuyển',
        self::ADJUSTMENT => 'Điều chỉnh',
        self::COST_ADJUSTMENT => 'Điều chỉnh giá vốn',
    ];

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'on_hand_after' => 'integer',
            'unit_cost' => 'integer',
            'cost_after' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
