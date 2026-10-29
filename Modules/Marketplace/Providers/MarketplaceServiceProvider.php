<?php

namespace Modules\Marketplace\Providers;

use App\Models\User;
use App\Support\Hooks\Hook;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Inventory\Events\ReservationChanged;
use Modules\Inventory\Events\StockChanged;
use Modules\Inventory\Models\StockMovement;
use Modules\Marketplace\Console\SyncMarketplacesCommand;
use Modules\Marketplace\Drivers\MarketplaceManager;
use Modules\Marketplace\Http\Controllers\WebhookController;
use Modules\Marketplace\Models\MarketplaceOrder;
use Modules\Marketplace\Services\MarketplaceStockService;
use Modules\Order\Models\Order;

class MarketplaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/config.php', 'marketplace');
        $this->app->singleton(MarketplaceManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'verified'])->group(__DIR__.'/../routes/web.php');

            // Platforms call this without a session; the token finds the shop, the signature proves the caller.
            Route::post('webhooks/marketplace/{token}', WebhookController::class)
                ->middleware('throttle:300,1')
                ->name('marketplaces.webhook');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([SyncMarketplacesCommand::class]);
        }

        // New orders within minutes even without push calls; stock pushed again hourly.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('marketplace:sync')->everyFiveMinutes()->withoutOverlapping(10);
            $schedule->command('marketplace:sync --stock')->hourlyAt(20)->withoutOverlapping(30);
        });

        // "Có thể bán" changed: push it to the shops selling these products.
        Event::listen(StockChanged::class, fn (StockChanged $event) => app(MarketplaceStockService::class)->changed(
            array_map(fn (StockMovement $movement): int => $movement->variant_id, $event->movements),
        ));
        Event::listen(ReservationChanged::class, fn (ReservationChanged $event) => app(MarketplaceStockService::class)->changed($event->variantIds));

        // "Đơn sàn" card on the order page.
        Hook::addFilter('order.panels', function (array $panels, Order $order, ?User $user): array {
            $row = MarketplaceOrder::query()->with('shop')->where('order_id', $order->id)->first();

            if ($row === null) {
                return $panels;
            }

            $drivers = app(MarketplaceManager::class);

            return [...$panels, [
                'title' => 'Đơn sàn TMĐT',
                'href' => $user?->can('marketplaces.view') ? '/marketplaces/orders?search='.urlencode($row->external_id) : null,
                'lines' => [
                    ['label' => 'Sàn', 'value' => $drivers->label($row->shop->platform)],
                    ['label' => 'Gian hàng', 'value' => $row->shop->name],
                    ['label' => 'Mã đơn sàn', 'value' => $row->external_id],
                    ['label' => 'Trạng thái trên sàn', 'value' => MarketplaceOrder::STATES[$row->state] ?? $row->state],
                    ...($row->buyer_username !== null ? [['label' => 'Người mua', 'value' => $row->buyer_username]] : []),
                ],
            ]];
        });
    }
}
