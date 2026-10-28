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

if (! function_exists('inertia_page_path')) {
    /**
     * Source file of an Inertia page, relative to the project root, for @vite.
     * Core pages live in resources/js/pages, module pages in
     * Modules/<Module>/resources/js/pages.
     */
    function inertia_page_path(string $component): string
    {
        $core = "resources/js/pages/{$component}.tsx";

        if (is_file(base_path($core))) {
            return $core;
        }

        foreach (glob(base_path("Modules/*/resources/js/pages/{$component}.tsx")) ?: [] as $file) {
            return ltrim(str_replace(base_path(), '', $file), '/\\');
        }

        return $core;
    }
}
