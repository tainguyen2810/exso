<?php

namespace Modules\Promotion\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Customer\Models\Customer;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderPayment;
use Modules\Promotion\Models\LoyaltyAccount;
use Modules\Promotion\Models\LoyaltyPoint;
use Modules\Promotion\Models\LoyaltyTier;

/**
 * Loyalty points ("Tích điểm"): the only place a balance changes. Each
 * change locks the customer's account row and writes a LoyaltyPoint line
 * with the balance after it.
 *
 *  - an order earns floor(value / loyalty.earn_amount) points × the tier's
 *    multiplier once it is completed; value = total − returned − shipping −
 *    what was paid with points. Returns take points back (syncOrder keeps
 *    the order's net points equal to what it should have earned).
 *  - paying with points takes ceil(amount / loyalty.redeem_value) points;
 *    refunding such a payment gives floor(amount / value) back.
 */
class LoyaltyService
{
    public function enabled(): bool
    {
        return (bool) settings('loyalty.enabled');
    }

    public function earnAmount(): int
    {
        return max(1, (int) settings('loyalty.earn_amount'));
    }

    public function redeemValue(): int
    {
        return max(1, (int) settings('loyalty.redeem_value'));
    }

    public function account(int $customerId): ?LoyaltyAccount
    {
        /** @var LoyaltyAccount|null $account */
        $account = LoyaltyAccount::query()->with('tier')->where('customer_id', $customerId)->first();

        return $account;
    }

    /**
     * Add (or take, points < 0) points. A balance may only go below zero
     * when $allowNegative (points taken back for returned goods).
     *
     * @param  array{reference?: Model|null, reference_code?: string|null, note?: string|null, allow_negative?: bool}  $options
     */
    public function record(int $customerId, string $type, int $points, array $options = []): ?LoyaltyPoint
    {
        if ($points === 0) {
            return null;
        }

        return DB::transaction(function () use ($customerId, $type, $points, $options): LoyaltyPoint {
            LoyaltyAccount::query()->firstOrCreate(['customer_id' => $customerId]);
            /** @var LoyaltyAccount $account */
            $account = LoyaltyAccount::query()->where('customer_id', $customerId)->lockForUpdate()->firstOrFail();
            $after = $account->points + $points;

            if ($after < 0 && ! ($options['allow_negative'] ?? false)) {
                throw ValidationException::withMessages(['points' => 'Khách hàng chỉ còn '.number_format($account->points, 0, ',', '.').' điểm.']);
            }

            $account->points = $after;

            // Earned = points from orders (less returns) and manual additions;
            // used = points paid with, less refunds.
            if (in_array($type, [LoyaltyPoint::EARN, LoyaltyPoint::RETURN], true) || ($type === LoyaltyPoint::ADJUST && $points > 0)) {
                $account->earned_total += $points;
            } elseif (in_array($type, [LoyaltyPoint::REDEEM, LoyaltyPoint::REFUND], true)) {
                $account->used_total -= $points;
            }

            $account->tier_id = $this->tierFor($customerId)?->id;
            $account->save();

            $reference = $options['reference'] ?? null;

            /** @var LoyaltyPoint $line */
            $line = LoyaltyPoint::query()->create([
                'customer_id' => $customerId,
                'type' => $type,
                'points' => $points,
                'balance_after' => $after,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'reference_code' => $options['reference_code'] ?? null,
                'note' => isset($options['note']) ? mb_substr($options['note'], 0, 500) : null,
                'created_by' => auth()->id(),
                'created_at' => now(),
            ]);

            return $line;
        });
    }

    /**
     * Points an order should have earned now.
     */
    public function pointsFor(Order $order): int
    {
        if ($order->customer_id === null || $order->status !== Order::COMPLETED) {
            return 0;
        }

        $byPoints = (int) OrderPayment::query()->where('order_id', $order->id)->where('method_type', 'points')->sum('amount');
        $value = max(0, $order->total - $order->returned_total - $order->shipping_fee - $byPoints);
        $points = intdiv($value, $this->earnAmount());
        $tier = $this->tierFor($order->customer_id);

        return $tier === null ? $points : intdiv($points * $tier->earn_multiplier_bp, 10000);
    }

    /**
     * Bring the points of an order in line with what it should have earned
     * (after completion, a return or a cancellation). Safe to run again.
     */
    public function syncOrder(Order $order): void
    {
        if ($order->customer_id === null) {
            return;
        }

        $current = (int) LoyaltyPoint::query()
            ->where('reference_type', $order->getMorphClass())
            ->where('reference_id', $order->id)
            ->whereIn('type', [LoyaltyPoint::EARN, LoyaltyPoint::RETURN])
            ->sum('points');

        // With loyalty off, orders stop earning, but points already given
        // are still taken back on returns.
        if (! $this->enabled() && $current === 0) {
            return;
        }

        $target = $this->enabled() ? $this->pointsFor($order) : min($current, $this->pointsFor($order));
        $diff = $target - $current;

        if ($diff !== 0) {
            $this->record($order->customer_id, $diff > 0 ? LoyaltyPoint::EARN : LoyaltyPoint::RETURN, $diff, [
                'reference' => $order,
                'reference_code' => $order->code,
                'allow_negative' => true,
            ]);
        }
    }

    /**
     * Money recorded with the "points" payment method: take or give back points.
     */
    public function payWithPoints(Order $order, int $amount): void
    {
        if (! $this->enabled()) {
            throw ValidationException::withMessages(['payment_method_id' => 'Cửa hàng chưa bật tích điểm (Loyalty).']);
        }

        if ($order->customer_id === null) {
            throw ValidationException::withMessages(['payment_method_id' => 'Chọn khách hàng để thanh toán bằng điểm.']);
        }

        if ($amount > 0) {
            $points = intdiv($amount + $this->redeemValue() - 1, $this->redeemValue());
            $balance = $this->account($order->customer_id)->points ?? 0;

            if ($points > $balance) {
                throw ValidationException::withMessages(['payment_method_id' => 'Khách hàng có '.number_format($balance, 0, ',', '.').' điểm, chỉ thanh toán được tối đa '.number_format($balance * $this->redeemValue(), 0, ',', '.').'đ.']);
            }

            $this->record($order->customer_id, LoyaltyPoint::REDEEM, -$points, ['reference' => $order, 'reference_code' => $order->code]);
        } elseif ($amount < 0) {
            $this->record($order->customer_id, LoyaltyPoint::REFUND, intdiv(-$amount, $this->redeemValue()), ['reference' => $order, 'reference_code' => $order->code]);
        }
    }

    /**
     * The highest tier the customer's total spending reaches.
     */
    public function tierFor(int $customerId): ?LoyaltyTier
    {
        $spent = (int) Customer::query()->withTrashed()->whereKey($customerId)->value('total_spent');

        /** @var LoyaltyTier|null $tier */
        $tier = LoyaltyTier::query()->where('min_spent', '<=', $spent)->orderByDesc('min_spent')->first();

        return $tier;
    }

    /**
     * Put every account in the tier its spending reaches (after tiers change).
     */
    public function refreshTiers(): void
    {
        $tiers = LoyaltyTier::query()->orderByDesc('min_spent')->get();

        LoyaltyAccount::query()->with('customer:id,total_spent')->chunkById(500, function ($accounts) use ($tiers): void {
            foreach ($accounts as $account) {
                $spent = $account->customer->total_spent ?? 0;
                $tier = $tiers->first(fn (LoyaltyTier $tier): bool => $tier->min_spent <= $spent);

                if ($account->tier_id !== $tier?->id) {
                    $account->forceFill(['tier_id' => $tier?->id])->save();
                }
            }
        });
    }

    /**
     * What the order form and POS show for a customer.
     *
     * @return array{points: int, value: int, tier: string|null}|null
     */
    public function summary(Customer $customer): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $account = $this->account($customer->id);

        return [
            'points' => $account->points ?? 0,
            'value' => ($account->points ?? 0) * $this->redeemValue(),
            'tier' => $account->tier->name ?? $this->tierFor($customer->id)?->name,
        ];
    }
}
