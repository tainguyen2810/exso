<?php

namespace Modules\Order\Database\Seeders;

use App\Models\Branch;
use App\Models\OrderReason;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Modules\Customer\Models\Customer;
use Modules\Order\Services\OrderService;
use Modules\Order\Services\PosService;
use Modules\Order\Services\ReturnService;
use Modules\Product\Models\ProductVariant;

/**
 * Demo orders at every step, a return and a cashier shift (run inside the
 * store's tenant, after the product, purchase and customer demos).
 */
class OrderDemoSeeder extends Seeder
{
    public function run(OrderService $orders, ReturnService $returns, PosService $pos): void
    {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        $cashier = User::query()->where('email', 'like', 'thungan@%')->firstOrFail();
        Auth::setUser($owner);

        $main = (int) Branch::query()->where('is_default', true)->value('id');
        $customer = fn (string $phone): ?int => Customer::query()->where('phone', $phone)->value('id');
        $cash = (int) PaymentMethod::query()->where('type', 'cash')->value('id');
        // Sample bank account so the demo shows VietQR codes.
        PaymentMethod::query()->where('type', 'bank')->whereNull('account_number')->limit(1)->update([
            'bank_bin' => '970436', 'account_number' => '0123456789', 'account_name' => 'CONG TY TNHH ITSO',
        ]);
        $transfer = (int) (PaymentMethod::query()->where('type', 'bank')->value('id') ?? $cash);
        $cancelReason = OrderReason::query()->where('type', 'cancel')->value('id');
        $returnReason = OrderReason::query()->where('type', 'return')->value('id');

        // Đặt hàng, chờ duyệt.
        $orders->create([
            'branch_id' => $main,
            'items' => $this->lines([['LOGI-M331', 2], ['UG-HDMI2', 3]]),
            'customer_id' => $customer('0901234567'),
            'shipping_fee' => 30000,
        ], $owner);

        // Đã duyệt, chờ đóng gói; đại lý mua giá buôn.
        $orders->create([
            'branch_id' => $main,
            'items' => $this->lines([['KEY-K2', 2]]),
            'customer_id' => $customer('02838123456'),
            'note' => 'Giao trong giờ hành chính',
        ], $owner, 'approve');

        // Đã đóng gói, chờ xuất kho, khách đã chuyển khoản.
        $packed = $orders->create([
            'branch_id' => $main,
            'items' => $this->lines([['JAB-E230', 1]]),
            'customer_id' => $customer('0912345678'),
            'payments' => [['payment_method_id' => $transfer, 'amount' => 1850000]],
        ], $owner, 'approve');
        $orders->pack($packed);

        // Đang giao qua đối tác ngoài, thu hộ khi giao.
        $orders->create([
            'branch_id' => $main,
            'items' => $this->lines([['DA-A4-70', 5], ['LOGI-M331', 1]]),
            'customer_id' => $customer('0933111222'),
            'delivery_method' => 'external',
            'carrier_name' => 'Viettel Post',
            'tracking_code' => 'VTP123456789',
            'shipping_fee' => 25000,
        ], $owner, 'export');

        // Hoàn thành, sau đó khách trả 1 sản phẩm và được hoàn tiền.
        $done = $orders->create([
            'branch_id' => $main,
            'items' => $this->lines([['UG-HDMI2', 4]]),
            'customer_id' => $customer('0987654321'),
            'delivery_method' => 'pickup',
            'payments' => [['payment_method_id' => $cash, 'amount' => 10000000]],
        ], $owner, 'export');
        $item = $done->items()->firstOrFail();
        $returns->create($done, [$item->id => 1], $returnReason, 'Khách đổi ý', [
            'payment_method_id' => $cash,
            'amount' => $returns->unitRefund($done, $item),
        ]);

        // Đơn hủy.
        $cancelled = $orders->create([
            'branch_id' => $main,
            'items' => $this->lines([['LOGI-M331', 1]]),
            'customer_id' => $customer('0977888999'),
        ], $owner);
        $orders->cancel($cancelled, $cancelReason, 'Khách không nghe máy');

        // Ca thu ngân đang mở với 2 đơn bán tại quầy.
        Auth::setUser($cashier);
        $shift = $pos->openShift($cashier, $main, 1000000);
        $pos->sell([
            'branch_id' => $main,
            'items' => $this->lines([['UG-HDMI2', 1], ['DA-A4-70', 2]]),
            'payments' => [['payment_method_id' => $cash, 'amount' => 500000]],
        ], $cashier, $shift);
        $pos->sell([
            'branch_id' => $main,
            'items' => $this->lines([['LOGI-M331', 1]]),
            'customer_id' => $customer('0901234567'),
            'payments' => [['payment_method_id' => $transfer, 'amount' => 390000]],
        ], $cashier, $shift);
        Auth::setUser($owner);
    }

    /**
     * @param  list<array{0: string, 1: int}>  $items  SKU and quantity
     * @return list<array{variant_id: int, quantity: int}>
     */
    protected function lines(array $items): array
    {
        return array_map(fn (array $item): array => [
            'variant_id' => (int) ProductVariant::query()->where('sku', $item[0])->value('id'),
            'quantity' => $item[1],
        ], $items);
    }
}
