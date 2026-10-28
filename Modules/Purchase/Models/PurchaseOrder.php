<?php

namespace Modules\Purchase\Models;

use App\Models\Branch;
use App\Models\User;
use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "Đặt hàng nhập": what was ordered from a supplier. Receipts made from it
 * fill received_quantity; it completes when everything arrived.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property int $supplier_id
 * @property string $code
 * @property string $status
 * @property CarbonImmutable|null $expected_at
 * @property int $subtotal
 * @property int $discount
 * @property int $total
 * @property string|null $note
 * @property int|null $created_by
 * @property-read Branch $branch
 * @property-read Supplier $supplier
 * @property-read User|null $creator
 * @property-read Collection<int, PurchaseOrderItem> $items
 * @property-read Collection<int, PurchaseReceipt> $receipts
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PurchaseOrder extends Model
{
    use BelongsToTenant, LogsActivity;

    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::PENDING => 'Đang giao dịch',
        self::COMPLETED => 'Hoàn thành',
        self::CANCELLED => 'Đã hủy',
    ];

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expected_at' => 'immutable_date', 'subtotal' => 'integer', 'discount' => 'integer', 'total' => 'integer'];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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

    /**
     * @return HasMany<PurchaseOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /**
     * @return HasMany<PurchaseReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    /**
     * Chưa nhập / Nhập một phần / Đã nhập.
     */
    public function receiveStatus(): string
    {
        $ordered = $this->items->sum('quantity');
        $received = $this->items->sum('received_quantity');

        return match (true) {
            $received <= 0 => 'none',
            $received < $ordered => 'partial',
            default => 'full',
        };
    }

    public function activityLabel(): string
    {
        return "đơn đặt hàng nhập {$this->code}";
    }
}
