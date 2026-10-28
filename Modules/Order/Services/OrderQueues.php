<?php

namespace Modules\Order\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Order\Models\Order;

/**
 * "Đơn hàng cần xử lý": the work queues shown above the order list and on
 * Tổng quan. Each queue is also a list filter (filter[queue]=<key>).
 */
class OrderQueues
{
    public const LABELS = [
        'approval' => 'Chờ duyệt',
        'payment' => 'Chờ thanh toán',
        'packing' => 'Chờ đóng gói',
        'pickup' => 'Chờ xuất kho',
        'shipping' => 'Đang giao hàng',
        'returning' => 'Hủy giao - chờ nhận',
    ];

    /**
     * @param  Builder<Order>  $query
     */
    public function apply(Builder $query, string $queue): void
    {
        match ($queue) {
            'approval' => $query->where('status', Order::PENDING),
            'payment' => $query->where('status', '!=', Order::CANCELLED)->whereRaw('paid < total - returned_total'),
            'packing' => $query->where('status', Order::APPROVED),
            'pickup' => $query->where('status', Order::PACKED),
            'shipping' => $query->where('status', Order::SHIPPED)->where('delivery_status', 'shipping'),
            'returning' => $query->where('status', Order::SHIPPED)->where('delivery_status', 'returning'),
            default => null,
        };
    }

    /**
     * @param  list<int>  $branchIds
     * @param  Builder<Order>|null  $base  e.g. only the orders a user may see
     * @return array<string, int>
     */
    public function counts(array $branchIds, ?Builder $base = null): array
    {
        $counts = [];

        foreach (array_keys(self::LABELS) as $queue) {
            $query = $base === null ? Order::query()->whereIn('branch_id', $branchIds) : clone $base;
            $this->apply($query, $queue);
            $counts[$queue] = $query->count();
        }

        return $counts;
    }

    /**
     * Count and money of each queue, for the strip above the order list.
     *
     * @param  Builder<Order>  $base
     * @return list<array{key: string, label: string, count: int, amount: int}>
     */
    public function strip(Builder $base): array
    {
        $strip = [];

        foreach (self::LABELS as $queue => $label) {
            $query = clone $base;
            $this->apply($query, $queue);
            $row = $query->selectRaw('COUNT(*) as aggregate, COALESCE(SUM('.($queue === 'payment' ? 'total - returned_total - paid' : 'total').'), 0) as amount')->first();
            $strip[] = [
                'key' => $queue,
                'label' => $label,
                'count' => (int) ($row?->getAttribute('aggregate') ?? 0),
                'amount' => (int) ($row?->getAttribute('amount') ?? 0),
            ];
        }

        return $strip;
    }
}
