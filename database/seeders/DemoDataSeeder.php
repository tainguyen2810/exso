<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Customer\Database\Seeders\CustomerDemoSeeder;
use Modules\Einvoice\Database\Seeders\EinvoiceDemoSeeder;
use Modules\Marketplace\Database\Seeders\MarketplaceDemoSeeder;
use Modules\Order\Database\Seeders\OrderDemoSeeder;
use Modules\Product\Database\Seeders\ProductDemoSeeder;
use Modules\Promotion\Database\Seeders\PromotionDemoSeeder;
use Modules\Purchase\Database\Seeders\PurchaseDemoSeeder;
use Modules\Report\Database\Seeders\ReportDemoSeeder;
use Modules\Shipping\Database\Seeders\ShippingDemoSeeder;

/**
 * Sample data for the current store: a second branch, demo staff and data
 * for every module. Runs inside a tenant that already has its owner and
 * default branch; used by `db:seed` and by the installer's "dữ liệu mẫu"
 * option. Does not use factories, so it works without dev dependencies.
 */
class DemoDataSeeder extends Seeder
{
    /** Domain of the demo accounts, e.g. demo@exso.test. */
    public string $domain = 'exso.test';

    /** Password of every demo account. */
    public string $password = 'password';

    public function run(): void
    {
        $main = Branch::query()->where('is_default', true)->firstOrFail();
        $second = Branch::query()->firstOrCreate(['code' => 'CN02'], ['name' => 'Chi nhánh Quận 3']);

        User::query()->where('is_owner', true)->get()
            ->each(fn (User $owner) => $owner->branches()->syncWithoutDetaching([$main->id, $second->id]));

        // The demo account: a manager of both branches who sees costs.
        $demo = $this->user('Tài khoản demo', 'demo', '0908000000');
        $demo->branches()->syncWithoutDetaching([$main->id, $second->id]);
        $this->assign($demo, 'Quản trị');
        $demo->givePermissionTo(['products.view_cost', 'inventory.view_transfer_price']);

        $cashier = $this->user('Thu ngân', 'thungan', null);
        $cashier->branches()->syncWithoutDetaching([$main->id]);
        $this->assign($cashier, 'Thu ngân');

        $staff = [
            ['Nguyễn Thị Lan', 'Thu ngân', $second, 'active'],
            ['Trần Văn Hùng', 'Nhân viên kho', $main, 'active'],
            ['Lê Minh Tuấn', 'Nhân viên kho', $second, 'active'],
            ['Phạm Thu Hà', 'Kế toán', $main, 'active'],
            ['Võ Quốc Bảo', 'Thu ngân', $main, 'inactive'],
        ];

        foreach ($staff as $index => [$name, $role, $branch, $status]) {
            $user = $this->user($name, 'nhanvien'.($index + 1), '09080000'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT), $status);
            $user->branches()->syncWithoutDetaching([$branch->id]);
            $this->assign($user, $role);

            if ($role === 'Nhân viên kho') {
                $user->givePermissionTo('products.view_cost');
            }
        }

        // Example of a role limited to one branch: cashier at the main
        // branch, accountant only at Quận 3; can see cost prices.
        $mixed = $this->user('Đỗ Hoàng Nam', 'nhanvien6', '0908000006');
        $mixed->branches()->syncWithoutDetaching([$main->id, $second->id]);
        $mixed->assignRole(['Thu ngân', 'Kế toán']);
        $mixed->roleAssignments()->firstOrCreate(['role_id' => Role::findByName('Thu ngân')->id, 'branch_id' => $main->id]);
        $mixed->roleAssignments()->firstOrCreate(['role_id' => Role::findByName('Kế toán')->id, 'branch_id' => $second->id]);
        $mixed->givePermissionTo('products.view_cost');

        $this->call([
            ProductDemoSeeder::class,
            PurchaseDemoSeeder::class,
            CustomerDemoSeeder::class,
            OrderDemoSeeder::class,
            PromotionDemoSeeder::class,
            ShippingDemoSeeder::class,
            MarketplaceDemoSeeder::class,
            EinvoiceDemoSeeder::class,
            ReportDemoSeeder::class,
        ]);
    }

    protected function user(string $name, string $login, ?string $phone, string $status = 'active'): User
    {
        $user = User::query()->firstOrNew(['email' => "{$login}@{$this->domain}"]);
        $user->fill(['name' => $name, 'phone' => $phone, 'password' => $this->password, 'status' => $status]);
        $user->email_verified_at ??= now();
        $user->save();

        return $user;
    }

    protected function assign(User $user, string $role): void
    {
        $user->assignRole($role);
        $user->roleAssignments()->firstOrCreate(['role_id' => Role::findByName($role)->id, 'branch_id' => null]);
    }
}
