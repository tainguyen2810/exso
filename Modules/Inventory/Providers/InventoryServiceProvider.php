<?php

namespace Modules\Inventory\Providers;

use App\Support\Hooks\Hook;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Inventory\Console\VerifyStockCommand;
use Modules\Inventory\Dashboard\StockSummary;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\StockLedger;

class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InventoryService::class);
        $this->app->singleton(StockLedger::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'verified'])->group(__DIR__.'/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyStockCommand::class]);
        }

        Hook::addFilter('dashboard.stock', new StockSummary);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('inventory:verify')->dailyAt('02:30')->withoutOverlapping();
        });
    }
}
