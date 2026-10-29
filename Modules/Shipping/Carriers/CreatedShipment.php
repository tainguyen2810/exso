<?php

namespace Modules\Shipping\Carriers;

use Carbon\CarbonImmutable;

/** What the carrier answers when a delivery is created. */
final readonly class CreatedShipment
{
    public function __construct(
        public string $trackingCode,
        public int $fee,
        public ?string $carrierStatus = null,
        public ?CarbonImmutable $expectedAt = null,
    ) {}
}
