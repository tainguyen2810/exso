<?php

namespace Modules\Report\Support;

/**
 * Sales figures from sums of the daily tables, with Sapo's formulas.
 */
final class SalesMetrics
{
    /** Sum columns of report_sales_daily. */
    public const SUMS = 'SUM(orders_count) as orders_count, SUM(items_quantity) as items_quantity, SUM(goods_amount) as goods_amount, '
        .'SUM(discount) as discount, SUM(shipping_fee) as shipping_fee, SUM(cost) as cost, SUM(returns_count) as returns_count, '
        .'SUM(returned_quantity) as returned_quantity, SUM(returned_amount) as returned_amount, SUM(returned_cost) as returned_cost';

    /**
     * @param  array<string, mixed>|object  $sums
     * @return array{orders_count: int, items_quantity: int, goods_amount: int, discount: int, shipping_fee: int, returns_count: int, returned_quantity: int, returned_amount: int, revenue: int, net_revenue: int, cost: int, gross_profit: int, margin: float|null}
     */
    public static function from(array|object $sums): array
    {
        $get = fn (string $key): int => (int) (is_array($sums) ? ($sums[$key] ?? 0) : ($sums->{$key} ?? 0));

        $net = $get('goods_amount') - $get('discount') - $get('returned_amount');
        $cost = $get('cost') - $get('returned_cost');

        return [
            'orders_count' => $get('orders_count'),
            'items_quantity' => $get('items_quantity') - $get('returned_quantity'),
            'goods_amount' => $get('goods_amount'),
            'discount' => $get('discount'),
            'shipping_fee' => $get('shipping_fee'),
            'returns_count' => $get('returns_count'),
            'returned_quantity' => $get('returned_quantity'),
            'returned_amount' => $get('returned_amount'),
            'revenue' => $net + $get('shipping_fee'),
            'net_revenue' => $net,
            'cost' => $cost,
            'gross_profit' => $net - $cost,
            'margin' => $net !== 0 ? round(($net - $cost) * 100 / $net, 1) : null,
        ];
    }

    /**
     * Standard sales columns after the label column(s).
     *
     * @return list<array{key: string, label: string, type: string, cost?: bool}>
     */
    public static function columns(): array
    {
        return [
            ['key' => 'orders_count', 'label' => 'SL đơn hàng', 'type' => 'number'],
            ['key' => 'items_quantity', 'label' => 'SL hàng thực bán', 'type' => 'number'],
            ['key' => 'goods_amount', 'label' => 'Tiền hàng', 'type' => 'money'],
            ['key' => 'discount', 'label' => 'Chiết khấu', 'type' => 'money'],
            ['key' => 'returned_amount', 'label' => 'Tiền hàng trả lại', 'type' => 'money'],
            ['key' => 'shipping_fee', 'label' => 'Phí giao hàng', 'type' => 'money'],
            ['key' => 'revenue', 'label' => 'Doanh thu', 'type' => 'money'],
            ['key' => 'net_revenue', 'label' => 'Doanh thu thuần', 'type' => 'money'],
            ['key' => 'cost', 'label' => 'Tiền vốn', 'type' => 'money', 'cost' => true],
            ['key' => 'gross_profit', 'label' => 'Lợi nhuận gộp', 'type' => 'money', 'cost' => true],
            ['key' => 'margin', 'label' => 'Tỷ suất LN gộp (%)', 'type' => 'percent', 'cost' => true],
        ];
    }
}
