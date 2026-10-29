<?php

namespace Modules\Shipping\Console;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\Shipment;
use Modules\Shipping\Services\ShipmentService;

/**
 * Ask the integrated carriers about open shipments. Runs every 30 minutes,
 * so statuses follow even when the carrier cannot reach the webhook (a
 * store on a local network, a missed call).
 */
class SyncShipmentsCommand extends Command
{
    protected $signature = 'shipping:sync {--limit=200 : Số vận đơn tối đa mỗi cửa hàng}';

    protected $description = 'Cập nhật trạng thái vận đơn đang giao từ đối tác vận chuyển';

    public function handle(ShipmentService $shipments): int
    {
        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            [$done, $failed] = tenancy()->run($tenant, function () use ($shipments): array {
                $done = 0;
                $failed = 0;

                Shipment::query()
                    ->with('carrier')
                    ->whereIn('status', Shipment::ACTIVE)
                    ->whereNotNull('tracking_code')
                    ->whereHas('carrier', fn (Builder $query) => $query->where('kind', Carrier::INTEGRATED)->where('is_active', true))
                    ->where(fn (Builder $query) => $query->whereNull('synced_at')->orWhere('synced_at', '<', now()->subMinutes(20)))
                    ->orderBy('synced_at')
                    ->limit(max(1, (int) $this->option('limit')))
                    ->get()
                    ->each(function (Shipment $shipment) use ($shipments, &$done, &$failed): void {
                        try {
                            $shipments->sync($shipment);
                            $done++;
                        } catch (ValidationException) {
                            $shipment->forceFill(['synced_at' => now()])->save();
                            $failed++;
                        }
                    });

                return [$done, $failed];
            });

            $this->info("{$tenant->name}: cập nhật {$done} vận đơn".($failed > 0 ? ", lỗi {$failed}" : '').'.');
        }

        return self::SUCCESS;
    }
}
