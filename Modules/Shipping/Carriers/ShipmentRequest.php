<?php

namespace Modules\Shipping\Carriers;

/**
 * What a carrier needs to price or create a delivery. Weight in grams,
 * sizes in cm, money in đồng.
 */
final readonly class ShipmentRequest
{
    /**
     * @param  list<array{name: string, code: string|null, quantity: int, price: int, weight: int}>  $items
     */
    public function __construct(
        public string $clientCode,
        public int $branchId,
        public string $receiverName,
        public string $receiverPhone,
        public string $address,
        public ?string $ward,
        public ?string $district,
        public ?string $province,
        public int $weight,
        public int $length,
        public int $width,
        public int $height,
        public int $codAmount,
        public int $insuranceValue = 0,
        public string $feePayer = 'shop',
        public ?string $serviceId = null,
        public ?string $requiredNote = null,
        public ?string $note = null,
        public array $items = [],
    ) {}
}
