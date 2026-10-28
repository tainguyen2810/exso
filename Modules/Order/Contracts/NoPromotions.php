<?php

namespace Modules\Order\Contracts;

use App\Models\PaymentMethod;
use Illuminate\Validation\ValidationException;
use Modules\Order\Models\Order;

/**
 * Used when no promotions module is enabled: nothing applies, and paying
 * with points is refused.
 */
class NoPromotions implements OrderPromotions
{
    public function apply(PromotionContext $context, bool $strict): PromotionOutcome
    {
        $errors = $context->chosen() ? ['promotion_ids' => 'Cửa hàng chưa bật khuyến mại.'] : [];

        if ($strict && $errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new PromotionOutcome(errors: $errors);
    }

    public function record(Order $order, PromotionOutcome $outcome): void {}

    public function release(Order $order): void {}

    public function beforePayment(Order $order, PaymentMethod $method, int $amount): void
    {
        if ($method->type === 'points') {
            throw ValidationException::withMessages(['payment_method_id' => 'Cửa hàng chưa bật tích điểm.']);
        }
    }
}
