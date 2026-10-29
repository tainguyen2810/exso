<?php

namespace Modules\Marketplace\Database\Seeders;

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Marketplace\Drivers\PlatformOrder;
use Modules\Marketplace\Drivers\ShopeeDriver;
use Modules\Marketplace\Models\MarketplaceListing;
use Modules\Marketplace\Models\MarketplaceShop;
use Modules\Marketplace\Services\MarketplaceOrderService;
use Modules\Product\Models\ProductVariant;

/**
 * A demo Shopee shop with linked products and orders at several steps
 * (run inside the store's tenant). The tokens are fake: syncing with
 * Shopee needs connecting a real shop.
 */
class MarketplaceDemoSeeder extends Seeder
{
    public function run(MarketplaceOrderService $orders): void
    {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        Auth::setUser($owner);

        $shop = new MarketplaceShop([
            'platform' => 'shopee',
            'external_id' => '880012345',
            'name' => 'EXSO Official Store',
            'status' => MarketplaceShop::ACTIVE,
            'branch_id' => (int) Branch::query()->where('is_default', true)->value('id'),
            'settings' => [
                'payment_method_id' => PaymentMethod::query()->where('name', 'Ví Shopee')->value('id'),
                'sync_orders' => true,
                'sync_stock' => false,
            ],
            'created_by' => $owner->id,
        ]);
        $shop->credentials = ['partner_id' => '2001234', 'partner_key' => Str::random(32), 'access_token' => Str::random(32), 'refresh_token' => Str::random(32)];
        $shop->forceFill([
            'token_expires_at' => now()->addHours(4),
            'refresh_expires_at' => now()->addDays(30),
            'webhook_token' => Str::random(48),
            'connected_at' => now()->subDays(3),
            'orders_synced_at' => now()->subMinutes(2),
            'products_synced_at' => now()->subHour(),
        ])->save();

        $listings = [
            ['20001', '0', 'Chuột không dây Logitech M331 Silent Plus', null, 'LOGI-M331', 379000],
            ['20002', '0', 'Cáp HDMI 2.0 Ugreen 2m 4K60Hz', null, 'UG-HDMI2', 149000],
            ['20003', '30011', 'Áo thun EXSO', 'Đen, M', 'AT-DEN-M', 175000],
            ['20003', '30012', 'Áo thun EXSO', 'Đen, L', 'AT-DEN-L', 175000],
            ['20004', '0', 'Bàn phím cơ không dây 87 phím', null, 'KB-87-WL', 890000],
        ];

        foreach ($listings as [$item, $model, $name, $modelName, $sku, $price]) {
            $listing = MarketplaceListing::query()->create([
                'shop_id' => $shop->id, 'item_id' => $item, 'model_id' => $model, 'name' => $name, 'model_name' => $modelName,
                'sku' => $sku, 'price' => $price, 'stock' => 10, 'status' => 'NORMAL',
            ]);
            $variant = ProductVariant::query()->where('sku', $sku)->first();

            if ($variant !== null) {
                $listing->forceFill(['variant_id' => $variant->id, 'linked_at' => now()->subDays(3), 'pushed_stock' => 10, 'pushed_at' => now()->subHour()])->save();
            }
        }

        $line = fn (string $item, string $model, string $sku, string $name, int $quantity, int $price): array => [
            'item_id' => $item, 'model_id' => $model, 'sku' => $sku, 'name' => $name, 'quantity' => $quantity, 'price' => $price, 'original_price' => $price + 20000,
        ];
        $order = function (string $id, string $status, array $items, string $buyer, int $minutes, ?string $tracking = null) use ($orders, $shop): void {
            $placed = CarbonImmutable::now()->subMinutes($minutes);
            $orders->sync($shop, new PlatformOrder(
                id: $id,
                status: $status,
                state: ShopeeDriver::STATUSES[$status][1],
                items: $items,
                total: array_sum(array_map(fn (array $item): int => $item['price'] * $item['quantity'], $items)),
                buyerId: (string) crc32($buyer),
                buyerUsername: $buyer,
                recipientName: mb_substr($buyer, 0, 1).'*****',
                recipientPhone: '******'.random_int(10, 99),
                address: '***, Phường Bến Nghé, Quận 1, TP. Hồ Chí Minh',
                carrier: 'SPX Express',
                trackingCode: $tracking,
                placedAt: $placed,
                updatedAt: $placed->addMinutes(3),
            ));
        };

        $order('241029ABCD01', 'COMPLETED', [$line('20001', '0', 'LOGI-M331', 'Chuột không dây Logitech M331 Silent Plus', 1, 379000)], 'minhanh.ng', 60 * 30, 'SPXVN041234567');
        $order('241029ABCD02', 'SHIPPED', [$line('20002', '0', 'UG-HDMI2', 'Cáp HDMI 2.0 Ugreen 2m 4K60Hz', 2, 149000)], 'tuan_pc', 60 * 20, 'SPXVN041234588');
        $order('241029ABCD03', 'READY_TO_SHIP', [
            $line('20003', '30011', 'AT-DEN-M', 'Áo thun EXSO Đen, M', 2, 175000),
            $line('20001', '0', 'LOGI-M331', 'Chuột không dây Logitech M331 Silent Plus', 1, 379000),
        ], 'hoa.tran99', 45);
        $order('241029ABCD04', 'READY_TO_SHIP', [$line('20004', '0', 'KB-87-WL', 'Bàn phím cơ không dây 87 phím', 1, 890000)], 'gamer.vn', 12);
        $order('241029ABCD05', 'CANCELLED', [$line('20002', '0', 'UG-HDMI2', 'Cáp HDMI 2.0 Ugreen 2m 4K60Hz', 1, 149000)], 'lanphuong', 60 * 5);
    }
}
