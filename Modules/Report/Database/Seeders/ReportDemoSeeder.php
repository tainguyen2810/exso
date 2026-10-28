<?php

namespace Modules\Report\Database\Seeders;

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Modules\Cashbook\Models\CashVoucher;
use Modules\Cashbook\Models\CashVoucherType;
use Modules\Cashbook\Services\CashbookService;
use Modules\Customer\Models\Customer;
use Modules\Order\Services\OrderService;
use Modules\Product\Models\ProductVariant;
use Modules\Report\Services\SalesAggregator;

/**
 * Sales of the last days, a few expenses and the daily report tables, so
 * Tổng quan, Sổ quỹ and Báo cáo show something (after the order demo).
 */
class ReportDemoSeeder extends Seeder
{
    public function run(OrderService $orders, CashbookService $cashbook, SalesAggregator $aggregator): void
    {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        Auth::setUser($owner);

        $main = (int) Branch::query()->where('is_default', true)->value('id');
        $cash = (int) PaymentMethod::query()->where('type', 'cash')->value('id');
        $transfer = (int) (PaymentMethod::query()->where('type', 'bank')->value('id') ?? $cash);
        $customers = Customer::query()->orderBy('id')->pluck('id')->all();
        $sku = fn (string $code): int => (int) ProductVariant::query()->where('sku', $code)->value('id');
        $today = CarbonImmutable::now();

        $sales = [
            6 => [['UG-HDMI2', 2], ['DA-A4-70', 1]],
            5 => [['LOGI-M331', 1]],
            4 => [['UG-HDMI2', 3], ['DA-A4-70', 2]],
            3 => [['LOGI-M331', 2], ['UG-HDMI2', 1]],
            2 => [['DA-A4-70', 3]],
            1 => [['LOGI-M331', 1], ['UG-HDMI2', 2], ['DA-A4-70', 1]],
        ];

        foreach ($sales as $daysAgo => $lines) {
            $this->at($today->subDays($daysAgo)->setTime(10 + $daysAgo, 15), function () use ($orders, $owner, $main, $lines, $sku, $cash, $transfer, $customers, $daysAgo): void {
                $data = [
                    'branch_id' => $main,
                    'items' => array_map(fn (array $line): array => ['variant_id' => $sku($line[0]), 'quantity' => $line[1]], $lines),
                    'customer_id' => $customers[$daysAgo % max(1, count($customers))] ?? null,
                    'delivery_method' => 'pickup',
                ];
                // Money over the total is change: only the total is recorded.
                $orders->create([...$data, 'payments' => [['payment_method_id' => $daysAgo % 2 ? $cash : $transfer, 'amount' => 100000000]]], $owner, 'export');
            });
        }

        // Rent and a small other income, counted in the profit report.
        $type = fn (string $kind, string $code): ?int => CashVoucherType::query()->where('kind', $kind)->where('code', $code)->value('id');

        $this->at($today->subDays(3)->setTime(9, 0), fn () => $cashbook->create([
            'kind' => CashVoucher::PAYMENT, 'branch_id' => $main, 'type_id' => $type(CashVoucher::PAYMENT, 'MATBANG'),
            'party_type' => 'other', 'party_name' => 'Chủ nhà Lê Văn Tám', 'amount' => 1500000, 'payment_method_id' => $transfer,
            'affects_profit' => true, 'description' => 'Tiền thuê mặt bằng tháng này',
        ], $owner));

        $this->at($today->subDays(2)->setTime(16, 0), fn () => $cashbook->create([
            'kind' => CashVoucher::RECEIPT, 'branch_id' => $main, 'type_id' => $type(CashVoucher::RECEIPT, 'THANHLY'),
            'party_type' => 'other', 'party_name' => 'Cửa hàng ve chai', 'amount' => 120000, 'payment_method_id' => $cash,
            'affects_profit' => true, 'description' => 'Thanh lý thùng carton',
        ], $owner));

        $cashbook->sync();
        $aggregator->rebuild();
    }

    /**
     * Run as if it were $time (orders, vouchers and their timestamps).
     */
    protected function at(CarbonImmutable $time, callable $callback): void
    {
        Carbon::setTestNow($time);
        CarbonImmutable::setTestNow($time);

        try {
            $callback();
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }
}
