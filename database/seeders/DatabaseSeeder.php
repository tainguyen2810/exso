<?php

namespace Database\Seeders;

use App\Authorization\Permissions;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Settings\StoreDefaults;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Demo store for development: owner admin@exso.test / password, demo
     * account demo@exso.test, cashier thungan@exso.test (same password).
     */
    public function run(): void
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => 'main'], ['name' => 'ITSO', 'status' => 'active']);

        Permissions::createDefaultRoles($tenant);

        app(TenantContext::class)->run($tenant, function (): void {
            settings()->set('store.name', 'ITSO');
            StoreDefaults::install();

            $main = Branch::query()->firstOrCreate(['code' => 'CN01'], ['name' => 'Chi nhánh chính', 'is_default' => true]);

            $owner = User::factory()->owner()->create(['name' => 'Chủ cửa hàng', 'email' => 'admin@exso.test']);
            $owner->branches()->attach($main);
            $owner->assignRole('Quản trị');

            $this->call(DemoDataSeeder::class);
        });
    }
}
