<?php

namespace Modules\Customer\Models;

use App\Models\User;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of a customer's debt ledger ("Công nợ"). Only inserted.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $type
 * @property int $amount
 * @property int $debt_after
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $reference_code
 * @property string|null $note
 * @property int|null $created_by
 * @property-read User|null $creator
 * @property CarbonImmutable|null $created_at
 */
class CustomerDebt extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const SALE = 'sale';

    public const PAYMENT = 'payment';

    public const REFUND = 'refund';

    public const RETURN = 'return';

    public const CANCEL = 'cancel';

    public const ADJUSTMENT = 'adjustment';

    public const TYPES = [
        self::SALE => 'Xuất kho đơn hàng',
        self::PAYMENT => 'Khách thanh toán',
        self::REFUND => 'Hoàn tiền cho khách',
        self::RETURN => 'Khách trả hàng',
        self::CANCEL => 'Hủy đơn hàng',
        self::ADJUSTMENT => 'Điều chỉnh công nợ',
    ];

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'integer', 'debt_after' => 'integer'];
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
}
