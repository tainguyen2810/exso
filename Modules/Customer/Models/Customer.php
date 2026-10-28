<?php

namespace Modules\Customer\Models;

use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Product\Models\PriceList;

/**
 * "Khách hàng". `debt` is what the customer owes the store and only
 * CustomerLedger changes it; the purchase totals are kept by the orders module.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $gender
 * @property CarbonImmutable|null $birthday
 * @property string|null $tax_code
 * @property string|null $website
 * @property string|null $address
 * @property string|null $province
 * @property string|null $district
 * @property string|null $ward
 * @property string|null $description
 * @property string|null $tags
 * @property int|null $group_id
 * @property int|null $assigned_to
 * @property string|null $price_column
 * @property int|null $price_list_id
 * @property int|null $discount_bp
 * @property int|null $payment_method_id
 * @property string|null $source
 * @property string|null $marketplace_id
 * @property string $status
 * @property int $debt
 * @property int $total_spent
 * @property int $orders_count
 * @property int $returned_quantity
 * @property CarbonImmutable|null $last_order_at
 * @property int|null $created_by
 * @property-read CustomerGroup|null $group
 * @property-read User|null $assignee
 * @property-read User|null $creator
 * @property-read PriceList|null $priceList
 * @property-read PaymentMethod|null $paymentMethod
 * @property-read Collection<int, CustomerAddress> $addresses
 * @property-read Collection<int, CustomerContact> $contacts
 * @property-read Collection<int, CustomerNote> $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['code', 'name', 'phone', 'email', 'gender', 'birthday', 'tax_code', 'website', 'address', 'province', 'district', 'ward', 'description', 'tags', 'group_id', 'assigned_to', 'price_column', 'price_list_id', 'discount_bp', 'payment_method_id', 'source', 'marketplace_id', 'status', 'created_by'])]
class Customer extends Model
{
    use BelongsToTenant, LogsActivity, SoftDeletes;

    public const GENDERS = ['male' => 'Nam', 'female' => 'Nữ', 'other' => 'Khác'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birthday' => 'immutable_date',
            'discount_bp' => 'integer',
            'debt' => 'integer',
            'total_spent' => 'integer',
            'orders_count' => 'integer',
            'returned_quantity' => 'integer',
            'last_order_at' => 'immutable_datetime',
        ];
    }

    /**
     * Digits only, so "0908 123 456" and "0908.123.456" are the same number.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (str_starts_with($digits, '84') && strlen($digits) === 11) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits === '' ? null : $digits;
    }

    /**
     * Customers the user may see: all with customers.view_all, otherwise
     * only those they are in charge of.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->can('customers.view_all') ? $query : $query->where('assigned_to', $user->id);
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->can('customers.view_all') || $this->assigned_to === $user->id;
    }

    /**
     * @return BelongsTo<CustomerGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'group_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return HasMany<CustomerAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class)->orderByDesc('is_default')->orderBy('id');
    }

    /**
     * @return HasMany<CustomerContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class)->orderBy('id');
    }

    /**
     * @return HasMany<CustomerNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class)->latest('id');
    }

    /**
     * @return HasMany<CustomerDebt, $this>
     */
    public function debts(): HasMany
    {
        return $this->hasMany(CustomerDebt::class);
    }

    /**
     * Price policy for new orders: the customer's own setting, else the
     * group's. A price list id, or a variant price column.
     *
     * @return array{column: string, price_list_id: int|null, label: string, discount_bp: int, payment_method_id: int|null}
     */
    public function salesDefaults(): array
    {
        $group = $this->group;
        $listId = $this->price_list_id ?? ($this->price_column === null ? $group?->price_list_id : null);
        $column = $this->price_column ?? $group->price_column ?? 'retail_price';
        $list = $listId === null ? null : PriceList::query()->find($listId);

        return [
            'column' => array_key_exists($column, CustomerGroup::PRICE_COLUMNS) ? $column : 'retail_price',
            'price_list_id' => $list?->id,
            'label' => $list->name ?? CustomerGroup::PRICE_COLUMNS[$column] ?? 'Giá bán lẻ',
            'discount_bp' => $this->discount_bp ?? $group->discount_bp ?? 0,
            'payment_method_id' => $this->payment_method_id ?? $group?->payment_method_id,
        ];
    }

    public function activityLabel(): string
    {
        return "khách hàng {$this->name}";
    }

    /**
     * @return list<string>
     */
    public function activityIgnored(): array
    {
        return ['debt', 'total_spent', 'orders_count', 'returned_quantity', 'last_order_at'];
    }
}
