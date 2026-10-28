<?php

namespace Modules\Report\Reports\Sales;

use Illuminate\Support\Facades\DB;
use Modules\Customer\Models\CustomerGroup;
use Modules\Report\Support\ReportContext;
use Modules\Report\Support\SalesMetrics;

/** Báo cáo bán hàng theo nhóm khách hàng (nhóm hiện tại của khách). */
class SalesByCustomerGroup extends SalesReport
{
    public function name(): string
    {
        return 'customer-group';
    }

    public function title(): string
    {
        return 'Báo cáo bán hàng theo nhóm khách hàng';
    }

    public function description(): string
    {
        return 'Doanh thu của khách lẻ, khách buôn, đại lý... theo nhóm hiện tại của khách.';
    }

    protected function dimension(): string
    {
        return 'customer_id';
    }

    protected function dimensionLabel(): string
    {
        return 'Nhóm khách hàng';
    }

    protected function labels(array $ids): array
    {
        return [];
    }

    public function rows(ReportContext $context): array
    {
        $groupOf = DB::table('customers')->where('tenant_id', tenancy()->id())->pluck('group_id', 'id');
        $names = CustomerGroup::query()->pluck('name', 'id');
        $buckets = [];

        $days = self::daily($context)->groupBy('customer_id')->selectRaw('customer_id, '.SalesMetrics::SUMS)->get();

        foreach ($days as $row) {
            $customer = $row->getAttribute('customer_id');
            $key = $customer === null ? 'retail' : (string) ($groupOf[$customer] ?? 'none');

            foreach ($row->getAttributes() as $field => $value) {
                if ($field !== 'customer_id') {
                    $buckets[$key][$field] = ($buckets[$key][$field] ?? 0) + (int) $value;
                }
            }
        }

        $rows = [];

        foreach ($buckets as $key => $sums) {
            $label = match ($key) {
                'retail' => 'Khách lẻ (không chọn khách)',
                'none' => 'Chưa có nhóm',
                default => (string) ($names[(int) $key] ?? 'Nhóm đã xóa'),
            };
            $rows[] = ['label' => $label, 'url' => null, ...SalesMetrics::from($sums)];
        }

        usort($rows, fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return $rows;
    }
}
