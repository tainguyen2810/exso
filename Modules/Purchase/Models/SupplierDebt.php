<?php

namespace Modules\Purchase\Models;

use App\Models\User;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a supplier's debt ledger. Rows are only inserted: the sum of
 * amount per supplier equals suppliers.debt.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $supplier_id
 * @property string $type
 * @property int $amount
 * @property int $debt_after
 * @property string|null $payment_method
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $reference_code
 * @property string|null $note
 * @property int|null $created_by
 * @property-read Supplier $supplier
 * @property-read User|null $creator
 * @property CarbonImmutable|null $created_at
 */
class SupplierDebt extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const PURCHASE = 'purchase';

    public const PAYMENT = 'payment';

    public const RETURN = 'return';

    public const CANCEL = 'cancel';

    public const TYPES = [
        self::PURCHASE => 'Nhập hàng',
        self::PAYMENT => 'Thanh toán',
        self::RETURN => 'Trả hàng',
        self::CANCEL => 'Hủy phiếu nhập',
    ];

    public const PAYMENT_METHODS = [
        'cash' => 'Tiền mặt',
        'transfer' => 'Chuyển khoản',
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
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
