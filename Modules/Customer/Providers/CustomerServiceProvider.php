<?php

namespace Modules\Customer\Providers;

use App\Support\Hooks\Hook;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Modules\Customer\Models\CustomerGroup;
use Modules\Customer\Services\CustomerLedger;
use Modules\Customer\Services\CustomerService;

class CustomerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CustomerLedger::class);
        $this->app->singleton(CustomerService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'verified'])->group(__DIR__.'/../routes/web.php');
        }

        // Default groups of a new store (Bán lẻ, Bán buôn, Đại lý, Vip).
        Hook::addFilter('store.defaults', function (mixed $value): mixed {
            // Older migrations install store defaults before this table exists.
            if (Schema::hasTable('customer_groups')) {
                CustomerGroup::installDefaults();
            }

            return $value;
        });
    }
}
