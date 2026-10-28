<?php

namespace Modules\Promotion\Providers;

use App\Models\User;
use App\Support\Hooks\Hook;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Customer\Models\Customer;
use Modules\Order\Contracts\OrderPromotions;
use Modules\Order\Events\OrderReturned;
use Modules\Order\Events\OrderStatusChanged;
use Modules\Promotion\Services\LoyaltyService;
use Modules\Promotion\Services\PromotionEngine;

class PromotionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LoyaltyService::class);
        $this->app->singleton(PromotionEngine::class);
        // Orders get their discounts, gifts and points payments from here.
        $this->app->singleton(OrderPromotions::class, fn ($app) => $app->make(PromotionEngine::class));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'verified'])->group(__DIR__.'/../routes/web.php');
        }

        // Points follow the order: earned when completed, taken back on returns.
        Event::listen(OrderStatusChanged::class, fn (OrderStatusChanged $event) => app(LoyaltyService::class)->syncOrder($event->order));
        Event::listen(OrderReturned::class, fn (OrderReturned $event) => app(LoyaltyService::class)->syncOrder($event->return->order));

        // Points of the customer picked in the order form / POS.
        Hook::addFilter('customer.option', fn (array $value, Customer $customer): array => [
            ...$value,
            'loyalty' => app(LoyaltyService::class)->summary($customer),
        ]);

        // "Điểm tích lũy" card on the customer page.
        Hook::addFilter('customer.panels', function (array $panels, Customer $customer, ?User $user): array {
            $loyalty = app(LoyaltyService::class);
            $summary = $loyalty->summary($customer);

            if ($summary === null || $user === null || ! $user->can('loyalty.view')) {
                return $panels;
            }

            $account = $loyalty->account($customer->id);

            return [...$panels, [
                'title' => 'Điểm tích lũy',
                'href' => '/loyalty/points?filter[customer_id]='.$customer->id,
                'lines' => [
                    ['label' => 'Hạng thành viên', 'value' => $summary['tier'] ?? '—'],
                    ['label' => 'Điểm hiện tại', 'value' => number_format($summary['points'], 0, ',', '.')],
                    ['label' => 'Quy đổi', 'value' => number_format($summary['value'], 0, ',', '.').'đ'],
                    ['label' => 'Tổng điểm đã tích', 'value' => number_format($account->earned_total ?? 0, 0, ',', '.')],
                    ['label' => 'Điểm đã dùng', 'value' => number_format($account->used_total ?? 0, 0, ',', '.')],
                ],
            ]];
        });
    }
}
