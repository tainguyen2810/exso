<?php

namespace Modules\Shipping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Tenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Shipping\Carriers\CarrierManager;
use Modules\Shipping\Jobs\SyncShipment;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\Shipment;

/**
 * Status calls from a carrier: POST /webhooks/shipping/{token}.
 *
 * The token is a random secret per connection (shown on "Kết nối đối tác"
 * to paste into the carrier's settings). Carriers such as GHN do not sign
 * their calls, so the payload is never trusted: it only names the parcel,
 * and the status is fetched again from the carrier's API on the queue.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $token, CarrierManager $carriers): JsonResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40,64}$/', $token) === 1, 404);

        // The URL carries no store: find the connection by its token, then
        // work inside its store only.
        /** @var Carrier|null $carrier */
        $carrier = Carrier::query()->withoutGlobalScope(TenantScope::class)
            ->where('webhook_token', $token)
            ->where('kind', Carrier::INTEGRATED)
            ->where('is_active', true)
            ->first();

        abort_if($carrier === null || ! hash_equals((string) $carrier->webhook_token, $token), 404);

        $tenant = Tenant::query()->find($carrier->tenant_id);
        abort_if($tenant === null || ! $tenant->isActive(), 404);

        return tenancy()->run($tenant, function () use ($request, $carrier, $carriers): JsonResponse {
            $payload = $request->json()->all();
            $code = $carriers->for($carrier)->webhookTrackingCode($payload);

            $shipment = $code === null ? null : Shipment::query()
                ->where('carrier_id', $carrier->id)
                ->where('tracking_code', $code)
                ->first();

            if ($shipment !== null) {
                SyncShipment::dispatch((int) $carrier->tenant_id, $shipment->id);
            }

            // 200 either way, so the carrier does not keep retrying unknown parcels.
            return response()->json(['ok' => true]);
        });
    }
}
