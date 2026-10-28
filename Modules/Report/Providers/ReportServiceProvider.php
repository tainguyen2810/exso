<?php

namespace Modules\Report\Providers;

use App\Support\Hooks\Hook;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Order\Events\OrderReturned;
use Modules\Order\Events\OrderStatusChanged;
use Modules\Order\Models\Order;
use Modules\Report\Console\RebuildReportsCommand;
use Modules\Report\Dashboard\DailySalesSummary;
use Modules\Report\Jobs\RefreshSalesDay;
use Modules\Report\Support\ReportRegistry;

class ReportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportRegistry::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'verified'])->group(__DIR__.'/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([RebuildReportsCommand::class]);
        }

        // Nightly rebuild of the last days, in case a queued refresh was lost.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('reports:rebuild --days=3')->dailyAt('02:45')->withoutOverlapping();
        });

        // Completed orders and returns refresh their day on the queue.
        Event::listen(OrderStatusChanged::class, function (OrderStatusChanged $event): void {
            $order = $event->order;

            if ($order->status === Order::COMPLETED && $order->completed_at !== null) {
                RefreshSalesDay::dispatch((int) $order->tenant_id, $order->branch_id, $order->completed_at->setTimezone((string) config('app.timezone'))->toDateString());
            }
        });

        Event::listen(OrderReturned::class, function (OrderReturned $event): void {
            $return = $event->return;
            RefreshSalesDay::dispatch((int) tenancy()->id(), $return->branch_id, ($return->created_at ?? now())->setTimezone((string) config('app.timezone'))->toDateString());
        });

        // After the Order module (priority 10): revenue from the daily table.
        Hook::addFilter('dashboard.sales', new DailySalesSummary, 20);
    }
}
