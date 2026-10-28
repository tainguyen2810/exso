<?php

namespace Modules\Report\Reports\Sales;

use Modules\Customer\Models\Customer;

/** Báo cáo bán hàng theo khách hàng (khách mua nhiều nhất). */
class SalesByCustomer extends SalesReport
{
    public function name(): string
    {
        return 'customer';
    }

    public function title(): string
    {
        return 'Báo cáo bán hàng theo khách hàng';
    }

    public function description(): string
    {
        return 'Khách hàng mua nhiều nhất, số đơn và doanh thu của từng khách.';
    }

    protected function dimension(): string
    {
        return 'customer_id';
    }

    protected function dimensionLabel(): string
    {
        return 'Khách hàng';
    }

    protected function labels(array $ids): array
    {
        $labels = ['' => ['Khách lẻ', null]];

        foreach (Customer::query()->withTrashed()->whereKey(array_filter($ids))->get(['id', 'name', 'code']) as $customer) {
            $labels[(string) $customer->id] = ["{$customer->name} ({$customer->code})", "/customers/{$customer->id}"];
        }

        return $labels;
    }
}
