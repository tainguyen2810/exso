<?php

namespace Modules\Einvoice\Drivers;

use App\Support\Hooks\Hook;
use Modules\Einvoice\Models\EinvoiceProvider;

/**
 * The e-invoice providers EXSO can connect to and their drivers.
 */
class EinvoiceManager
{
    /**
     * Driver key => class. Other modules add theirs with
     * Hook::addFilter('einvoice.drivers', fn (array $drivers) => [...]).
     *
     * @return array<string, class-string<EinvoiceDriver>>
     */
    public function drivers(): array
    {
        /** @var array<string, class-string<EinvoiceDriver>> $drivers */
        $drivers = Hook::filter('einvoice.drivers', ['viettel' => ViettelDriver::class]);

        return $drivers;
    }

    public function has(string $driver): bool
    {
        return isset($this->drivers()[$driver]);
    }

    public function for(EinvoiceProvider $provider): EinvoiceDriver
    {
        $class = $this->drivers()[$provider->driver] ?? null;

        if ($class === null) {
            throw new EinvoiceException("Chưa hỗ trợ nhà cung cấp {$provider->driver}.");
        }

        return new $class($provider);
    }

    /** The connection used to issue: the active one. */
    public function active(): ?EinvoiceProvider
    {
        return EinvoiceProvider::query()->where('is_active', true)->whereNotNull('connected_at')->orderBy('id')->first();
    }
}
