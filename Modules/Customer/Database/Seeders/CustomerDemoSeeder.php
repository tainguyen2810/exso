<?php

namespace Modules\Customer\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Customer\Models\CustomerGroup;
use Modules\Customer\Services\CustomerService;

/**
 * Demo customers in each group (run inside the store's tenant).
 */
class CustomerDemoSeeder extends Seeder
{
    public function run(CustomerService $customers): void
    {
        CustomerGroup::installDefaults();
        $group = fn (string $code): ?int => CustomerGroup::query()->where('code', $code)->value('id');
        $cashier = User::query()->where('email', 'like', 'thungan@%')->value('id');

        foreach ([
            ['Nguyễn Văn An', '0901234567', 'BANLE', '12 Lê Lợi', 'Quận 1', 'Hồ Chí Minh', 'male'],
            ['Trần Thị Bích', '0912345678', 'VIP', '45 Nguyễn Huệ', 'Quận 1', 'Hồ Chí Minh', 'female'],
            ['Công ty TNHH Minh Phát', '02838123456', 'DAILY', '88 Cộng Hòa', 'Tân Bình', 'Hồ Chí Minh', null],
            ['Cửa hàng Tin học Hoàng Long', '0987654321', 'BANBUON', '210 Trần Hưng Đạo', 'Quận 5', 'Hồ Chí Minh', null],
            ['Lê Hoàng Nam', '0933111222', 'BANLE', '5 Hai Bà Trưng', 'Hoàn Kiếm', 'Hà Nội', 'male'],
            ['Phạm Ngọc Mai', '0977888999', 'BANLE', null, null, null, 'female'],
        ] as $index => [$name, $phone, $code, $address, $district, $province, $gender]) {
            $customer = $customers->save(null, [
                'name' => $name,
                'phone' => $phone,
                'group_id' => $group($code),
                'address' => $address,
                'district' => $district,
                'province' => $province,
                'gender' => $gender,
                'assigned_to' => $index % 2 === 0 ? $cashier : null,
                'status' => 'active',
            ]);

            if ($address !== null) {
                $customer->addresses()->create([
                    'name' => $name,
                    'phone' => $phone,
                    'address' => $address,
                    'district' => $district,
                    'province' => $province,
                    'is_default' => true,
                ]);
            }
        }

        // A marketplace buyer comes masked; the platform id is kept.
        $customers->save(null, ['name' => 'L******h', 'source' => 'Shopee', 'marketplace_id' => 'shopee_88213471', 'status' => 'active']);
    }
}
