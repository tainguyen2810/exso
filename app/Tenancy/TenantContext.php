<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\Schema;

/**
 * Holds the tenant (store) the current request, job or command works for.
 *
 * In standalone mode the single tenant is resolved lazily, so artisan
 * commands and queued jobs work without extra setup. In SaaS mode nothing
 * is resolved implicitly: without a tenant, tenant-scoped queries return
 * no rows (fail closed).
 */
class TenantContext
{
    protected ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        if ($this->tenant === null && self::isStandalone()) {
            $this->tenant = $this->resolveStandaloneTenant();
        }

        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->get()?->id;
    }

    public function check(): bool
    {
        return $this->get() !== null;
    }

    /**
     * Run the callback as the given tenant, then restore the previous one.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }

    public static function isStandalone(): bool
    {
        return config('tenancy.mode') !== 'saas';
    }

    protected function resolveStandaloneTenant(): ?Tenant
    {
        if (! Schema::hasTable('tenants')) {
            return null;
        }

        return Tenant::query()->orderBy('id')->first();
    }
}
