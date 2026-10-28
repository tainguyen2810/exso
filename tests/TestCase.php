<?php

namespace Tests;

use App\Authorization\Permissions;
use App\Models\Tenant;
use App\Support\Settings\StoreDefaults;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * The store every database test runs as (standalone mode resolves it automatically).
     */
    protected ?Tenant $tenant = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            $this->tenant = Tenant::factory()->create(['slug' => 'main']);
            Permissions::createDefaultRoles($this->tenant);
            app(TenantContext::class)->run($this->tenant, fn () => StoreDefaults::install());
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
