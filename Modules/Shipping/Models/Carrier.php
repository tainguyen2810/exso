<?php

namespace Modules\Shipping\Models;

use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A shipping partner ("Đối tác vận chuyển"): a carrier connected by API
 * ("Đối tác tích hợp", e.g. GHN) or one the store contacts itself ("Đối tác
 * tự liên hệ": a shipper, a bus company...). Credentials are encrypted and
 * hidden; they never leave the server.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $kind
 * @property string|null $driver
 * @property string $name
 * @property string|null $phone
 * @property string|null $note
 * @property bool $is_active
 * @property bool $is_sandbox
 * @property array<string, mixed>|null $credentials
 * @property array<string, mixed>|null $settings
 * @property string|null $webhook_token
 * @property CarbonImmutable|null $connected_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Collection<int, Shipment> $shipments
 */
class Carrier extends Model
{
    use BelongsToTenant, LogsActivity, SoftDeletes;

    public const INTEGRATED = 'integrated';

    public const MANUAL = 'manual';

    /** @var list<string> */
    protected $fillable = ['code', 'kind', 'driver', 'name', 'phone', 'note', 'is_active', 'is_sandbox', 'settings', 'created_by'];

    /** @var list<string> */
    protected $hidden = ['credentials', 'webhook_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_sandbox' => 'boolean',
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'connected_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function isIntegrated(): bool
    {
        return $this->kind === self::INTEGRATED;
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

    /** "••••1a2b": enough to recognise a token without showing it. */
    public function maskedCredential(string $key): ?string
    {
        $value = $this->credential($key);

        return $value === null ? null : '••••'.mb_substr($value, -4);
    }

    public function activityLabel(): string
    {
        return "đối tác vận chuyển {$this->name}";
    }
}
