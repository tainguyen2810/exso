<?php

namespace App\Support\Settings;

use App\Models\Setting;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Business configuration stored per tenant in the settings table.
 * Keys use dot notation grouped by area, e.g. "store.name", "sales.allow_negative_stock".
 */
class SettingsRepository
{
    public function __construct(protected TenantContext $tenancy) {}

    /**
     * The stored value, else $default, else the built-in default from
     * StoreSettings::DEFAULTS.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $all = $this->all()) ? $all[$key] : ($default ?? StoreSettings::default($key));
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        $this->forget();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        $this->forget();
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $tenantId = $this->tenancy->id();

        if ($tenantId === null) {
            return [];
        }

        return Cache::rememberForever($this->cacheKey($tenantId), fn (): array => Setting::query()
            ->pluck('value', 'key')
            ->all());
    }

    public function forget(): void
    {
        if (($tenantId = $this->tenancy->id()) !== null) {
            Cache::forget($this->cacheKey($tenantId));
        }
    }

    protected function cacheKey(int $tenantId): string
    {
        return "tenants.{$tenantId}.settings";
    }
}
