<?php

namespace Modules\Shipping\Carriers;

use Carbon\CarbonImmutable;

/** One service of a carrier with its fee ("gói cước"). */
final readonly class Quote
{
    public function __construct(
        public string $serviceId,
        public string $serviceName,
        public int $fee,
        public ?CarbonImmutable $expectedAt = null,
    ) {}

    /**
     * @return array{service_id: string, service_name: string, fee: int, expected_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'service_id' => $this->serviceId,
            'service_name' => $this->serviceName,
            'fee' => $this->fee,
            'expected_at' => $this->expectedAt?->toIso8601String(),
        ];
    }
}
