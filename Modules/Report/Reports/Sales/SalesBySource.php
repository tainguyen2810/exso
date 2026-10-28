<?php

namespace Modules\Report\Reports\Sales;

use App\Models\SalesSource;

/** Báo cáo bán hàng theo nguồn bán hàng. */
class SalesBySource extends SalesReport
{
    public function name(): string
    {
        return 'source';
    }

    public function title(): string
    {
        return 'Báo cáo bán hàng theo nguồn bán hàng';
    }

    public function description(): string
    {
        return 'Doanh thu theo nơi khách đặt hàng: tại quầy, Facebook, Zalo, sàn TMĐT...';
    }

    protected function dimension(): string
    {
        return 'source_id';
    }

    protected function dimensionLabel(): string
    {
        return 'Nguồn bán hàng';
    }

    protected function labels(array $ids): array
    {
        $labels = ['' => ['Không có nguồn', null]];

        foreach (SalesSource::query()->whereKey(array_filter($ids))->get(['id', 'name']) as $source) {
            $labels[(string) $source->id] = [$source->name, null];
        }

        return $labels;
    }
}
