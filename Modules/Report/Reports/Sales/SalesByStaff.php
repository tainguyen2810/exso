<?php

namespace Modules\Report\Reports\Sales;

use App\Models\User;

/** Báo cáo bán hàng theo nhân viên (phụ trách đơn, không có thì người tạo). */
class SalesByStaff extends SalesReport
{
    public function name(): string
    {
        return 'staff';
    }

    public function title(): string
    {
        return 'Báo cáo bán hàng theo nhân viên';
    }

    public function description(): string
    {
        return 'Kết quả bán hàng của từng nhân viên phụ trách đơn.';
    }

    protected function dimension(): string
    {
        return 'staff_id';
    }

    protected function dimensionLabel(): string
    {
        return 'Nhân viên';
    }

    protected function labels(array $ids): array
    {
        $labels = ['' => ['Không có nhân viên', null]];

        foreach (User::query()->whereKey(array_filter($ids))->get(['id', 'name']) as $user) {
            $labels[(string) $user->id] = [$user->name, null];
        }

        return $labels;
    }
}
