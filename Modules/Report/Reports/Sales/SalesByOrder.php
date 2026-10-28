<?php

namespace Modules\Report\Reports\Sales;

use Modules\Order\Models\Order;
use Modules\Report\Support\ReportContext;

/** Báo cáo bán hàng theo đơn hàng: từng đơn hoàn thành trong kỳ. */
class SalesByOrder extends SalesReport
{
    public function name(): string
    {
        return 'order';
    }

    public function title(): string
    {
        return 'Báo cáo bán hàng theo đơn hàng';
    }

    public function description(): string
    {
        return 'Từng đơn giao thành công trong kỳ với doanh thu, giá vốn và lợi nhuận.';
    }

    protected function dimension(): string
    {
        return 'id';
    }

    protected function dimensionLabel(): string
    {
        return 'Mã đơn hàng';
    }

    protected function labels(array $ids): array
    {
        return [];
    }

    public function columns(ReportContext $context): array
    {
        return [
            ['key' => 'label', 'label' => 'Mã đơn hàng', 'type' => 'text', 'link' => 'url'],
            ['key' => 'completed_at', 'label' => 'Ngày hoàn thành', 'type' => 'date'],
            ['key' => 'customer', 'label' => 'Khách hàng', 'type' => 'text'],
            ['key' => 'source', 'label' => 'Nguồn', 'type' => 'text'],
            ['key' => 'goods_amount', 'label' => 'Tiền hàng', 'type' => 'money'],
            ['key' => 'discount', 'label' => 'Chiết khấu', 'type' => 'money'],
            ['key' => 'shipping_fee', 'label' => 'Phí giao hàng', 'type' => 'money'],
            ['key' => 'returned_amount', 'label' => 'Đã trả lại', 'type' => 'money'],
            ['key' => 'revenue', 'label' => 'Doanh thu', 'type' => 'money'],
            ['key' => 'cost', 'label' => 'Tiền vốn', 'type' => 'money', 'cost' => true],
            ['key' => 'gross_profit', 'label' => 'Lợi nhuận gộp', 'type' => 'money', 'cost' => true],
        ];
    }

    public function rows(ReportContext $context): array
    {
        $rows = [];

        Order::query()
            ->with(['items:id,order_id,price,quantity,discount,cost,returned_quantity', 'source:id,name'])
            ->whereIn('branch_id', $context->branchIds)
            ->where('status', Order::COMPLETED)
            ->whereBetween('completed_at', [$context->from, $context->to])
            ->when($context->sourceId !== null, fn ($query) => $query->where('source_id', $context->sourceId))
            ->orderByDesc('completed_at')
            ->limit(5000)
            ->get()
            ->each(function (Order $order) use (&$rows): void {
                $goods = (int) $order->items->sum(fn ($item): int => $item->price * $item->quantity);
                $discount = $order->discount + (int) $order->items->sum('discount');
                $cost = (int) $order->items->sum(fn ($item): int => $item->cost * ($item->quantity - $item->returned_quantity));
                $net = $goods - $discount - $order->returned_total;

                $rows[] = [
                    'label' => $order->code,
                    'url' => "/orders/{$order->id}",
                    'completed_at' => $order->completed_at?->toIso8601String(),
                    'customer' => $order->customer_name ?: 'Khách lẻ',
                    'source' => $order->source?->name,
                    'goods_amount' => $goods,
                    'discount' => $discount,
                    'shipping_fee' => $order->shipping_fee,
                    'returned_amount' => $order->returned_total,
                    'revenue' => $net + $order->shipping_fee,
                    'cost' => $cost,
                    'gross_profit' => $net - $cost,
                ];
            });

        return $rows;
    }

    public function totals(ReportContext $context, array $rows): ?array
    {
        return parent::totals($context, $rows);
    }

    public function chart(ReportContext $context): ?array
    {
        return null;
    }
}
