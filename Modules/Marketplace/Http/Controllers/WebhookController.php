<?php

namespace Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Tenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Marketplace\Drivers\MarketplaceManager;
use Modules\Marketplace\Jobs\SyncMarketplaceOrders;
use Modules\Marketplace\Models\MarketplaceShop;

/**
 * Push calls from a platform: POST /webhooks/marketplace/{token}.
 *
 * The token (random, per shop) only finds the shop; the call must also
 * carry the platform's signature made with the shop's partner key. Even
 * then the payload is not trusted: it only names the orders, which are
 * fetched again from the platform's API on the queue.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $token, MarketplaceManager $drivers): JsonResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40,64}$/', $token) === 1, 404);

        // The URL carries no store: find the shop by its token, then work inside its store only.
        /** @var MarketplaceShop|null $shop */
        $shop = MarketplaceShop::query()->withoutGlobalScope(TenantScope::class)->where('webhook_token', $token)->first();

        abort_if($shop === null || ! hash_equals((string) $shop->webhook_token, $token), 404);

        $tenant = Tenant::query()->find($shop->tenant_id);
        abort_if($tenant === null || ! $tenant->isActive(), 404);

        return tenancy()->run($tenant, function () use ($request, $shop, $drivers): JsonResponse {
            $orders = $drivers->has($shop->platform) ? $drivers->for($shop)->webhookOrders($request) : null;

            abort_if($orders === null, 401);

            if ($orders !== [] && $shop->isActive() && $shop->setting('sync_orders', true)) {
                SyncMarketplaceOrders::dispatch((int) $shop->tenant_id, $shop->id, array_slice($orders, 0, 50));
            }

            return response()->json(['ok' => true]);
        });
    }
}
