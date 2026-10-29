<?php

namespace Modules\Marketplace\Jobs;

use App\Models\Tenant;
use Closure;
use Modules\Marketplace\Drivers\MarketplaceException;
use Modules\Marketplace\Models\MarketplaceShop;

/**
 * Runs work for one shop inside its store. When the platform says the
 * authorisation ended, the shop is marked "Hết hạn" so the pages ask the
 * seller to authorise again.
 */
trait ShopJob
{
    protected function forShop(int $tenantId, int $shopId, Closure $work): void
    {
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null || ! $tenant->isActive()) {
            return;
        }

        tenancy()->run($tenant, function () use ($shopId, $work): void {
            $shop = MarketplaceShop::query()->find($shopId);

            if ($shop === null || ! $shop->isActive()) {
                return;
            }

            try {
                $work($shop);
            } catch (MarketplaceException $exception) {
                $shop->forceFill([
                    'last_error' => mb_substr($exception->getMessage(), 0, 500),
                    'status' => $exception->authExpired ? MarketplaceShop::EXPIRED : $shop->status,
                ])->save();

                if (! $exception->authExpired) {
                    throw $exception;
                }
            }
        });
    }
}
