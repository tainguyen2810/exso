<?php

namespace Modules\Order\Providers;

use App\Support\Hooks\Hook;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Customer\Models\CustomerDebt;
use Modules\Order\Contracts\NoPromotions;
use Modules\Order\Contracts\NoShipping;
use Modules\Order\Contracts\OrderPromotions;
use Modules\Order\Contracts\OrderShipping;
use Modules\Order\Dashboard\SalesSummary;
use Modules\Order\Http\Controllers\OrderController;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderReturn;
use Modules\Order\Services\OrderQueues;
use Modules\Order\Services\OrderService;
use Modules\Order\Services\PosService;
use Modules\Order\Services\PriceResolver;
use Modules\Order\Services\ReturnService;

class OrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The Promotion module binds the real engine; without it nothing applies.
        $this->app->singletonIf(OrderPromotions::class, NoPromotions::class);
        // The Shipping module binds the partners; without it only manual delivery.
        $this->app->singletonIf(OrderShipping::class, NoShipping::class);
        $this->app->singleton(PriceResolver::class);
        $this->app->singleton(OrderService::class);
        $this->app->singleton(ReturnService::class);
        $this->app->singleton(PosService::class);
        $this->app->singleton(OrderQueues::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'verified'])->group(__DIR__.'/../routes/web.php');
        }

        Hook::addFilter('dashboard.sales', new SalesSummary);
        // Purchase history tab and order links on the customer page.
        Hook::addFilter('customer.orders', OrderController::customerOrders(...));
        Hook::addFilter('customer.reference_url', fn (?string $url, CustomerDebt $line): ?string => match ($line->reference_type) {
            (new Order)->getMorphClass() => "/orders/{$line->reference_id}",
            (new OrderReturn)->getMorphClass() => "/returns/{$line->reference_id}",
            default => $url,
        });
    }
}
