<?php

namespace Modules\Marketplace\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Marketplace\Models\MarketplaceShop;
use Modules\Marketplace\Services\MarketplaceListingService;
use Modules\Marketplace\Services\MarketplaceOrderService;
use Modules\Marketplace\Services\MarketplaceStockService;

/**
 * "Cập nhật dữ liệu sản phẩm": read the shop's listings, link new ones by
 * SKU, then push stock and bring in orders that waited for a link.
 */
class FetchMarketplaceListings implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ShopJob;

    public int $tries = 2;

    public int $timeout = 600;

    public int $uniqueFor = 1800;

    public function __construct(public int $tenantId, public int $shopId) {}

    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->shopId}";
    }

    public function handle(MarketplaceListingService $listings, MarketplaceStockService $stock, MarketplaceOrderService $orders): void
    {
        $this->forShop($this->tenantId, $this->shopId, function (MarketplaceShop $shop) use ($listings, $stock, $orders): void {
            $listings->fetch($shop);
            $stock->markAll($shop);
            $stock->push($shop);
            $orders->retryUnlinked($shop);
        });
    }
}
