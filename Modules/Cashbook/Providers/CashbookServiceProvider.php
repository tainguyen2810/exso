<?php

namespace Modules\Cashbook\Providers;

use App\Support\Hooks\Hook;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Modules\Cashbook\Console\SyncVouchersCommand;
use Modules\Cashbook\Models\CashVoucher;
use Modules\Cashbook\Models\CashVoucherType;
use Modules\Cashbook\Services\CashbookService;
use Modules\Customer\Models\CustomerDebt;
use Modules\Order\Events\OrderPaymentRecorded;
use Modules\Purchase\Events\SupplierPaymentRecorded;

class CashbookServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CashbookService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'verified'])->group(__DIR__.'/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([SyncVouchersCommand::class]);
        }

        // Money of orders and supplier payments become automatic vouchers.
        Event::listen(OrderPaymentRecorded::class, fn (OrderPaymentRecorded $event) => app(CashbookService::class)->recordOrderPayment($event->payment));
        Event::listen(SupplierPaymentRecorded::class, fn (SupplierPaymentRecorded $event) => app(CashbookService::class)->recordSupplierPayment($event->line));

        Hook::addFilter('store.defaults', function (mixed $value): mixed {
            // Older migrations install store defaults before this table exists.
            if (Schema::hasTable('cash_voucher_types')) {
                CashVoucherType::installDefaults();
            }

            return $value;
        });

        // Debt lines written by a voucher link to it on the customer page.
        Hook::addFilter('customer.reference_url', fn (?string $url, CustomerDebt $line): ?string => $line->reference_type === (new CashVoucher)->getMorphClass()
            ? "/cashbook/vouchers/{$line->reference_id}"
            : $url);
    }
}
