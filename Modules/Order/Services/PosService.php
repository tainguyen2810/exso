<?php

namespace Modules\Order\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderPayment;
use Modules\Order\Models\PosShift;

/**
 * Selling at the counter ("Bán tại quầy"): cashier shifts and one-step
 * sales. A POS sale is created, exported, paid and completed in one
 * transaction; change given back is not recorded as money received.
 *
 * @phpstan-import-type OrderInput from OrderService
 */
class PosService
{
    public function __construct(protected OrderService $orders) {}

    public function currentShift(User $user, int $branchId): ?PosShift
    {
        return PosShift::query()
            ->where('user_id', $user->id)
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->latest('id')
            ->first();
    }

    public function openShift(User $user, int $branchId, int $openingCash): PosShift
    {
        return DB::transaction(function () use ($user, $branchId, $openingCash): PosShift {
            if ($this->currentShift($user, $branchId) !== null) {
                throw ValidationException::withMessages(['opening_cash' => 'Bạn đang có ca làm việc chưa chốt ở chi nhánh này.']);
            }

            /** @var PosShift $shift */
            $shift = PosShift::query()->create([
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'status' => 'open',
                'opened_at' => now(),
                'opening_cash' => max(0, $openingCash),
            ]);

            return $shift;
        });
    }

    /**
     * Totals of a shift: cash in/out of its payments, orders and sales.
     *
     * @return array{cash_in: int, cash_out: int, expected_cash: int, orders_count: int, sales_total: int, by_method: list<array{name: string, amount: int}>}
     */
    public function summary(PosShift $shift): array
    {
        $payments = OrderPayment::query()->where('pos_shift_id', $shift->id);
        $cashIn = (int) (clone $payments)->where('method_type', 'cash')->where('amount', '>', 0)->sum('amount');
        $cashOut = (int) -(clone $payments)->where('method_type', 'cash')->where('amount', '<', 0)->sum('amount');
        $orders = Order::query()->where('pos_shift_id', $shift->id)->where('status', '!=', Order::CANCELLED);

        return [
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'expected_cash' => $shift->opening_cash + $cashIn - $cashOut,
            'orders_count' => (clone $orders)->count(),
            'sales_total' => (int) (clone $orders)->sum('total'),
            'by_method' => array_values((clone $payments)
                ->selectRaw('method_name, SUM(amount) as amount')
                ->groupBy('method_name')
                ->get()
                ->map(fn ($row): array => ['name' => (string) $row->getAttribute('method_name'), 'amount' => (int) $row->getAttribute('amount')])
                ->all()),
        ];
    }

    /**
     * Chốt ca: compare the counted cash with what the drawer should hold.
     */
    public function closeShift(PosShift $shift, int $countedCash, ?string $note = null): PosShift
    {
        return DB::transaction(function () use ($shift, $countedCash, $note): PosShift {
            /** @var PosShift $locked */
            $locked = PosShift::query()->lockForUpdate()->findOrFail($shift->id);

            if ($locked->status !== 'open') {
                throw ValidationException::withMessages(['counted_cash' => 'Ca làm việc đã chốt.']);
            }

            $summary = $this->summary($locked);
            $locked->forceFill([
                'status' => 'closed',
                'closed_at' => now(),
                'cash_in' => $summary['cash_in'],
                'cash_out' => $summary['cash_out'],
                'expected_cash' => $summary['expected_cash'],
                'counted_cash' => max(0, $countedCash),
                'difference' => max(0, $countedCash) - $summary['expected_cash'],
                'orders_count' => $summary['orders_count'],
                'sales_total' => $summary['sales_total'],
                'note' => $note,
            ])->save();

            return $locked;
        });
    }

    /**
     * Sell at the counter. Payments beyond the total are change given back.
     * A walk-in customer (no customer picked) must pay in full.
     *
     * @param  OrderInput  $data
     */
    public function sell(array $data, User $user, PosShift $shift): Order
    {
        if ($shift->status !== 'open' || $shift->user_id !== $user->id) {
            throw ValidationException::withMessages(['shift' => 'Hãy mở ca làm việc trước khi bán hàng.']);
        }

        $data['branch_id'] = $shift->branch_id;
        $data['channel'] = 'pos';
        $data['delivery_method'] = 'pickup';
        $data['pos_shift_id'] = $shift->id;
        $data['shipping_fee'] = 0;

        $customer = isset($data['customer_id']) ? (int) $data['customer_id'] : null;

        return DB::transaction(function () use ($data, $user, $customer): Order {
            $payments = $data['payments'] ?? [];
            unset($data['payments']);

            $order = $this->orders->create($data, $user, 'export');
            $left = $order->total;

            foreach ($payments as $payment) {
                $amount = min((int) $payment['amount'], $left);

                if ($amount > 0) {
                    $this->orders->pay($order, (int) $payment['payment_method_id'], $amount);
                    $left -= $amount;
                }
            }

            if ($left > 0 && $customer === null) {
                throw ValidationException::withMessages(['payments' => 'Khách lẻ phải thanh toán đủ '.number_format($order->total, 0, ',', '.').'. Chọn khách hàng để ghi nợ.']);
            }

            $this->orders->refreshCustomer($order->customer_id);

            return $order->refresh();
        });
    }
}
