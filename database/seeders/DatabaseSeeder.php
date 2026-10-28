<?php

namespace Database\Seeders;

use App\Authorization\Permissions;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Settings\StoreDefaults;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\Product\Database\Seeders\ProductDemoSeeder;
use Modules\Purchase\Database\Seeders\PurchaseDemoSeeder;

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
            StoreDefaults::install();

            $main = Branch::query()->firstOrCreate(['code' => 'CN01'], ['name' => 'Chi nhánh chính', 'is_default' => true]);
            $second = Branch::query()->firstOrCreate(['code' => 'CN02'], ['name' => 'Chi nhánh Quận 3']);

            $owner = User::factory()->owner()->create(['name' => 'Chủ cửa hàng', 'email' => 'admin@exso.test']);
            $owner->branches()->attach([$main->id, $second->id]);
            $owner->assignRole('Quản trị');

            $cashier = User::factory()->create(['name' => 'Thu ngân', 'email' => 'thungan@exso.test']);
            $cashier->branches()->attach($main);
            $cashier->assignRole('Thu ngân');

            $demo = [
                ['Nguyễn Thị Lan', 'Thu ngân', $second, 'active'],
                ['Trần Văn Hùng', 'Nhân viên kho', $main, 'active'],
                ['Lê Minh Tuấn', 'Nhân viên kho', $second, 'active'],
                ['Phạm Thu Hà', 'Kế toán', $main, 'active'],
                ['Võ Quốc Bảo', 'Thu ngân', $main, 'inactive'],
            ];

            foreach ($demo as $index => [$name, $role, $branch, $status]) {
                $user = User::factory()->create([
                    'name' => $name,
                    'email' => 'nhanvien'.($index + 1).'@exso.test',
                    'phone' => '09080000'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'status' => $status,
                ]);
                $user->branches()->attach($branch);
                $user->assignRole($role);
                $user->roleAssignments()->create(['role_id' => Role::findByName($role)->id, 'branch_id' => null]);

                if ($role === 'Nhân viên kho') {
                    $user->givePermissionTo('products.view_cost');
                }
            }

            // Example of a role limited to one branch: cashier at the main
            // branch, accountant only at Quận 3; can see cost prices.
            $mixed = User::factory()->create(['name' => 'Đỗ Hoàng Nam', 'email' => 'nhanvien6@exso.test', 'phone' => '0908000006']);
            $mixed->branches()->attach([$main->id, $second->id]);
            $mixed->assignRole(['Thu ngân', 'Kế toán']);
            $mixed->roleAssignments()->create(['role_id' => Role::findByName('Thu ngân')->id, 'branch_id' => $main->id]);
            $mixed->roleAssignments()->create(['role_id' => Role::findByName('Kế toán')->id, 'branch_id' => $second->id]);
            $mixed->givePermissionTo('products.view_cost');

            $this->call(ProductDemoSeeder::class);
            $this->call(PurchaseDemoSeeder::class);
        });
    }
}
