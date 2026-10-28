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
 * "Nhập hàng". Draft until stock is received; totals are always computed
 * by PurchaseService, never taken from the browser.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property int $supplier_id
 * @property int|null $purchase_order_id
 * @property string $code
 * @property string $status
 * @property int $subtotal
 * @property int $discount
 * @property list<array{name: string, amount: int}>|null $costs
 * @property int $cost_total
 * @property int $total
 * @property int $paid
 * @property int $returned_total
 * @property string|null $reference
 * @property string|null $note
 * @property CarbonImmutable|null $received_at
 * @property int|null $received_by
 * @property int|null $created_by
 * @property-read Branch $branch
 * @property-read Supplier $supplier
 * @property-read PurchaseOrder|null $purchaseOrder
 * @property-read User|null $creator
 * @property-read User|null $receiver
 * @property-read Collection<int, PurchaseReceiptItem> $items
 * @property-read Collection<int, PurchaseReturn> $returns
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PurchaseReceipt extends Model
{
    use BelongsToTenant, LogsActivity;

    public const DRAFT = 'draft';

    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::DRAFT => 'Chưa nhập kho',
        self::RECEIVED => 'Đã nhập kho',
        self::CANCELLED => 'Đã hủy',
    ];

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'costs' => 'array',
            'received_at' => 'immutable_datetime',
            'subtotal' => 'integer',
            'discount' => 'integer',
            'cost_total' => 'integer',
            'total' => 'integer',
            'paid' => 'integer',
            'returned_total' => 'integer',
        ];
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
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
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
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * @return HasMany<PurchaseReceiptItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReceiptItem::class);
    }

    /**
     * @return HasMany<PurchaseReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    /**
     * Still owed to the supplier for this receipt.
     */
    public function remaining(): int
    {
        return $this->status === self::CANCELLED ? 0 : max(0, $this->total - $this->returned_total - $this->paid);
    }

    /**
     * Chưa thanh toán / Thanh toán một phần / Đã thanh toán.
     */
    public function paymentStatus(): string
    {
        return match (true) {
            $this->paid <= 0 => 'unpaid',
            $this->remaining() > 0 => 'partial',
            default => 'paid',
        };
    }

    public function activityLabel(): string
    {
        return "phiếu nhập hàng {$this->code}";
    }
}
