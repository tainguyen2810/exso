<?php

namespace Modules\Purchase\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Purchase\Services\PurchaseService;
use Modules\Purchase\Services\SupplierLedger;

class PurchaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SupplierLedger::class);
        $this->app->singleton(PurchaseService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'verified'])->group(__DIR__.'/../routes/web.php');
        }
    }
}
