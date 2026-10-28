<?php

namespace Modules\Report\Reports;

use Modules\Report\Support\ReportContext;

/**
 * One report page. The page, filters, table, totals, chart and Excel export
 * are shared; a report only says what it shows and computes its rows.
 *
 * Columns: ['key', 'label', 'type' => text|number|money|percent|date, 'cost' => true when it
 * reveals cost prices (hidden without products.view_cost), 'link' => key of a url field].
 */
abstract class Report
{
    /** Group on the Báo cáo menu: sales, purchases, inventory, finance, customers. */
    abstract public function group(): string;

    /** Key inside the group, used in the URL: /reports/{group}/{name}. */
    abstract public function name(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /**
     * @return list<array{key: string, label: string, type: string, cost?: bool, link?: string, total?: bool}>
     */
    abstract public function columns(ReportContext $context): array;

    /**
     * Rows of the report, each keyed by column key (plus url fields).
     *
     * @return list<array<string, mixed>>
     */
    abstract public function rows(ReportContext $context): array;

    public function key(): string
    {
        return $this->group().'.'.$this->name();
    }

    /**
     * Which shared filters the page shows.
     *
     * @return list<'period'|'branch'|'source'>
     */
    public function filters(): array
    {
        return ['period', 'branch', 'source'];
    }

    /**
     * Extra choices, e.g. group by day / month.
     *
     * @return array<string, array{label: string, options: array<string, string>, default: string}>
     */
    public function options(): array
    {
        return [];
    }

    /**
     * Chart over the rows: bars (and an optional line) by the x column.
     *
     * fill => 'time' draws every bucket of the period (rows need a 'key'),
     * top => how many rows to draw otherwise (default 10).
     *
     * @return array{x: string, bars: list<string>, line?: string|null, fill?: 'time', top?: int}|null
     */
    public function chart(ReportContext $context): ?array
    {
        return null;
    }

    /** Message shown instead of (or above) the table, e.g. a missing permission. */
    public function notice(ReportContext $context): ?string
    {
        return null;
    }

    /** Extra permission needed besides reports.view. */
    public function permission(): ?string
    {
        return null;
    }

    /**
     * The "Tổng" row: number and money columns summed. Null for no row.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    public function totals(ReportContext $context, array $rows): ?array
    {
        $totals = [];

        foreach ($this->columns($context) as $column) {
            if (in_array($column['type'], ['number', 'money'], true) && ($column['total'] ?? true)) {
                $totals[$column['key']] = array_sum(array_map(fn (array $row): int => (int) ($row[$column['key']] ?? 0), $rows));
            }
        }

        return $totals;
    }

    public function url(): string
    {
        return "/reports/{$this->group()}/{$this->name()}";
    }
}
