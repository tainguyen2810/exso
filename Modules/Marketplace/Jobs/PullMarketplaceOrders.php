<?php

namespace Modules\Marketplace\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Marketplace\Models\MarketplaceShop;
use Modules\Marketplace\Services\MarketplaceOrderService;

/**
 * Orders changed on the platform since the last run (every 5 minutes, or
 * "Cập nhật đơn hàng"), so new orders arrive even without push calls.
 */
class PullMarketplaceOrders implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ShopJob;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $uniqueFor = 900;

    public function __construct(public int $tenantId, public int $shopId, public ?int $hours = null) {}

    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->shopId}";
    }

    public function handle(MarketplaceOrderService $orders): void
    {
        $this->forShop($this->tenantId, $this->shopId, fn (MarketplaceShop $shop) => $orders->pull($shop, $this->hours));
    }
}
