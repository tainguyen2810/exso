<?php

namespace Modules\Report\Reports\Inventory;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockMovement;
use Modules\Report\Support\ReportContext;

/**
 * Báo cáo xuất nhập tồn: stock at the start of the period, what came in
 * and went out by kind, stock at the end. Read from stock_movements, so it
 * always matches the stock card (thẻ kho).
 */
class StockMovementReport extends StockReport
{
    /** Column => movement types (quantity sign tells in or out). */
    public const KINDS = [
        'purchase' => [StockMovement::PURCHASE],
        'sale_return' => [StockMovement::SALE_RETURN],
        'transfer_in' => [StockMovement::TRANSFER_IN],
        'sale' => [StockMovement::SALE],
        'purchase_return' => [StockMovement::PURCHASE_RETURN],
        'transfer_out' => [StockMovement::TRANSFER_OUT],
        'adjust' => [StockMovement::OPENING, StockMovement::STOCKTAKE, StockMovement::ADJUSTMENT],
    ];

    public function name(): string
    {
        return 'movement';
    }

    public function title(): string
    {
        return 'Báo cáo xuất nhập tồn';
    }

    public function description(): string
    {
        return 'Tồn đầu kỳ, nhập trong kỳ, xuất trong kỳ và tồn cuối kỳ của từng sản phẩm.';
    }

    public function filters(): array
    {
        return ['period', 'branch'];
    }

    public function columns(ReportContext $context): array
    {
        return [
            ['key' => 'sku', 'label' => 'Mã SKU', 'type' => 'text'],
            ['key' => 'label', 'label' => 'Tên sản phẩm', 'type' => 'text', 'link' => 'url'],
            ['key' => 'opening', 'label' => 'Tồn đầu kỳ', 'type' => 'number'],
            ['key' => 'purchase', 'label' => 'Nhập hàng', 'type' => 'number'],
            ['key' => 'sale_return', 'label' => 'Khách trả', 'type' => 'number'],
            ['key' => 'transfer_in', 'label' => 'Chuyển đến', 'type' => 'number'],
            ['key' => 'sale', 'label' => 'Bán hàng', 'type' => 'number'],
            ['key' => 'purchase_return', 'label' => 'Trả NCC', 'type' => 'number'],
            ['key' => 'transfer_out', 'label' => 'Chuyển đi', 'type' => 'number'],
            ['key' => 'adjust', 'label' => 'Kiểm kê, điều chỉnh', 'type' => 'number'],
            ['key' => 'closing', 'label' => 'Tồn cuối kỳ', 'type' => 'number'],
            ['key' => 'closing_value', 'label' => 'Giá trị tồn cuối kỳ', 'type' => 'money', 'cost' => true],
        ];
    }

    public function rows(ReportContext $context): array
    {
        $movements = fn () => DB::table('stock_movements')
            ->where('tenant_id', tenancy()->id())
            ->whereIn('branch_id', $context->branchIds);

        $opening = $movements()->where('created_at', '<', $context->from)
            ->groupBy('variant_id')->selectRaw('variant_id, SUM(quantity) as quantity')->pluck('quantity', 'variant_id');

        $period = $movements()->whereBetween('created_at', [$context->from, $context->to])
            ->groupBy('variant_id', 'type')->selectRaw('variant_id, type, SUM(quantity) as quantity')->get();

        $ids = array_unique([...array_keys($opening->all()), ...$period->pluck('variant_id')->all()]);
        $variants = DB::table('product_variants')->whereIn('id', $ids)->get(['id', 'product_id', 'sku', 'name', 'cost_price'])->keyBy('id');
        $moves = [];

        foreach ($period as $movement) {
            foreach (self::KINDS as $column => $types) {
                if (in_array($movement->type, $types, true)) {
                    // Outgoing columns show positive numbers.
                    $quantity = in_array($column, ['sale', 'purchase_return', 'transfer_out'], true) ? -(int) $movement->quantity : (int) $movement->quantity;
                    $moves[(int) $movement->variant_id][$column] = ($moves[(int) $movement->variant_id][$column] ?? 0) + $quantity;
                }
            }
        }

        $result = [];

        foreach ($ids as $id) {
            $variant = $variants[$id] ?? null;

            if ($variant === null) {
                continue;
            }

            $row = ['opening' => (int) ($opening[$id] ?? 0)];

            foreach (array_keys(self::KINDS) as $column) {
                $row[$column] = $moves[(int) $id][$column] ?? 0;
            }

            $closing = $row['opening'] + $row['purchase'] + $row['sale_return'] + $row['transfer_in']
                - $row['sale'] - $row['purchase_return'] - $row['transfer_out'] + $row['adjust'];

            $result[] = [
                'sku' => $variant->sku,
                'label' => $variant->name,
                'url' => "/products/{$variant->product_id}",
                ...$row,
                'closing' => $closing,
                'closing_value' => max(0, $closing) * (int) $variant->cost_price,
            ];
        }

        usort($result, fn (array $a, array $b): int => strcmp((string) $a['label'], (string) $b['label']));

        return $result;
    }
}
