<?php

namespace Modules\Report\Reports\Sales;

use App\Models\Branch;

/** Báo cáo bán hàng theo chi nhánh. */
class SalesByBranch extends SalesReport
{
    public function name(): string
    {
        return 'branch';
    }

    public function title(): string
    {
        return 'Báo cáo bán hàng theo chi nhánh';
    }

    public function description(): string
    {
        return 'So sánh doanh thu, lợi nhuận giữa các chi nhánh.';
    }

    protected function dimension(): string
    {
        return 'branch_id';
    }

    protected function dimensionLabel(): string
    {
        return 'Chi nhánh';
    }

    protected function labels(array $ids): array
    {
        $labels = [];

        foreach (Branch::query()->whereKey(array_filter($ids))->get(['id', 'name']) as $branch) {
            $labels[(string) $branch->id] = [$branch->name, null];
        }

        return $labels;
    }
}
