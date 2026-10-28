<?php

namespace Modules\Report\Reports\Sales;

use Illuminate\Database\Eloquent\Builder;
use Modules\Report\Models\ProductDaily;
use Modules\Report\Support\ReportContext;

/** Báo cáo bán hàng theo sản phẩm (từng phiên bản). */
class SalesByProduct extends SalesReport
{
    public function name(): string
    {
        return 'product';
    }

    public function title(): string
    {
        return 'Báo cáo bán hàng theo sản phẩm';
    }

    public function description(): string
    {
        return 'Số lượng bán, trả, doanh thu thuần và lợi nhuận của từng sản phẩm.';
    }

    protected function dimension(): string
    {
        return 'variant_id';
    }

    protected function dimensionLabel(): string
    {
        return 'Sản phẩm';
    }

    protected function labels(array $ids): array
    {
        return [];
    }

    /**
     * @return Builder<ProductDaily>
     */
    public static function productDaily(ReportContext $context): Builder
    {
        return ProductDaily::query()
            ->whereIn('branch_id', $context->branchIds)
            ->whereBetween('date', [$context->from->toDateString(), $context->to->toDateString()])
            ->when($context->sourceId !== null, fn (Builder $query) => $query->where('source_id', $context->sourceId));
    }

    public function columns(ReportContext $context): array
    {
        return [
            ['key' => 'sku', 'label' => 'Mã SKU', 'type' => 'text'],
            ['key' => 'label', 'label' => 'Tên sản phẩm', 'type' => 'text', 'link' => 'url'],
            ['key' => 'quantity', 'label' => 'SL bán', 'type' => 'number'],
            ['key' => 'returned_quantity', 'label' => 'SL trả lại', 'type' => 'number'],
            ['key' => 'items_quantity', 'label' => 'SL thực bán', 'type' => 'number'],
            ['key' => 'goods_amount', 'label' => 'Tiền hàng', 'type' => 'money'],
            ['key' => 'discount', 'label' => 'Chiết khấu', 'type' => 'money'],
            ['key' => 'returned_amount', 'label' => 'Tiền hàng trả lại', 'type' => 'money'],
            ['key' => 'net_revenue', 'label' => 'Doanh thu thuần', 'type' => 'money'],
            ['key' => 'cost', 'label' => 'Tiền vốn', 'type' => 'money', 'cost' => true],
            ['key' => 'gross_profit', 'label' => 'Lợi nhuận gộp', 'type' => 'money', 'cost' => true],
            ['key' => 'margin', 'label' => 'Tỷ suất LN gộp (%)', 'type' => 'percent', 'cost' => true],
        ];
    }

    public function rows(ReportContext $context): array
    {
        $groups = self::productDaily($context)
            ->groupBy('variant_id', 'product_id', 'name', 'sku')
            ->selectRaw('variant_id, product_id, name, sku, SUM(quantity) as quantity, SUM(goods_amount) as goods_amount, SUM(discount) as discount, SUM(cost) as cost, '
                .'SUM(returned_quantity) as returned_quantity, SUM(returned_amount) as returned_amount, SUM(returned_cost) as returned_cost')
            ->get();

        // A product renamed during the period shows once, with its latest name.
        $rows = [];

        foreach ($groups as $group) {
            $key = $group->getAttribute('variant_id') ?? 'name:'.$group->getAttribute('name');
            $row = $rows[$key] ?? [
                'sku' => $group->getAttribute('sku'),
                'label' => $group->getAttribute('name'),
                'url' => $group->getAttribute('product_id') ? '/products/'.$group->getAttribute('product_id') : null,
                'quantity' => 0, 'returned_quantity' => 0, 'goods_amount' => 0, 'discount' => 0,
                'returned_amount' => 0, 'cost' => 0, 'returned_cost' => 0,
            ];

            foreach (['quantity', 'returned_quantity', 'goods_amount', 'discount', 'returned_amount', 'cost', 'returned_cost'] as $field) {
                $row[$field] += (int) $group->getAttribute($field);
            }

            $rows[$key] = $row;
        }

        $result = [];

        foreach ($rows as $row) {
            $net = $row['goods_amount'] - $row['discount'] - $row['returned_amount'];
            $cost = $row['cost'] - $row['returned_cost'];
            $result[] = [
                ...$row,
                'items_quantity' => $row['quantity'] - $row['returned_quantity'],
                'net_revenue' => $net,
                'cost' => $cost,
                'gross_profit' => $net - $cost,
                'margin' => $net !== 0 ? round(($net - $cost) * 100 / $net, 1) : null,
            ];
        }

        usort($result, fn (array $a, array $b): int => [$b['net_revenue'], $b['quantity']] <=> [$a['net_revenue'], $a['quantity']]);

        return $result;
    }

    public function chart(ReportContext $context): ?array
    {
        return ['x' => 'label', 'bars' => ['net_revenue'], 'line' => null, 'top' => 10];
    }
}
