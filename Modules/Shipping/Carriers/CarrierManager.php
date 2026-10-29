<?php

namespace Modules\Shipping\Carriers;

use App\Support\Hooks\Hook;
use Modules\Shipping\Models\Carrier;

/**
 * The integrated carriers EXSO can connect to and their drivers.
 */
class CarrierManager
{
    /**
     * Driver key => class. Other modules add theirs with
     * Hook::addFilter('shipping.drivers', fn (array $drivers) => [...]).
     *
     * @return array<string, class-string<CarrierDriver>>
     */
    public function drivers(): array
    {
        /** @var array<string, class-string<CarrierDriver>> $drivers */
        $drivers = Hook::filter('shipping.drivers', ['ghn' => GhnDriver::class]);

        return $drivers;
    }

    public function has(string $driver): bool
    {
        return isset($this->drivers()[$driver]);
    }

    public function for(Carrier $carrier): CarrierDriver
    {
        $class = $carrier->driver === null ? null : ($this->drivers()[$carrier->driver] ?? null);

        if ($class === null || ! $carrier->isIntegrated()) {
            throw new CarrierException("Đối tác {$carrier->name} không kết nối qua API.");
        }

        return new $class($carrier);
    }
}
