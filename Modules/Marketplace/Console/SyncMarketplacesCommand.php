<?php

namespace Modules\Marketplace\Console;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Modules\Marketplace\Jobs\PullMarketplaceOrders;
use Modules\Marketplace\Jobs\PushMarketplaceStock;
use Modules\Marketplace\Models\MarketplaceShop;
use Modules\Marketplace\Services\MarketplaceStockService;

/**
 * Every 5 minutes: queue a pull of changed orders for each connected shop,
 * so orders arrive even when the platform's push calls cannot reach the
 * server. With --stock (hourly): push the stock of every linked product again.
 */
class SyncMarketplacesCommand extends Command
{
    protected $signature = 'marketplace:sync {--stock : Đẩy lại tồn kho của mọi sản phẩm đã liên kết}';

    protected $description = 'Lấy đơn mới từ sàn TMĐT (và đẩy tồn kho lên sàn với --stock)';

    public function handle(MarketplaceStockService $stock): int
    {
        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            if (! $tenant->isActive()) {
                continue;
            }

            $count = tenancy()->run($tenant, function () use ($stock): int {
                $shops = MarketplaceShop::query()->where('status', MarketplaceShop::ACTIVE)->get();

                foreach ($shops as $shop) {
                    if ($this->option('stock')) {
                        $stock->markAll($shop);
                        PushMarketplaceStock::dispatch((int) $shop->tenant_id, $shop->id);
                    } elseif ($shop->setting('sync_orders', true)) {
                        PullMarketplaceOrders::dispatch((int) $shop->tenant_id, $shop->id);
                    }
                }

                return $shops->count();
            });

            if ($count > 0) {
                $this->info("{$tenant->name}: {$count} gian hàng.");
            }
        }

        return self::SUCCESS;
    }
}
