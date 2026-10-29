<?php

namespace Modules\Shipping\Carriers;

use Carbon\CarbonImmutable;

/**
 * Where a parcel is, as the carrier tells it: its current raw status and
 * the steps so far (oldest first).
 */
final readonly class Tracking
{
    /**
     * @param  list<array{carrier_status: string, note: string|null, occurred_at: CarbonImmutable}>  $events
     */
    public function __construct(
        public string $carrierStatus,
        public array $events = [],
        public ?int $codAmount = null,
        public ?int $fee = null,
    ) {}
}
