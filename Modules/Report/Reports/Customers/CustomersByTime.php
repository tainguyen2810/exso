<?php

namespace Modules\Report\Reports\Customers;

use Modules\Customer\Models\Customer;
use Modules\Report\Reports\Report;
use Modules\Report\Reports\Sales\SalesReport;
use Modules\Report\Support\Period;
use Modules\Report\Support\ReportContext;

/**
 * Báo cáo khách hàng theo thời gian: khách mới, khách có mua hàng,
 * đơn hàng và doanh thu of each day / week / month.
 */
class CustomersByTime extends Report
{
    public function group(): string
    {
        return 'customers';
    }

    public function name(): string
    {
        return 'time';
    }

    public function title(): string
    {
        return 'Báo cáo khách hàng theo thời gian';
    }

    public function description(): string
    {
        return 'Số khách hàng mới, khách hàng mua hàng và doanh thu từ khách theo thời gian.';
    }

    public function filters(): array
    {
        return ['period', 'branch', 'source'];
    }

    public function options(): array
    {
        return ['group_by' => Period::option()];
    }

    public function columns(ReportContext $context): array
    {
        return [
            ['key' => 'label', 'label' => 'Thời gian', 'type' => 'text'],
            ['key' => 'new_customers', 'label' => 'Khách hàng mới', 'type' => 'number'],
            ['key' => 'buyers', 'label' => 'Khách hàng mua hàng', 'type' => 'number', 'total' => false],
            ['key' => 'orders_count', 'label' => 'SL đơn hàng', 'type' => 'number'],
            ['key' => 'revenue', 'label' => 'Doanh thu', 'type' => 'money'],
        ];
    }

    public function rows(ReportContext $context): array
    {
        $group = $context->option('group_by');
        $timezone = (string) config('app.timezone');
        $buckets = [];
        $row = fn (string $key): array => ['key' => $key, 'label' => Period::label($key, $group), 'new_customers' => 0, 'buyers' => [], 'orders_count' => 0, 'revenue' => 0];

        Customer::query()
            ->visibleTo($context->user)
            ->whereBetween('created_at', [$context->from, $context->to])
            ->select(['id', 'created_at'])
            ->orderBy('id')
            ->each(function (Customer $customer) use (&$buckets, $row, $group, $timezone): void {
                $key = Period::key($customer->created_at->setTimezone($timezone)->toDateString(), $group);
                $buckets[$key] ??= $row($key);
                $buckets[$key]['new_customers']++;
            });

        SalesReport::daily($context)
            ->whereNotNull('customer_id')
            ->groupBy('date', 'customer_id')
            ->selectRaw('date, customer_id, SUM(orders_count) as orders_count, SUM(goods_amount - discount - returned_amount + shipping_fee) as revenue')
            ->get()
            ->each(function ($day) use (&$buckets, $row, $group): void {
                $key = Period::key(substr((string) $day->getAttribute('date'), 0, 10), $group);
                $buckets[$key] ??= $row($key);
                $buckets[$key]['orders_count'] += (int) $day->getAttribute('orders_count');
                $buckets[$key]['revenue'] += (int) $day->getAttribute('revenue');

                if ((int) $day->getAttribute('orders_count') > 0) {
                    $buckets[$key]['buyers'][(int) $day->getAttribute('customer_id')] = true;
                }
            });

        krsort($buckets);

        return array_values(array_map(fn (array $bucket): array => [...$bucket, 'buyers' => count($bucket['buyers'])], $buckets));
    }

    public function totals(ReportContext $context, array $rows): ?array
    {
        $totals = parent::totals($context, $rows) ?? [];
        $totals['buyers'] = SalesReport::daily($context)
            ->whereNotNull('customer_id')
            ->where('orders_count', '>', 0)
            ->distinct()
            ->count('customer_id');

        return $totals;
    }

    public function chart(ReportContext $context): ?array
    {
        return ['x' => 'label', 'bars' => ['new_customers', 'buyers'], 'line' => null, 'fill' => 'time'];
    }
}
