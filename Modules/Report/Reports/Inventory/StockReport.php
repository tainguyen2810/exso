<?php

namespace Modules\Report\Reports\Inventory;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Report\Reports\Report;
use Modules\Report\Support\ReportContext;

/**
 * Báo cáo tồn kho: quantity and value in stock now, per product, in the
 * chosen branch or all the user's branches.
 */
class StockReport extends Report
{
    public function group(): string
    {
        return 'inventory';
    }

    public function name(): string
    {
        return 'stock';
    }

    public function title(): string
    {
        return 'Báo cáo tồn kho';
    }

    public function description(): string
    {
        return 'Số lượng và giá trị tồn kho hiện tại của chi nhánh và toàn hệ thống.';
    }

    public function filters(): array
    {
        return ['branch'];
    }

    public function permission(): ?string
    {
        return 'inventory.view';
    }

    protected function stock(ReportContext $context): Builder
    {
        return DB::table('inventories')
            ->join('product_variants', 'product_variants.id', '=', 'inventories.variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('inventories.tenant_id', tenancy()->id())
            ->whereIn('inventories.branch_id', $context->branchIds)
            ->whereNull('products.deleted_at');
    }

    public function columns(ReportContext $context): array
    {
        return [
            ['key' => 'sku', 'label' => 'Mã SKU', 'type' => 'text'],
            ['key' => 'label', 'label' => 'Tên sản phẩm', 'type' => 'text', 'link' => 'url'],
            ['key' => 'unit', 'label' => 'Đơn vị', 'type' => 'text'],
            ['key' => 'on_hand', 'label' => 'Tồn kho', 'type' => 'number'],
            ['key' => 'reserved', 'label' => 'Đang giao dịch', 'type' => 'number'],
            ['key' => 'available', 'label' => 'Có thể bán', 'type' => 'number'],
            ['key' => 'cost_price', 'label' => 'Giá vốn', 'type' => 'money', 'cost' => true, 'total' => false],
            ['key' => 'value', 'label' => 'Giá trị tồn', 'type' => 'money', 'cost' => true],
        ];
    }

    public function rows(ReportContext $context): array
    {
        return array_values($this->stock($context)
            ->groupBy('product_variants.id', 'product_variants.sku', 'product_variants.name', 'product_variants.unit', 'product_variants.cost_price', 'product_variants.product_id')
            ->selectRaw('product_variants.product_id, product_variants.sku, product_variants.name, product_variants.unit, product_variants.cost_price, '
                .'SUM(inventories.on_hand) as on_hand, SUM(inventories.reserved) as reserved')
            ->orderBy('product_variants.name')
            ->get()
            ->map(fn (object $row): array => [
                'sku' => $row->sku,
                'label' => $row->name,
                'url' => "/products/{$row->product_id}",
                'unit' => $row->unit,
                'on_hand' => (int) $row->on_hand,
                'reserved' => (int) $row->reserved,
                'available' => (int) $row->on_hand - (int) $row->reserved,
                'cost_price' => (int) $row->cost_price,
                'value' => max(0, (int) $row->on_hand) * (int) $row->cost_price,
            ])
            ->all());
    }
}
