<?php

namespace Modules\Order\Contracts;

/**
 * Result of the promotions for an order.
 *
 * @phpstan-type LineDiscount array{amount: int, promotion_id: int, name: string}
 * @phpstan-type GiftLine array{variant_id: int, promotion_id: int, row: int, quantity: int}
 * @phpstan-type Applied array{promotion_id: int|null, coupon_id: int|null, name: string, code: string|null, amount: int}
 */
final class PromotionOutcome
{
    /**
     * @param  array<int, LineDiscount>  $lineDiscounts  index of the input line => discount on the whole line
     * @param  list<GiftLine>  $gifts  free lines to add
     * @param  int  $orderDiscount  discount on the whole order (programs + coupon)
     * @param  list<Applied>  $applied  what was applied, for usage counting and display
     * @param  array<string, string>  $errors  field => message (preview only)
     * @param  list<array<string, mixed>>  $available  programs the order qualifies for (preview)
     */
    public function __construct(
        public readonly array $lineDiscounts = [],
        public readonly array $gifts = [],
        public readonly int $orderDiscount = 0,
        public readonly array $applied = [],
        public readonly array $errors = [],
        public readonly array $available = [],
    ) {}
}
