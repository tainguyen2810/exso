<?php

namespace Modules\Order\Contracts;

use App\Models\PaymentMethod;
use Modules\Order\Models\Order;

/**
 * What the order module asks of a promotions module (bound by the
 * Promotion module; NoPromotions otherwise). Orders never depend on how
 * promotions work: they hand over the priced lines and get back discounts
 * and gift lines, then let the promotions module count usage and points.
 */
interface OrderPromotions
{
    /**
     * Evaluate the programs and coupon chosen for an order. When $strict,
     * a choice that does not apply is a ValidationException; otherwise the
     * problem is reported in PromotionOutcome::$errors (preview).
     */
    public function apply(PromotionContext $context, bool $strict): PromotionOutcome;

    /**
     * The order was saved with this outcome: count the usage (locks the
     * promotion and coupon rows, refuses when a limit is reached). Runs in
     * the order's transaction.
     */
    public function record(Order $order, PromotionOutcome $outcome): void;

    /** Give back the usage of an order (edited or cancelled). */
    public function release(Order $order): void;

    /**
     * Money is about to be recorded for an order (amount < 0 = refund),
     * e.g. paying with loyalty points. May refuse with a ValidationException.
     */
    public function beforePayment(Order $order, PaymentMethod $method, int $amount): void;
}
