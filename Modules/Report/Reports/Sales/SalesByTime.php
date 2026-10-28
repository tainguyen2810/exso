<?php

namespace Modules\Report\Reports\Sales;

use Modules\Report\Support\Period;
use Modules\Report\Support\ReportContext;
use Modules\Report\Support\SalesMetrics;

/** Báo cáo bán hàng theo thời gian (hoạt động kinh doanh). */
class SalesByTime extends SalesReport
{
    public function name(): string
    {
        return 'time';
    }

    public function title(): string
    {
        return 'Báo cáo bán hàng theo thời gian';
    }

    public function description(): string
    {
        return 'Doanh thu, lợi nhuận theo ngày, tuần, tháng; ghi nhận theo ngày giao hàng thành công.';
    }

    public function options(): array
    {
        return ['group_by' => Period::option()];
    }

    protected function dimension(): string
    {
        return 'date';
    }

    protected function dimensionLabel(): string
    {
        return 'Thời gian';
    }

    protected function labels(array $ids): array
    {
        return [];
    }

    public function rows(ReportContext $context): array
    {
        $group = $context->option('group_by');
        $buckets = [];

        foreach (self::daily($context)->groupBy('date')->selectRaw('date, '.SalesMetrics::SUMS)->get() as $day) {
            $key = Period::key(substr((string) $day->getAttribute('date'), 0, 10), $group);

            foreach ($day->getAttributes() as $field => $value) {
                if ($field !== 'date') {
                    $buckets[$key][$field] = ($buckets[$key][$field] ?? 0) + (int) $value;
                }
            }
        }

        krsort($buckets);
        $rows = [];

        foreach ($buckets as $key => $sums) {
            $rows[] = ['label' => Period::label((string) $key, $group), 'key' => (string) $key, 'url' => null, ...SalesMetrics::from($sums)];
        }

        return $rows;
    }

    public function chart(ReportContext $context): ?array
    {
        return ['x' => 'label', 'bars' => ['revenue'], 'line' => $context->canViewCost() ? 'gross_profit' : null, 'fill' => 'time'];
    }
}
