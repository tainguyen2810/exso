<?php

namespace Modules\Order\Dashboard;

use App\Support\Dashboard\DashboardContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderReturn;
use Modules\Order\Services\OrderQueues;

/**
 * Sales numbers on Tổng quan (filter dashboard.sales), counted only in the
 * branches of the context. Revenue of a day = totals of the orders sold
 * that day (not cancelled) less the goods returned that day.
 */
class SalesSummary
{
    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    public function __invoke(array $value, DashboardContext $context): array
    {
        $orders = fn (): Builder => Order::query()->whereIn('branch_id', $context->branchIds);
        $timezone = (string) config('app.timezone');
        $start = $context->from()->setTimezone($timezone)->startOfDay();
        $todayStart = $context->today->setTimezone($timezone)->startOfDay();

        $sold = $orders()
            ->where('status', '!=', Order::CANCELLED)
            ->where('sale_at', '>=', $start)
            ->get(['sale_at', 'total'])
            ->groupBy(fn (Order $order): string => (string) $order->sale_at?->setTimezone($timezone)->toDateString())
            ->map(fn ($day): int => (int) $day->sum('total'));

        $returned = OrderReturn::query()
            ->whereIn('branch_id', $context->branchIds)
            ->where('created_at', '>=', $start)
            ->get(['created_at', 'total'])
            ->groupBy(fn (OrderReturn $return): string => (string) $return->created_at?->setTimezone($timezone)->toDateString())
            ->map(fn ($day): int => (int) $day->sum('total'));

        $chart = [];

        foreach ($value['chart'] as $day) {
            $chart[] = ['date' => $day['date'], 'revenue' => ($sold[$day['date']] ?? 0) - ($returned[$day['date']] ?? 0)];
        }

        $today = $todayStart->toDateString();

        $value['ready'] = true;
        $value['chart'] = $chart;
        $value['today'] = [
            'revenue' => ($sold[$today] ?? 0) - ($returned[$today] ?? 0),
            'orders' => $orders()->where('sale_at', '>=', $todayStart)->count(),
            'returns' => OrderReturn::query()->whereIn('branch_id', $context->branchIds)->where('created_at', '>=', $todayStart)->count(),
            'cancelled' => $orders()->where('status', Order::CANCELLED)->where('cancelled_at', '>=', $todayStart)->count(),
        ];
        $value['pending'] = app(OrderQueues::class)->counts($context->branchIds);
        $value['top'] = $this->top($context->branchIds, $start);

        return $value;
    }

    /**
     * Best sellers of the period by quantity.
     *
     * @param  list<int>  $branchIds
     * @return list<array{name: string, sku: ?string, quantity: int}>
     */
    protected function top(array $branchIds, CarbonImmutable $from): array
    {
        return array_values(DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.tenant_id', tenancy()->id())
            ->whereIn('orders.branch_id', $branchIds)
            ->where('orders.status', '!=', Order::CANCELLED)
            ->where('orders.sale_at', '>=', $from)
            ->groupBy('order_items.variant_id', 'order_items.name', 'order_items.sku')
            ->selectRaw('order_items.name, order_items.sku, SUM(order_items.quantity - order_items.returned_quantity) as quantity')
            ->orderByDesc('quantity')
            ->limit(5)
            ->get()
            ->map(fn (object $row): array => ['name' => (string) $row->name, 'sku' => $row->sku, 'quantity' => (int) $row->quantity])
            ->all());
    }
}
