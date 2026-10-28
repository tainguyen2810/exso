<?php

namespace Modules\Report\Reports\Purchases;

use Modules\Purchase\Models\PurchaseReceipt;
use Modules\Report\Support\ReportContext;

/** Báo cáo nhập hàng theo nhà cung cấp. */
class PurchasesBySupplier extends PurchasesReport
{
    public function name(): string
    {
        return 'supplier';
    }

    public function title(): string
    {
        return 'Báo cáo nhập hàng theo nhà cung cấp';
    }

    public function description(): string
    {
        return 'Giá trị nhập, đã trả và còn nợ của từng nhà cung cấp trong kỳ.';
    }

    public function options(): array
    {
        return [];
    }

    protected function labelHeading(): string
    {
        return 'Nhà cung cấp';
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    protected function bucket(PurchaseReceipt $receipt, ReportContext $context): array
    {
        return [(string) $receipt->supplier_id, $receipt->supplier->name ?? '—', "/suppliers/{$receipt->supplier_id}"];
    }

    protected function sort(array $rows): array
    {
        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $rows;
    }

    public function chart(ReportContext $context): ?array
    {
        return ['x' => 'label', 'bars' => ['total'], 'line' => null, 'top' => 10];
    }
}
