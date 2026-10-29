<?php

namespace Modules\Marketplace\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Marketplace\Models\MarketplaceShop;
use Modules\Marketplace\Services\MarketplaceStockService;

/**
 * Push the marked listings of a shop. Many stock changes before the job
 * runs make one job.
 */
class PushMarketplaceStock implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ShopJob;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $tenantId, public int $shopId) {}

    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->shopId}";
    }

    public function handle(MarketplaceStockService $stock): void
    {
        $this->forShop($this->tenantId, $this->shopId, fn (MarketplaceShop $shop) => $stock->push($shop));
    }
}
