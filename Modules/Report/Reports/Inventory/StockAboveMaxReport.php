<?php

namespace Modules\Report\Reports\Inventory;

/** Báo cáo tồn kho vượt định mức (hàng tồn đọng). */
class StockAboveMaxReport extends StockLevelReport
{
    public function name(): string
    {
        return 'above-max';
    }

    public function title(): string
    {
        return 'Báo cáo tồn kho vượt định mức';
    }

    public function description(): string
    {
        return 'Sản phẩm có tồn kho cao hơn định mức tối đa ở từng chi nhánh.';
    }

    protected function above(): bool
    {
        return true;
    }
}
