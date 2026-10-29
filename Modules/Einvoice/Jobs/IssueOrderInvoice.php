<?php

namespace Modules\Einvoice\Jobs;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Einvoice\Models\Einvoice;
use Modules\Einvoice\Services\EinvoiceService;
use Modules\Order\Models\Order;

/**
 * Auto-issue the invoice of a completed order. A refusal (buyer data,
 * provider error) leaves the invoice "Phát hành lỗi" in the list to fix
 * and issue by hand; it is not retried.
 */
class IssueOrderInvoice implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public int $tenantId, public int $orderId) {}

    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->orderId}";
    }

    public function handle(EinvoiceService $invoices): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null || ! $tenant->isActive()) {
            return;
        }

        tenancy()->run($tenant, function () use ($invoices): void {
            $order = Order::query()->find($this->orderId);

            if ($order === null || $order->status !== Order::COMPLETED) {
                return;
            }

            $invoice = $invoices->invoiceOf($order);

            try {
                $invoice ??= $invoices->fromOrder($order);

                if ($invoice->status === Einvoice::DRAFT) {
                    $invoices->issue($invoice);
                }
            } catch (ValidationException $exception) {
                $message = implode(' ', $exception->validator->errors()->all());

                // Shown in the list as "Phát hành lỗi" with the reason.
                if ($invoice !== null && $invoice->refresh()->status === Einvoice::DRAFT) {
                    $invoice->forceFill(['status' => Einvoice::FAILED, 'error' => mb_substr($message, 0, 500)])->save();
                }

                Log::warning("Không tự xuất được hóa đơn điện tử cho đơn {$order->code}: {$message}");
            }
        });
    }
}
