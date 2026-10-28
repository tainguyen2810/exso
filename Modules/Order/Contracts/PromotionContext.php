<?php

namespace Modules\Order\Contracts;

use Carbon\CarbonImmutable;
use Modules\Customer\Models\Customer;

/**
 * An order as the promotions see it: lines already priced by the order
 * module (line discount = what the user typed), and what was chosen.
 *
 * @phpstan-type PromoLine array{variant_id: int|null, product_id: int|null, product_type: string, name: string, quantity: int, price: int, discount: int, total: int}
 */
final class PromotionContext
{
    /**
     * @param  list<PromoLine>  $lines
     * @param  list<int>  $promotionIds  programs the user applied
     * @param  array<string, int>  $gifts  "<promotion id>-<row>" => gift variant chosen
     * @param  list<int>  $keepIds  programs already on the order being edited: kept even if they ended since
     */
    public function __construct(
        public readonly array $lines,
        public readonly ?Customer $customer,
        public readonly int $branchId,
        public readonly ?int $sourceId,
        public readonly array $promotionIds = [],
        public readonly ?string $couponCode = null,
        public readonly array $gifts = [],
        public readonly array $keepIds = [],
        public readonly ?string $keepCoupon = null,
        public readonly ?CarbonImmutable $at = null,
    ) {}

    public function chosen(): bool
    {
        return $this->promotionIds !== [] || ($this->couponCode !== null && $this->couponCode !== '');
    }
}
