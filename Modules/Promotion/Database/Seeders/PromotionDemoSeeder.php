<?php

namespace Modules\Promotion\Database\Seeders;

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Sequences\Sequence;
use App\Support\Settings\SettingsRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Modules\Customer\Models\Customer;
use Modules\Order\Services\OrderService;
use Modules\Product\Models\Category;
use Modules\Product\Models\ProductVariant;
use Modules\Promotion\Models\CouponBatch;
use Modules\Promotion\Models\LoyaltyTier;
use Modules\Promotion\Models\Promotion;
use Modules\Promotion\Services\LoyaltyService;

/**
 * Demo programs (chiết khấu, tặng quà), a coupon batch, member tiers and
 * two orders that use them (run inside the store's tenant, after the order demo).
 */
class PromotionDemoSeeder extends Seeder
{
    public function run(OrderService $orders, LoyaltyService $loyalty, SettingsRepository $settings): void
    {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        Auth::setUser($owner);

        $settings->setMany(['loyalty.enabled' => true, 'loyalty.earn_amount' => 100000, 'loyalty.redeem_value' => 1000]);

        foreach ([['Thành viên', 0, 10000], ['Bạc', 5000000, 12000], ['Vàng', 20000000, 15000]] as [$name, $spent, $multiplier]) {
            LoyaltyTier::query()->firstOrCreate(['name' => $name], ['min_spent' => $spent, 'earn_multiplier_bp' => $multiplier]);
        }

        $variant = fn (string $sku): int => (int) ProductVariant::query()->where('sku', $sku)->value('id');
        $accessories = (int) Category::query()->where('name', 'Phụ kiện')->value('id');

        $total = $this->promotion('Giảm giá theo tổng đơn tháng 11', 'order_total', [
            ['min' => 1000000, 'max' => null, 'type' => 'percent', 'value' => 500],
            ['min' => 3000000, 'max' => null, 'type' => 'percent', 'value' => 1000],
        ], ['usage_limit' => 500, 'ends_at' => now()->addMonth()]);
        $this->promotion('Phụ kiện giảm 10%', 'product', [
            ['ids' => [$accessories], 'min_qty' => 1, 'limit_qty' => null, 'type' => 'percent', 'value' => 1000],
        ], ['target_type' => 'category']);
        $gift = $this->promotion('Tặng cáp HDMI cho đơn từ 2 triệu', 'gift_order', [
            ['min_order' => 2000000, 'gift_ids' => [$variant('UG-HDMI2')], 'gift_qty' => 1],
        ]);
        $this->promotion('Mua 5 ram giấy A4 giảm 5.000đ/ram', 'quantity', [
            ['ids' => [$variant('DA-A4-70')], 'tiers' => [['from' => 5, 'to' => null, 'type' => 'amount', 'value' => 5000]]],
        ]);
        $this->promotion('Black Friday giảm 15%', 'order_total', [
            ['min' => 0, 'max' => null, 'type' => 'percent', 'value' => 1500],
        ], ['starts_at' => now()->addWeeks(4)->startOfDay(), 'ends_at' => now()->addWeeks(4)->endOfDay(), 'usage_limit' => 100]);
        $this->promotion('Giờ vàng buổi trưa', 'order_total', [
            ['min' => 500000, 'max' => null, 'type' => 'amount', 'value' => 30000],
        ], ['status' => Promotion::PAUSED, 'schedule' => ['time_from' => '11:00', 'time_to' => '13:00', 'weekdays' => [1, 2, 3, 4, 5], 'months' => [], 'days' => []]]);

        $batch = new CouponBatch([
            'name' => 'Khách mới giảm 50.000đ',
            'starts_at' => now()->subDay(),
            'value_type' => 'amount',
            'value' => 50000,
            'uses_per_code' => 100,
            'uses_per_customer' => 1,
            'combinable' => true,
            'min_order' => 300000,
            'created_by' => $owner->id,
        ]);
        $batch->code = Sequence::next('DPH', CouponBatch::class);
        $batch->save();
        $batch->coupons()->create(['code' => 'KHACHMOI']);

        foreach (['EXSO7K2M', 'EXSO9TQ4', 'EXSOH3VW'] as $code) {
            $batch->coupons()->create(['code' => $code]);
        }

        $main = (int) Branch::query()->where('is_default', true)->value('id');
        $cash = (int) PaymentMethod::query()->where('type', 'cash')->value('id');
        $customer = fn (string $phone): ?int => Customer::query()->where('phone', $phone)->value('id');

        // Đơn hoàn thành có chiết khấu tổng đơn và mã giảm giá: khách được tích điểm.
        $orders->create([
            'branch_id' => $main,
            'items' => [['variant_id' => $variant('KEY-K2'), 'quantity' => 1], ['variant_id' => $variant('LOGI-M331'), 'quantity' => 1]],
            'customer_id' => $customer('0912345678'),
            'delivery_method' => 'pickup',
            'promotion_ids' => [$total->id],
            'coupon_code' => 'KHACHMOI',
            'payments' => [['payment_method_id' => $cash, 'amount' => 10000000]],
        ], $owner, 'export');

        // Đơn đã duyệt có quà tặng.
        $orders->create([
            'branch_id' => $main,
            'items' => [['variant_id' => $variant('JAB-E230'), 'quantity' => 1], ['variant_id' => $variant('LOGI-M331'), 'quantity' => 1]],
            'customer_id' => $customer('0901234567'),
            'promotion_ids' => [$gift->id],
            'gifts' => ["{$gift->id}-0" => $variant('UG-HDMI2')],
        ], $owner, 'approve');

        $loyalty->refreshTiers();
    }

    /**
     * @param  list<array<string, mixed>>  $conditions
     * @param  array<string, mixed>  $attributes
     */
    protected function promotion(string $name, string $method, array $conditions, array $attributes = []): Promotion
    {
        $promotion = new Promotion([
            'name' => $name,
            'kind' => Promotion::METHODS[$method][0],
            'method' => $method,
            'status' => Promotion::ACTIVE,
            'starts_at' => now()->subDays(3)->startOfDay(),
            'conditions' => $conditions,
            'created_by' => Auth::id(),
            ...$attributes,
        ]);
        $promotion->code = Sequence::next('KM', Promotion::class);
        $promotion->save();

        return $promotion;
    }
}
