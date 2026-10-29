<?php

namespace Modules\Marketplace\Models;

use App\Models\Branch;
use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A marketplace shop connected to the store ("gian hàng", e.g. a Shopee
 * shop). Partner key and tokens are encrypted and hidden; they never leave
 * the server.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $platform
 * @property string|null $external_id
 * @property string $name
 * @property string $status
 * @property int $branch_id
 * @property bool $is_sandbox
 * @property array<string, mixed>|null $credentials
 * @property CarbonImmutable|null $token_expires_at
 * @property CarbonImmutable|null $refresh_expires_at
 * @property array<string, mixed>|null $settings
 * @property string|null $webhook_token
 * @property CarbonImmutable|null $orders_synced_at
 * @property CarbonImmutable|null $products_synced_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $connected_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Branch $branch
 * @property-read Collection<int, MarketplaceListing> $listings
 * @property-read Collection<int, MarketplaceOrder> $orders
 */
class MarketplaceShop extends Model
{
    use BelongsToTenant, LogsActivity;

    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const DISCONNECTED = 'disconnected';

    public const STATUSES = [
        self::PENDING => 'Chờ cấp quyền',
        self::ACTIVE => 'Đang hoạt động',
        self::EXPIRED => 'Hết hạn',
        self::DISCONNECTED => 'Ngắt kết nối',
    ];

    /** @var list<string> */
    protected $fillable = ['platform', 'external_id', 'name', 'status', 'branch_id', 'is_sandbox', 'settings', 'created_by'];

    /** @var list<string> */
    protected $hidden = ['credentials', 'webhook_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_sandbox' => 'boolean',
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'token_expires_at' => 'immutable_datetime',
            'refresh_expires_at' => 'immutable_datetime',
            'orders_synced_at' => 'immutable_datetime',
            'products_synced_at' => 'immutable_datetime',
            'connected_at' => 'immutable_datetime',
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
     * @return HasMany<MarketplaceListing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class, 'shop_id');
    }

    /**
     * @return HasMany<MarketplaceOrder, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(MarketplaceOrder::class, 'shop_id');
    }

    /**
     * Shops whose orders go to one of the user's branches.
     *
     * @param  Builder<MarketplaceShop>  $query
     * @return Builder<MarketplaceShop>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn($query->qualifyColumn('branch_id'), $user->branchIds());
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** "••••1a2b": enough to recognise a key without showing it. */
    public function maskedCredential(string $key): ?string
    {
        $value = $this->credential($key);

        return $value === null ? null : '••••'.mb_substr($value, -4);
    }

    /**
     * Branches whose "Có thể bán" is pushed to the platform (default: the
     * branch receiving the orders).
     *
     * @return list<int>
     */
    public function stockBranchIds(): array
    {
        $ids = array_values(array_unique(array_map('intval', (array) $this->setting('stock_branch_ids', []))));

        return $ids === [] ? [$this->branch_id] : $ids;
    }

    /**
     * @return list<string>
     */
    public function activityIgnored(): array
    {
        return ['token_expires_at', 'refresh_expires_at', 'orders_synced_at', 'products_synced_at', 'last_error'];
    }

    public function activityLabel(): string
    {
        return "gian hàng {$this->name}";
    }
}
