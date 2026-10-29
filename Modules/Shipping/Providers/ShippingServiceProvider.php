<?php

namespace Modules\Shipping\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Order\Contracts\OrderShipping;
use Modules\Order\Events\OrderStatusChanged;
use Modules\Shipping\Carriers\CarrierManager;
use Modules\Shipping\Console\SyncShipmentsCommand;
use Modules\Shipping\Http\Controllers\WebhookController;
use Modules\Shipping\Services\CodReconciliationService;
use Modules\Shipping\Services\OrderShippingBridge;
use Modules\Shipping\Services\ShipmentService;

class ShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/config.php', 'shipping');
        $this->app->singleton(CarrierManager::class);
        $this->app->singleton(ShipmentService::class);
        $this->app->singleton(CodReconciliationService::class);
        // Orders get their shipping partners and shipments from here.
        $this->app->singleton(OrderShipping::class, fn ($app) => $app->make(OrderShippingBridge::class));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'verified'])->group(__DIR__.'/../routes/web.php');

            // Carriers call this without a session; the token identifies the connection.
            Route::post('webhooks/shipping/{token}', WebhookController::class)
                ->middleware('throttle:120,1')
                ->name('shipping.webhook');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([SyncShipmentsCommand::class]);
        }

        // Statuses follow even when the carrier cannot reach the webhook.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('shipping:sync')->everyThirtyMinutes()->withoutOverlapping(25);
        });

        // Steps done on the order page move its shipment.
        Event::listen(OrderStatusChanged::class, fn (OrderStatusChanged $event) => app(ShipmentService::class)->followOrder($event->order, $event->action));
    }
}
