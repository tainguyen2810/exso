<?php

namespace Modules\Marketplace\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Marketplace\Models\MarketplaceShop;
use Modules\Marketplace\Services\MarketplaceOrderService;

/**
 * Fetch some orders of a shop from the platform and follow them (after a
 * push call, or "Đồng bộ lại"). Retried when the platform cannot be reached.
 */
class SyncMarketplaceOrders implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ShopJob;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    /**
     * @param  list<string>  $orderIds
     */
    public function __construct(public int $tenantId, public int $shopId, public array $orderIds) {}

    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->shopId}:".md5(implode(',', $this->orderIds));
    }

    public function handle(MarketplaceOrderService $orders): void
    {
        $this->forShop($this->tenantId, $this->shopId, fn (MarketplaceShop $shop) => $orders->syncIds($shop, $this->orderIds));
    }
}
