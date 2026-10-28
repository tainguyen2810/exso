<?php

namespace Modules\Report\Reports\Customers;

use Modules\Customer\Models\Customer;
use Modules\Report\Reports\Report;
use Modules\Report\Reports\Sales\SalesReport;
use Modules\Report\Support\ReportContext;
use Modules\Report\Support\SalesMetrics;

/** Báo cáo khách hàng theo khu vực (tỉnh/thành phố của khách). */
class CustomersByArea extends Report
{
    public function group(): string
    {
        return 'customers';
    }

    public function name(): string
    {
        return 'area';
    }

    public function title(): string
    {
        return 'Báo cáo khách hàng theo khu vực';
    }

    public function description(): string
    {
        return 'Số khách hàng mua hàng, đơn hàng và doanh thu theo tỉnh/thành phố.';
    }

    public function columns(ReportContext $context): array
    {
        return [
            ['key' => 'label', 'label' => 'Khu vực', 'type' => 'text'],
            ['key' => 'buyers', 'label' => 'Khách hàng mua hàng', 'type' => 'number'],
            ['key' => 'orders_count', 'label' => 'SL đơn hàng', 'type' => 'number'],
            ['key' => 'goods_amount', 'label' => 'Tiền hàng', 'type' => 'money'],
            ['key' => 'returned_amount', 'label' => 'Tiền hàng trả lại', 'type' => 'money'],
            ['key' => 'revenue', 'label' => 'Doanh thu', 'type' => 'money'],
        ];
    }

    public function rows(ReportContext $context): array
    {
        $sales = SalesReport::daily($context)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, '.SalesMetrics::SUMS)
            ->get();

        $provinces = Customer::query()->withTrashed()
            ->whereKey($sales->pluck('customer_id')->filter()->all())
            ->pluck('province', 'id');

        $rows = [];

        foreach ($sales as $customer) {
            $id = $customer->getAttribute('customer_id');
            $area = $id === null ? 'Khách lẻ' : (trim((string) ($provinces[$id] ?? '')) ?: 'Chưa có khu vực');
            $metrics = SalesMetrics::from($customer->getAttributes());
            $row = $rows[$area] ?? ['label' => $area, 'buyers' => 0, 'orders_count' => 0, 'goods_amount' => 0, 'returned_amount' => 0, 'revenue' => 0];
            $row['buyers'] += $id !== null && $metrics['orders_count'] > 0 ? 1 : 0;

            foreach (['orders_count', 'goods_amount', 'returned_amount', 'revenue'] as $key) {
                $row[$key] += $metrics[$key];
            }

            $rows[$area] = $row;
        }

        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return $rows;
    }

    public function chart(ReportContext $context): ?array
    {
        return ['x' => 'label', 'bars' => ['revenue'], 'line' => null, 'top' => 10];
    }
}
