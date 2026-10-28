<?php

use App\Support\Settings\SettingsRepository;
use App\Tenancy\TenantContext;

if (! function_exists('settings')) {
    /**
     * Read a tenant setting, or the repository when called without a key.
     */
    function settings(?string $key = null, mixed $default = null): mixed
    {
        $repository = app(SettingsRepository::class);

        return $key === null ? $repository : $repository->get($key, $default);
    }
}

if (! function_exists('tenancy')) {
    function tenancy(): TenantContext
    {
        return app(TenantContext::class);
    }
}
