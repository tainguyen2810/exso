<?php

namespace Database\Seeders;

use App\Authorization\Permissions;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Demo store for development: owner admin@exso.test / password,
     * cashier thungan@exso.test / password.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => 'main'], ['name' => 'ITSO', 'status' => 'active']);

        Permissions::createDefaultRoles($tenant);

        app(TenantContext::class)->run($tenant, function (): void {
            settings()->set('store.name', 'ITSO');

            $main = Branch::query()->firstOrCreate(['code' => 'CN01'], ['name' => 'Chi nhánh chính', 'is_default' => true]);
            $second = Branch::query()->firstOrCreate(['code' => 'CN02'], ['name' => 'Chi nhánh Quận 3']);

            $owner = User::factory()->owner()->create(['name' => 'Chủ cửa hàng', 'email' => 'admin@exso.test']);
            $owner->branches()->attach([$main->id, $second->id]);
            $owner->assignRole('Quản trị');

            $cashier = User::factory()->create(['name' => 'Thu ngân', 'email' => 'thungan@exso.test']);
            $cashier->branches()->attach($main);
            $cashier->assignRole('Thu ngân');
        });
    }
}
