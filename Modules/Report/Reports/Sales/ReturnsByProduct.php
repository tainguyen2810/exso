<?php

namespace Modules\Report\Reports\Sales;

use Modules\Report\Support\ReportContext;

/** Trả hàng theo sản phẩm. */
class ReturnsByProduct extends SalesByProduct
{
    public function name(): string
    {
        return 'returns';
    }

    public function title(): string
    {
        return 'Báo cáo trả hàng theo sản phẩm';
    }

    public function description(): string
    {
        return 'Sản phẩm khách trả lại nhiều nhất trong kỳ, số lượng và giá trị hàng trả.';
    }

    public function columns(ReportContext $context): array
    {
        return [
            ['key' => 'sku', 'label' => 'Mã SKU', 'type' => 'text'],
            ['key' => 'label', 'label' => 'Tên sản phẩm', 'type' => 'text', 'link' => 'url'],
            ['key' => 'returned_quantity', 'label' => 'SL trả lại', 'type' => 'number'],
            ['key' => 'returned_amount', 'label' => 'Giá trị hàng trả', 'type' => 'money'],
            ['key' => 'returned_cost', 'label' => 'Giá vốn hàng trả', 'type' => 'money', 'cost' => true],
        ];
    }

    public function rows(ReportContext $context): array
    {
        $rows = array_values(array_filter(parent::rows($context), fn (array $row): bool => $row['returned_quantity'] > 0));
        usort($rows, fn (array $a, array $b): int => $b['returned_amount'] <=> $a['returned_amount']);

        return $rows;
    }

    public function totals(ReportContext $context, array $rows): ?array
    {
        return [
            'returned_quantity' => array_sum(array_column($rows, 'returned_quantity')),
            'returned_amount' => array_sum(array_column($rows, 'returned_amount')),
            'returned_cost' => array_sum(array_column($rows, 'returned_cost')),
        ];
    }

    public function chart(ReportContext $context): ?array
    {
        return null;
    }
}
