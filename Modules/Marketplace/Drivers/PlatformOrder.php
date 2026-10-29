<?php

namespace Modules\Marketplace\Drivers;

use Carbon\CarbonImmutable;

/**
 * An order as read from the platform's API (never from a push payload).
 *
 * @phpstan-type PlatformLine array{item_id: string, model_id: string, sku: string|null, name: string, quantity: int, price: int, original_price: int}
 */
final class PlatformOrder
{
    /**
     * @param  string  $state  one of MarketplaceOrder::STATES
     * @param  list<PlatformLine>  $items
     */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $state,
        public readonly array $items,
        public readonly int $total,
        public readonly ?string $buyerId = null,
        public readonly ?string $buyerUsername = null,
        public readonly ?string $recipientName = null,
        public readonly ?string $recipientPhone = null,
        public readonly ?string $address = null,
        public readonly ?string $carrier = null,
        public readonly ?string $trackingCode = null,
        public readonly ?string $note = null,
        public readonly ?CarbonImmutable $placedAt = null,
        public readonly ?CarbonImmutable $updatedAt = null,
    ) {}
}
