<?php

namespace Modules\Report\Reports\Sales;

use Illuminate\Database\Eloquent\Builder;
use Modules\Report\Models\SalesDaily;
use Modules\Report\Reports\Report;
use Modules\Report\Support\ReportContext;
use Modules\Report\Support\SalesMetrics;

/**
 * Sales grouped by one column of the daily table (staff, branch, source,
 * customer...). Subclasses name the column and how to label its values.
 */
abstract class SalesReport extends Report
{
    public function group(): string
    {
        return 'sales';
    }

    /**
     * Column of report_sales_daily to group by.
     *
     * @return literal-string
     */
    abstract protected function dimension(): string;

    abstract protected function dimensionLabel(): string;

    /**
     * Label (and link) of each value of the dimension; null is a row too.
     *
     * @param  list<int|null>  $ids
     * @return array<int|string, array{0: string, 1: string|null}> id ('' for null) => [label, url]
     */
    abstract protected function labels(array $ids): array;

    /**
     * @return Builder<SalesDaily>
     */
    public static function daily(ReportContext $context): Builder
    {
        return SalesDaily::query()
            ->whereIn('branch_id', $context->branchIds)
            ->whereBetween('date', [$context->from->toDateString(), $context->to->toDateString()])
            ->when($context->sourceId !== null, fn (Builder $query) => $query->where('source_id', $context->sourceId));
    }

    public function columns(ReportContext $context): array
    {
        return [['key' => 'label', 'label' => $this->dimensionLabel(), 'type' => 'text', 'link' => 'url'], ...SalesMetrics::columns()];
    }

    public function rows(ReportContext $context): array
    {
        $column = $this->dimension();
        $groups = self::daily($context)
            ->groupBy($column)
            ->selectRaw("{$column} as dimension, ".SalesMetrics::SUMS)
            ->get();

        $labels = $this->labels(array_values($groups->pluck('dimension')->map(fn (mixed $id): ?int => $id === null ? null : (int) $id)->all()));
        $rows = [];

        foreach ($groups as $group) {
            $id = (string) ($group->getAttribute('dimension') ?? '');
            [$label, $url] = $labels[$id] ?? ['—', null];
            $rows[] = ['label' => $label, 'url' => $url, ...SalesMetrics::from($group->getAttributes())];
        }

        usort($rows, fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return $rows;
    }

    public function totals(ReportContext $context, array $rows): ?array
    {
        return self::sumMetrics($rows);
    }

    /**
     * Totals of metric rows, with the margin computed on the sums.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public static function sumMetrics(array $rows): array
    {
        $sum = fn (string $key): int => array_sum(array_map(fn (array $row): int => (int) ($row[$key] ?? 0), $rows));
        $totals = [];

        foreach (['orders_count', 'items_quantity', 'goods_amount', 'discount', 'shipping_fee', 'returns_count', 'returned_quantity', 'returned_amount', 'revenue', 'net_revenue', 'cost', 'gross_profit', 'quantity'] as $key) {
            $totals[$key] = $sum($key);
        }

        $totals['margin'] = $totals['net_revenue'] !== 0 ? round($totals['gross_profit'] * 100 / $totals['net_revenue'], 1) : null;

        return $totals;
    }

    public function chart(ReportContext $context): ?array
    {
        return ['x' => 'label', 'bars' => ['revenue'], 'line' => $context->canViewCost() ? 'gross_profit' : null];
    }
}
