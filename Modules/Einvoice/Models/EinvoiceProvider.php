<?php

namespace Modules\Einvoice\Models;

use App\Support\Activity\LogsActivity;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Connection to an e-invoice provider ("Đơn vị phát hành"). The account is
 * encrypted and hidden; it never leaves the server.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $driver
 * @property string $name
 * @property bool $is_active
 * @property bool $is_sandbox
 * @property array<string, mixed>|null $credentials
 * @property array<string, mixed>|null $settings
 * @property CarbonImmutable|null $connected_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class EinvoiceProvider extends Model
{
    use BelongsToTenant, LogsActivity;

    /** @var list<string> */
    protected $fillable = ['driver', 'name', 'is_active', 'is_sandbox', 'settings', 'created_by'];

    /** @var list<string> */
    protected $hidden = ['credentials'];

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

    public function setting(string $key, mixed $default = null): mixed
    {
        $value = $this->settings[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public function activityLabel(): string
    {
        return "kết nối hóa đơn điện tử {$this->name}";
    }
}
