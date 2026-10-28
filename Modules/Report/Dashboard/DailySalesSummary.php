<?php

namespace Modules\Report\Dashboard;

use App\Support\Dashboard\DashboardContext;
use Modules\Report\Models\ProductDaily;
use Modules\Report\Models\SalesDaily;
use Modules\Report\Support\SalesMetrics;

/**
 * Revenue on Tổng quan from the daily sales table, so the chart, today's
 * revenue and the best sellers match the sales reports (and Sapo: counted on
 * the day the order is completed). Runs after the Order module's filter,
 * which still fills the order counts and pending queues.
 */
class DailySalesSummary
{
    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    public function __invoke(array $value, DashboardContext $context): array
    {
        $from = $context->from()->toDateString();
        $today = $context->today->setTimezone((string) config('app.timezone'))->toDateString();

        $days = SalesDaily::query()
            ->whereIn('branch_id', $context->branchIds)
            ->whereBetween('date', [$from, $today])
            ->groupBy('date')
            ->selectRaw('date, '.SalesMetrics::SUMS)
            ->get()
            ->mapWithKeys(fn (SalesDaily $day): array => [substr((string) $day->getAttribute('date'), 0, 10) => SalesMetrics::from($day->getAttributes())]);

        $value['chart'] = array_map(fn (array $day): array => [
            ...$day,
            'revenue' => $days[$day['date']]['revenue'] ?? 0,
        ], $value['chart']);

        $value['today']['revenue'] = $days[$today]['revenue'] ?? 0;

        $value['top'] = ProductDaily::query()
            ->whereIn('branch_id', $context->branchIds)
            ->whereBetween('date', [$from, $today])
            ->groupBy('variant_id', 'name', 'sku')
            ->selectRaw('name, sku, SUM(quantity - returned_quantity) as sold')
            ->orderByDesc('sold')
            ->limit(5)
            ->get()
            ->filter(fn (ProductDaily $row): bool => (int) $row->getAttribute('sold') > 0)
            ->map(fn (ProductDaily $row): array => ['name' => (string) $row->getAttribute('name'), 'sku' => $row->getAttribute('sku'), 'quantity' => (int) $row->getAttribute('sold')])
            ->values()
            ->all();

        $value['ready'] = true;

        return $value;
    }
}
