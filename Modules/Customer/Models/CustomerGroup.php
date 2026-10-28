<?php

namespace Modules\Customer\Models;

use App\Models\PaymentMethod;
use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Product\Models\PriceList;

/**
 * "Nhóm khách hàng": decides the price policy and default discount of
 * new orders for its customers (e.g. "Đại lý" sells at the wholesale price).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $price_column retail_price | wholesale_price
 * @property int|null $price_list_id
 * @property int $discount_bp
 * @property int|null $payment_method_id
 * @property bool $is_default
 * @property-read PriceList|null $priceList
 * @property-read PaymentMethod|null $paymentMethod
 * @property-read int|null $customers_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['code', 'name', 'description', 'price_column', 'price_list_id', 'discount_bp', 'payment_method_id', 'is_default'])]
class CustomerGroup extends Model
{
    use BelongsToTenant, LogsActivity, SoftDeletes;

    /** Price columns a group can sell at (besides a price list). */
    public const PRICE_COLUMNS = [
        'retail_price' => 'Giá bán lẻ',
        'wholesale_price' => 'Giá bán buôn',
    ];

    /**
     * Groups every store starts with, like Sapo (+ "Đại lý" at the wholesale price).
     *
     * @var list<array{code: string, name: string, price_column: string, is_default?: bool}>
     */
    public const DEFAULTS = [
        ['code' => 'BANLE', 'name' => 'Bán lẻ', 'price_column' => 'retail_price', 'is_default' => true],
        ['code' => 'BANBUON', 'name' => 'Bán buôn', 'price_column' => 'wholesale_price'],
        ['code' => 'DAILY', 'name' => 'Đại lý', 'price_column' => 'wholesale_price'],
        ['code' => 'VIP', 'name' => 'Vip', 'price_column' => 'retail_price'],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['discount_bp' => 'integer', 'is_default' => 'boolean'];
    }

    /**
     * Create the default groups that are missing (matched by code). Safe to
     * run again; must run inside the store's tenant context.
     */
    public static function installDefaults(): void
    {
        foreach (self::DEFAULTS as $group) {
            self::query()->withTrashed()->firstOrCreate(['code' => $group['code']], $group);
        }
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'group_id');
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

    public function priceLabel(): string
    {
        return $this->priceList->name ?? self::PRICE_COLUMNS[$this->price_column] ?? $this->price_column;
    }

    public function activityLabel(): string
    {
        return "nhóm khách hàng {$this->name}";
    }
}
