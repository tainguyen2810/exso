<?php

namespace Modules\Einvoice\Providers;

use App\Models\User;
use App\Support\Hooks\Hook;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Einvoice\Drivers\EinvoiceManager;
use Modules\Einvoice\Jobs\IssueOrderInvoice;
use Modules\Einvoice\Models\Einvoice;
use Modules\Order\Events\OrderStatusChanged;
use Modules\Order\Models\Order;

class EinvoiceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/config.php', 'einvoice');
        $this->app->singleton(EinvoiceManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'verified'])->group(__DIR__.'/../routes/web.php');
        }

        // "Tự động xuất hóa đơn khi đơn hoàn thành" (Cấu hình → Hóa đơn điện tử).
        Event::listen(OrderStatusChanged::class, function (OrderStatusChanged $event): void {
            if ($event->order->status === Order::COMPLETED && settings('einvoice.auto_issue') && app(EinvoiceManager::class)->active() !== null) {
                IssueOrderInvoice::dispatch((int) $event->order->tenant_id, $event->order->id);
            }
        });

        // An issued invoice fixes the prices: the order is no longer edited or cancelled.
        Hook::addFilter('order.locked', function (?string $reason, Order $order): ?string {
            if ($reason !== null) {
                return $reason;
            }

            $invoice = Einvoice::query()->where('order_id', $order->id)->where('kind', Einvoice::ORIGINAL)
                ->where('status', Einvoice::ISSUED)->first(['id', 'invoice_no', 'series']);

            return $invoice === null ? null
                : "Đơn đã xuất hóa đơn điện tử số {$invoice->invoice_no}. Muốn đổi giá hay hủy đơn thì lập hóa đơn điều chỉnh hoặc hủy hóa đơn trước.";
        });

        // "Hóa đơn điện tử" card on the order page.
        Hook::addFilter('order.panels', function (array $panels, Order $order, ?User $user): array {
            if ($user === null || ! $user->can('einvoices.view')) {
                return $panels;
            }

            $invoice = Einvoice::query()->where('order_id', $order->id)->where('kind', Einvoice::ORIGINAL)
                ->where('status', '!=', Einvoice::CANCELLED)->latest('id')->first();

            if ($invoice === null) {
                if (! $order->isExported() || $order->status === Order::CANCELLED || ! $user->can('einvoices.create')) {
                    return $panels;
                }

                return [...$panels, [
                    'title' => 'Hóa đơn điện tử',
                    'lines' => [['label' => 'Trạng thái', 'value' => 'Chưa tạo hóa đơn']],
                    'actions' => [['label' => 'Tạo hóa đơn điện tử', 'href' => "/e-invoices/orders/{$order->id}", 'method' => 'post']],
                ]];
            }

            return [...$panels, [
                'title' => 'Hóa đơn điện tử',
                'href' => "/e-invoices/{$invoice->id}",
                'lines' => [
                    ['label' => 'Mã hóa đơn', 'value' => $invoice->code, 'href' => "/e-invoices/{$invoice->id}"],
                    ['label' => 'Trạng thái', 'value' => Einvoice::STATUSES[$invoice->status]],
                    ...($invoice->invoice_no !== null ? [['label' => 'Số hóa đơn', 'value' => $invoice->invoice_no]] : []),
                    ...($invoice->lookup_code !== null ? [['label' => 'Mã tra cứu', 'value' => $invoice->lookup_code]] : []),
                ],
            ]];
        });
    }
}
