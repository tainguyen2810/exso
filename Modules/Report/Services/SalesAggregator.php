<?php

namespace Modules\Report\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\OrderReturn;
use Modules\Order\Models\OrderReturnItem;
use Modules\Report\Models\ProductDaily;
use Modules\Report\Models\SalesDaily;
use RuntimeException;

/**
 * Builds the daily sales tables of the current store, one branch and day
 * at a time. Sales count on the day the order is completed (giao hàng
 * thành công, as Sapo's reports), returns on the day they are received.
 *
 *   Doanh thu        = tiền hàng − chiết khấu − hàng trả lại + phí giao hàng
 *   Doanh thu thuần  = tiền hàng − chiết khấu − hàng trả lại
 *   Lợi nhuận gộp    = doanh thu thuần − (giá vốn hàng bán − giá vốn hàng trả)
 *
 * @phpstan-type ProductRow array{source_id: int|null, variant_id: int|null, product_id: int|null, name: string, sku: string|null, quantity: int, goods_amount: int, discount: int, cost: int, returned_quantity: int, returned_amount: int, returned_cost: int}
 */
class SalesAggregator
{
    /**
     * Rebuild the rows of one branch and day from the orders and returns.
     */
    public function refresh(int $branchId, string $date): void
    {
        $tenantId = tenancy()->id() ?? throw new RuntimeException('Reports need a current store.');
        $timezone = (string) config('app.timezone');
        $start = CarbonImmutable::parse($date, $timezone)->startOfDay();
        $end = $start->endOfDay();

        $sales = [];
        $products = [];

        $orders = Order::query()
            ->with('items')
            ->where('branch_id', $branchId)
            ->where('status', Order::COMPLETED)
            ->whereBetween('completed_at', [$start, $end])
            ->get();

        foreach ($orders as $order) {
            $row = &$this->salesRow($sales, $order);
            $row['orders_count']++;
            $row['shipping_fee'] += $order->shipping_fee;
            $row['discount'] += $order->discount;
            $shares = $this->discountShares($order);

            foreach ($order->items as $item) {
                $gross = $item->price * $item->quantity;
                $row['items_quantity'] += $item->quantity;
                $row['goods_amount'] += $gross;
                $row['discount'] += $item->discount;
                $row['cost'] += $item->cost * $item->quantity;

                $line = &$this->productRow($products, $order, $item);
                $line['quantity'] += $item->quantity;
                $line['goods_amount'] += $gross;
                $line['discount'] += $item->discount + ($shares[$item->id] ?? 0);
                $line['cost'] += $item->cost * $item->quantity;
                unset($line);
            }

            unset($row);
        }

        $returns = OrderReturn::query()
            ->with(['order', 'items.orderItem'])
            ->where('branch_id', $branchId)
            ->whereBetween('created_at', [$start, $end])
            ->get();

        foreach ($returns as $return) {
            $row = &$this->salesRow($sales, $return->order);
            $row['returns_count']++;
            $row['returned_quantity'] += $return->quantity;
            $row['returned_amount'] += $return->total;

            foreach ($return->items as $returned) {
                /** @var OrderReturnItem $returned */
                $row['returned_cost'] += $returned->cost * $returned->quantity;

                $line = &$this->productRow($products, $return->order, $returned->orderItem);
                $line['returned_quantity'] += $returned->quantity;
                $line['returned_amount'] += $returned->total;
                $line['returned_cost'] += $returned->cost * $returned->quantity;
                unset($line);
            }

            unset($row);
        }

        $fill = fn (array $row): array => ['tenant_id' => $tenantId, 'date' => $start->toDateString(), 'branch_id' => $branchId, ...$row];

        DB::transaction(function () use ($branchId, $start, $sales, $products, $fill): void {
            SalesDaily::query()->where('branch_id', $branchId)->whereDate('date', $start->toDateString())->delete();
            ProductDaily::query()->where('branch_id', $branchId)->whereDate('date', $start->toDateString())->delete();

            foreach (array_chunk(array_map($fill, array_values($sales)), 500) as $chunk) {
                SalesDaily::query()->insert($chunk);
            }

            foreach (array_chunk(array_map($fill, array_values($products)), 500) as $chunk) {
                ProductDaily::query()->insert($chunk);
            }
        });
    }

    /**
     * Rebuild every branch and day with sales or returns in [$from, $to]
     * (all time when null). Returns the number of days rebuilt.
     */
    public function rebuild(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): int
    {
        $timezone = (string) config('app.timezone');
        $days = [];

        $collect = function (string $model, string $column) use (&$days, $from, $to, $timezone): void {
            $model::query()
                ->when($model === Order::class, fn ($query) => $query->where('status', Order::COMPLETED))
                ->whereNotNull($column)
                ->when($from !== null, fn ($query) => $query->where($column, '>=', $from))
                ->when($to !== null, fn ($query) => $query->where($column, '<=', $to))
                ->select(['branch_id', $column])
                ->orderBy('id')
                ->each(function ($record) use (&$days, $column, $timezone): void {
                    $date = CarbonImmutable::parse($record->getAttribute($column))->setTimezone($timezone)->toDateString();
                    $days[$record->getAttribute('branch_id').'|'.$date] = true;
                });
        };

        $collect(Order::class, 'completed_at');
        $collect(OrderReturn::class, 'created_at');

        // Days in the range that no longer have sales lose their rows.
        foreach ([SalesDaily::class, ProductDaily::class] as $table) {
            $table::query()
                ->when($from !== null, fn ($query) => $query->whereDate('date', '>=', $from->toDateString()))
                ->when($to !== null, fn ($query) => $query->whereDate('date', '<=', $to->toDateString()))
                ->delete();
        }

        foreach (array_keys($days) as $key) {
            [$branchId, $date] = explode('|', $key);
            $this->refresh((int) $branchId, $date);
        }

        return count($days);
    }

    /**
     * @param  array<string, array<string, int|null>>  $rows
     * @return array<string, int|null>
     */
    protected function &salesRow(array &$rows, Order $order): array
    {
        $staff = $order->assigned_to ?? $order->created_by;
        $key = $order->source_id.'|'.$staff.'|'.$order->customer_id;

        if (! isset($rows[$key])) {
            $rows[$key] = [
                'source_id' => $order->source_id,
                'staff_id' => $staff,
                'customer_id' => $order->customer_id,
                'orders_count' => 0, 'items_quantity' => 0, 'goods_amount' => 0, 'discount' => 0,
                'shipping_fee' => 0, 'cost' => 0, 'returns_count' => 0, 'returned_quantity' => 0,
                'returned_amount' => 0, 'returned_cost' => 0,
            ];
        }

        return $rows[$key];
    }

    /**
     * @param  array<string, ProductRow>  $rows
     * @return ProductRow
     */
    protected function &productRow(array &$rows, Order $order, OrderItem $item): array
    {
        $key = $order->source_id.'|'.($item->variant_id ?? 'name:'.$item->name);

        if (! isset($rows[$key])) {
            $rows[$key] = [
                'source_id' => $order->source_id,
                'variant_id' => $item->variant_id,
                'product_id' => $item->product_id,
                'name' => $item->name,
                'sku' => $item->sku,
                'quantity' => 0, 'goods_amount' => 0, 'discount' => 0, 'cost' => 0,
                'returned_quantity' => 0, 'returned_amount' => 0, 'returned_cost' => 0,
            ];
        }

        return $rows[$key];
    }

    /**
     * The order discount spread over the lines by value; the rounding
     * remainder goes to the biggest line so the parts add up.
     *
     * @return array<int, int> order item id => share
     */
    protected function discountShares(Order $order): array
    {
        if ($order->discount <= 0 || $order->subtotal <= 0 || $order->items->isEmpty()) {
            return [];
        }

        $shares = [];

        foreach ($order->items as $item) {
            $shares[$item->id] = intdiv($order->discount * $item->total, $order->subtotal);
        }

        $biggest = $order->items->sortByDesc('total')->first();
        $shares[$biggest->id] += $order->discount - array_sum($shares);

        return $shares;
    }
}
