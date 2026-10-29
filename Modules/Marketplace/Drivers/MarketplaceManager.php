<?php

namespace Modules\Marketplace\Drivers;

use App\Support\Hooks\Hook;
use Modules\Marketplace\Models\MarketplaceShop;

/**
 * The marketplaces EXSO can connect to and their drivers.
 */
class MarketplaceManager
{
    /**
     * Platform key => class. Other modules add theirs with
     * Hook::addFilter('marketplace.drivers', fn (array $drivers) => [...]).
     *
     * @return array<string, class-string<MarketplaceDriver>>
     */
    public function drivers(): array
    {
        /** @var array<string, class-string<MarketplaceDriver>> $drivers */
        $drivers = Hook::filter('marketplace.drivers', ['shopee' => ShopeeDriver::class]);

        return $drivers;
    }

    public function has(string $platform): bool
    {
        return isset($this->drivers()[$platform]);
    }

    public function label(string $platform): string
    {
        $class = $this->drivers()[$platform] ?? null;

        return $class === null ? ucfirst($platform) : $class::label();
    }

    public function for(MarketplaceShop $shop): MarketplaceDriver
    {
        $class = $this->drivers()[$shop->platform] ?? null;

        if ($class === null) {
            throw new MarketplaceException("Chưa hỗ trợ sàn {$shop->platform}.");
        }

        return new $class($shop);
    }
}
