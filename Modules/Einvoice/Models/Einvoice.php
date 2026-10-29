<?php

namespace Modules\Einvoice\Models;

use App\Models\Branch;
use App\Models\User;
use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Order\Models\Order;

/**
 * An e-invoice ("hóa đơn điện tử", code EIN): made from an order as a
 * draft, issued through the provider, then only cancelled or adjusted by
 * another invoice ("hóa đơn điều chỉnh").
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property string $code
 * @property int|null $order_id
 * @property int|null $provider_id
 * @property string $kind
 * @property int|null $original_id
 * @property string|null $adjustment_type
 * @property string $status
 * @property string $transaction_uuid
 * @property string|null $template_code
 * @property string|null $series
 * @property string|null $invoice_no
 * @property string|null $lookup_code
 * @property CarbonImmutable|null $issued_at
 * @property string|null $buyer_name
 * @property string|null $buyer_company
 * @property string|null $buyer_tax_code
 * @property string|null $buyer_address
 * @property string|null $buyer_email
 * @property string|null $buyer_phone
 * @property string|null $buyer_id_number
 * @property string|null $buyer_bank_account
 * @property string|null $buyer_bank_name
 * @property string $payment_method
 * @property int $subtotal
 * @property int $discount
 * @property int $amount_before_tax
 * @property int $tax_amount
 * @property int $total
 * @property string|null $note
 * @property string|null $error
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property int|null $created_by
 * @property int|null $issued_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, EinvoiceItem> $items
 * @property-read Order|null $order
 * @property-read Branch $branch
 * @property-read EinvoiceProvider|null $provider
 * @property-read Einvoice|null $original
 * @property-read User|null $creator
 */
class Einvoice extends Model
{
    use BelongsToTenant, LogsActivity;

    public const DRAFT = 'draft';

    public const ISSUED = 'issued';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::DRAFT => 'Chưa phát hành',
        self::ISSUED => 'Đã phát hành',
        self::FAILED => 'Phát hành lỗi',
        self::CANCELLED => 'Đã hủy',
    ];

    public const ORIGINAL = 'original';

    public const ADJUSTMENT = 'adjustment';

    public const ADJUSTMENT_TYPES = [
        'increase' => 'Điều chỉnh tăng',
        'decrease' => 'Điều chỉnh giảm',
        'info' => 'Điều chỉnh thông tin',
    ];

    /** Tax rates on an invoice line (%, -1 KKKNT, -2 KCT). */
    public const TAX_RATES = [
        10 => '10%',
        8 => '8%',
        5 => '5%',
        0 => '0%',
        -1 => 'KKKNT',
        -2 => 'KCT',
    ];

    /** @var list<string> */
    protected $fillable = ['branch_id', 'order_id', 'buyer_name', 'buyer_company', 'buyer_tax_code', 'buyer_address', 'buyer_email', 'buyer_phone', 'buyer_id_number', 'buyer_bank_account', 'buyer_bank_name', 'payment_method', 'note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'discount' => 'integer',
            'amount_before_tax' => 'integer',
            'tax_amount' => 'integer',
            'total' => 'integer',
            'issued_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<EinvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(EinvoiceItem::class)->orderBy('sort');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<EinvoiceProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(EinvoiceProvider::class);
    }

    /**
     * @return BelongsTo<Einvoice, $this>
     */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Invoices of the user's branches.
     *
     * @param  Builder<Einvoice>  $query
     * @return Builder<Einvoice>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn($query->qualifyColumn('branch_id'), $user->branchIds());
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::FAILED], true);
    }

    /** "C26TAA/0000123" as printed on the invoice. */
    public function number(): ?string
    {
        return $this->invoice_no;
    }

    /**
     * @return list<string>
     */
    public function activityIgnored(): array
    {
        return ['error'];
    }

    public function activityLabel(): string
    {
        return "hóa đơn điện tử {$this->code}";
    }
}
