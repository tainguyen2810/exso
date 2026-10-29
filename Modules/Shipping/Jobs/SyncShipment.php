<?php

namespace Modules\Shipping\Jobs;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Shipping\Models\Shipment;
use Modules\Shipping\Services\ShipmentService;

/**
 * Fetch the status of one shipment from its carrier (after a webhook call)
 * and follow it. Several calls for the same parcel are merged.
 */
class SyncShipment implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public int $tenantId, public int $shipmentId) {}

    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->shipmentId}";
    }

    public function handle(ShipmentService $shipments): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        tenancy()->run($tenant, function () use ($shipments): void {
            $shipment = Shipment::query()->find($this->shipmentId);

            if ($shipment === null) {
                return;
            }

            try {
                $shipments->sync($shipment);
            } catch (ValidationException $exception) {
                Log::warning('Không đồng bộ được vận đơn', ['shipment' => $shipment->code, 'errors' => $exception->errors()]);
            }
        });
    }
}
