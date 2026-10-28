<?php

namespace Modules\Purchase\Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Modules\Inventory\Services\StockDocumentService;
use Modules\Product\Models\ProductVariant;
use Modules\Purchase\Models\Supplier;
use Modules\Purchase\Services\PurchaseService;

/**
 * Demo suppliers, purchases and stock documents for the sample store (run
 * inside the store's tenant, after the product demo).
 */
class PurchaseDemoSeeder extends Seeder
{
    public function run(PurchaseService $purchases, StockDocumentService $documents): void
    {
        $owner = User::query()->where('is_owner', true)->first();

        if ($owner !== null) {
            Auth::setUser($owner);
        }

        $main = (int) Branch::query()->where('is_default', true)->value('id');
        $second = (int) Branch::query()->where('is_default', false)->value('id');
        $variant = fn (string $sku): int => (int) ProductVariant::query()->where('sku', $sku)->value('id');

        $suppliers = [];

        foreach ([
            ['Công ty TNHH Phân phối Digiworld', '02839290059', 'digiworld@example.com', '195-197 Nguyễn Thái Bình, Quận 1, TP.HCM', '0302498419'],
            ['Công ty CP Thế Giới Phụ Kiện', '02862706868', 'tgpk@example.com', '120 Lý Thường Kiệt, Quận 10, TP.HCM', null],
            ['Ugreen Việt Nam', '0901234567', null, 'Hà Nội', null],
        ] as [$name, $phone, $email, $address, $taxCode]) {
            $suppliers[] = Supplier::query()->create([
                'code' => $purchases->supplierCode(),
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'address' => $address,
                'tax_code' => $taxCode,
                'status' => 'active',
            ]);
        }

        $line = fn (string $sku, int $quantity, int $price, int $discount = 0): array => [
            'variant_id' => $variant($sku), 'quantity' => $quantity, 'price' => $price, 'discount' => $discount,
        ];

        $order = $purchases->saveOrder(null, [
            'supplier_id' => $suppliers[0]->id,
            'branch_id' => $main,
            'expected_at' => now()->addDays(5)->toDateString(),
            'items' => [$line('LOGI-M331', 20, 280000), $line('KEY-K2', 5, 1650000)],
        ]);

        $received = $purchases->saveReceipt(null, [
            'supplier_id' => $suppliers[0]->id,
            'branch_id' => $main,
            'purchase_order_id' => $order->id,
            'reference' => 'HD-0012345',
            'discount' => 100000,
            'costs' => [['name' => 'Vận chuyển', 'amount' => 150000]],
            'items' => [$line('LOGI-M331', 10, 280000), $line('KEY-K2', 5, 1650000, 50000)],
        ], true);
        $purchases->pay($received, 5000000, 'transfer', 'Chuyển khoản đợt 1');

        $purchases->saveReceipt(null, [
            'supplier_id' => $suppliers[2]->id,
            'branch_id' => $main,
            'note' => 'Chờ hàng về kiểm đủ mới nhập kho',
            'items' => [$line('UG-HDMI2', 50, 90000)],
        ]);

        $documents->saveStocktake(null, [
            'branch_id' => $main,
            'note' => 'Kiểm kho cuối tháng',
            'items' => [
                ['variant_id' => $variant('UG-HDMI2'), 'actual_quantity' => 58, 'reason' => 'Thất lạc'],
                ['variant_id' => $variant('LOGI-M331'), 'actual_quantity' => 35, 'reason' => null],
            ],
        ]);

        if ($second > 0) {
            $transfer = $documents->saveTransfer(null, [
                'from_branch_id' => $main,
                'to_branch_id' => $second,
                'note' => 'Bổ sung hàng cho chi nhánh Quận 3',
                'items' => [['variant_id' => $variant('LOGI-M331'), 'quantity' => 5], ['variant_id' => $variant('UG-HDMI2'), 'quantity' => 10]],
            ]);
            $documents->ship($transfer);
        }

        Auth::forgetUser();
    }
}
