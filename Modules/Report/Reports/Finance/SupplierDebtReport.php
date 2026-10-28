<?php

namespace Modules\Report\Reports\Finance;

use Modules\Purchase\Models\Supplier;
use Modules\Report\Support\ReportContext;

/** Công nợ phải trả nhà cung cấp, same layout as the customer report. */
class SupplierDebtReport extends CustomerDebtReport
{
    public function name(): string
    {
        return 'supplier-debts';
    }

    public function title(): string
    {
        return 'Báo cáo công nợ nhà cung cấp';
    }

    public function description(): string
    {
        return 'Nợ phải trả đầu kỳ, phát sinh tăng/giảm và cuối kỳ của từng nhà cung cấp.';
    }

    public function permission(): ?string
    {
        return 'suppliers.view';
    }

    protected function ledger(): string
    {
        return 'supplier_debts';
    }

    /** @return literal-string */
    protected function partyColumn(): string
    {
        return 'supplier_id';
    }

    protected function partyHeading(): string
    {
        return 'Nhà cung cấp';
    }

    protected function parties(ReportContext $context, array $ids): array
    {
        $labels = [];

        foreach (Supplier::query()->withTrashed()->whereKey($ids)->get(['id', 'name', 'code']) as $supplier) {
            $labels[$supplier->id] = ["{$supplier->name} ({$supplier->code})", "/suppliers/{$supplier->id}"];
        }

        return $labels;
    }
}
