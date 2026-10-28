<?php

namespace Modules\Report\Reports\Inventory;

use Modules\Report\Support\ReportContext;

/** Báo cáo tồn kho dưới định mức (thiếu hàng cần nhập). */
class StockLevelReport extends StockReport
{
    public function name(): string
    {
        return 'below-min';
    }

    public function title(): string
    {
        return 'Báo cáo tồn kho dưới định mức';
    }

    public function description(): string
    {
        return 'Sản phẩm có tồn kho thấp hơn định mức tối thiểu ở từng chi nhánh.';
    }

    /** Above the maximum instead of below the minimum. */
    protected function above(): bool
    {
        return false;
    }

    public function columns(ReportContext $context): array
    {
        return [
            ['key' => 'sku', 'label' => 'Mã SKU', 'type' => 'text'],
            ['key' => 'label', 'label' => 'Tên sản phẩm', 'type' => 'text', 'link' => 'url'],
            ['key' => 'branch', 'label' => 'Chi nhánh', 'type' => 'text'],
            ['key' => 'on_hand', 'label' => 'Tồn kho', 'type' => 'number'],
            ['key' => 'limit', 'label' => $this->above() ? 'Định mức tối đa' : 'Định mức tối thiểu', 'type' => 'number', 'total' => false],
            ['key' => 'difference', 'label' => $this->above() ? 'Vượt định mức' : 'Cần nhập thêm', 'type' => 'number'],
        ];
    }

    public function rows(ReportContext $context): array
    {
        $column = $this->above() ? 'max_quantity' : 'min_quantity';

        return array_values($this->stock($context)
            ->join('branches', 'branches.id', '=', 'inventories.branch_id')
            ->whereNotNull("inventories.{$column}")
            ->whereColumn('inventories.on_hand', $this->above() ? '>' : '<', "inventories.{$column}")
            ->orderBy('product_variants.name')
            ->get(['product_variants.product_id', 'product_variants.sku', 'product_variants.name', 'branches.name as branch', 'inventories.on_hand', "inventories.{$column} as limit"])
            ->map(fn (object $row): array => [
                'sku' => $row->sku,
                'label' => $row->name,
                'url' => "/products/{$row->product_id}",
                'branch' => $row->branch,
                'on_hand' => (int) $row->on_hand,
                'limit' => (int) $row->limit,
                'difference' => abs((int) $row->on_hand - (int) $row->limit),
            ])
            ->all());
    }
}
