<?php

namespace Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\DataTable\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Marketplace\Drivers\MarketplaceManager;
use Modules\Marketplace\Drivers\ShopeeDriver;
use Modules\Marketplace\Jobs\PullMarketplaceOrders;
use Modules\Marketplace\Jobs\SyncMarketplaceOrders;
use Modules\Marketplace\Models\MarketplaceListing;
use Modules\Marketplace\Models\MarketplaceOrder;
use Modules\Marketplace\Models\MarketplaceShop;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sàn TMĐT → "Tổng quan" (today's figures, the connected shops with their
 * linked products and orders) and "Đơn hàng" (platform orders and the EXSO
 * order made from each). Limited to shops of the user's branches.
 */
class MarketplaceController extends Controller
{
    public function overview(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $shops = MarketplaceShop::query()->visibleTo($user)->with('branch:id,name')->orderBy('id')->get();
        $ids = $shops->modelKeys();
        $today = now()->startOfDay();

        $listings = MarketplaceListing::query()->whereIn('shop_id', $ids)
            ->selectRaw('shop_id, COUNT(*) as total, SUM(CASE WHEN variant_id IS NULL THEN 0 ELSE 1 END) as linked')
            ->groupBy('shop_id')->get()->keyBy('shop_id');
        $orders = MarketplaceOrder::query()->whereIn('shop_id', $ids)
            ->selectRaw('shop_id, COUNT(*) as total, SUM(CASE WHEN order_id IS NULL THEN 0 ELSE 1 END) as linked')
            ->groupBy('shop_id')->get()->keyBy('shop_id');
        $todayValue = MarketplaceOrder::query()->whereIn('shop_id', $ids)->where('placed_at', '>=', $today)->where('state', '!=', 'cancelled')
            ->selectRaw('shop_id, COUNT(*) as count, SUM(total) as value')->groupBy('shop_id')->get()->keyBy('shop_id');
        $states = MarketplaceOrder::query()->whereIn('shop_id', $ids)->where('placed_at', '>=', now()->subDays(30))
            ->select('state', DB::raw('COUNT(*) as count'))->groupBy('state')->pluck('count', 'state');

        return Inertia::render('marketplaces/overview', [
            'today' => [
                'value' => (int) $todayValue->sum('value'),
                'orders' => (int) $todayValue->sum('count'),
            ],
            'states' => [
                'to_ship' => (int) ($states['to_ship'] ?? 0),
                'shipped' => (int) ($states['shipped'] ?? 0),
                'completed' => (int) ($states['completed'] ?? 0),
                'cancelled' => (int) ($states['cancelled'] ?? 0),
                'errors' => MarketplaceOrder::query()->whereIn('shop_id', $ids)->where('sync_status', '!=', MarketplaceOrder::SYNCED)->count(),
                'unlinked' => MarketplaceListing::query()->whereIn('shop_id', $ids)->whereNull('variant_id')->count(),
            ],
            'shops' => $shops->map(fn (MarketplaceShop $shop): array => [
                ...ShopController::summary($shop),
                'listings' => (int) ($listings->get($shop->id)?->getAttribute('total') ?? 0),
                'listings_linked' => (int) ($listings->get($shop->id)?->getAttribute('linked') ?? 0),
                'orders' => (int) ($orders->get($shop->id)?->getAttribute('total') ?? 0),
                'orders_linked' => (int) ($orders->get($shop->id)?->getAttribute('linked') ?? 0),
                'today_value' => (int) ($todayValue->get($shop->id)?->getAttribute('value') ?? 0),
            ])->all(),
            'platforms' => ShopController::platforms(),
            'branches' => ShopController::branches($user),
        ]);
    }

    public function orders(Request $request, MarketplaceManager $drivers): Response|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $shops = MarketplaceShop::query()->visibleTo($user)->orderBy('id')->get(['id', 'name', 'platform']);

        $table = DataTable::for(MarketplaceOrder::query()->whereIn('shop_id', $shops->modelKeys())->with(['shop:id,name,platform', 'order:id,code,status'])->select('marketplace_orders.*'), $request, 'marketplace_orders')
            ->search(['external_id', 'buyer_username', 'recipient_name', 'tracking_code', 'order.code'])
            ->filterExact('state')
            ->filterExact('shop_id')
            ->filterExact('sync_status')
            ->filterDateRange('placed_at')
            ->sortable(['placed_at', 'total', 'synced_at'], '-placed_at');

        $row = fn (MarketplaceOrder $order): array => [
            'id' => $order->id,
            'external_id' => $order->external_id,
            'shop' => $order->shop->name,
            'platform' => $order->shop->platform,
            'platform_status' => $order->platform_status,
            'platform_status_label' => $order->shop->platform === 'shopee' && $order->platform_status !== null ? ShopeeDriver::statusLabel($order->platform_status) : $order->platform_status,
            'state' => $order->state,
            'sync_status' => $order->sync_status,
            'error' => $order->error,
            'order_id' => $order->order_id,
            'order_code' => $order->order?->code,
            'buyer' => $order->buyer_username ?? $order->recipient_name,
            'total' => $order->total,
            'carrier' => $order->carrier,
            'tracking_code' => $order->tracking_code,
            'placed_at' => $order->placed_at?->toIso8601String(),
            'synced_at' => $order->synced_at?->toIso8601String(),
        ];

        if ($request->query('export') === 'xlsx') {
            abort_unless($user->can('orders.export'), 403);

            return $table->export('don-san-'.now()->format('Ymd-His'), [
                'Mã đơn sàn' => fn (MarketplaceOrder $order) => $order->external_id,
                'Gian hàng' => fn (MarketplaceOrder $order) => $order->shop->name,
                'Ngày tạo' => fn (MarketplaceOrder $order) => $order->placed_at?->setTimezone((string) config('app.timezone')),
                'Trạng thái trên sàn' => fn (MarketplaceOrder $order) => MarketplaceOrder::STATES[$order->state] ?? $order->state,
                'Mã đơn EXSO' => fn (MarketplaceOrder $order) => $order->order?->code,
                'Người mua' => fn (MarketplaceOrder $order) => $order->buyer_username ?? $order->recipient_name,
                'Tổng tiền' => fn (MarketplaceOrder $order) => $order->total,
                'Vận chuyển' => fn (MarketplaceOrder $order) => $order->carrier,
                'Mã vận đơn' => fn (MarketplaceOrder $order) => $order->tracking_code,
                'Đồng bộ' => fn (MarketplaceOrder $order) => MarketplaceOrder::SYNC_STATUSES[$order->sync_status] ?? $order->sync_status,
                'Lỗi' => fn (MarketplaceOrder $order) => $order->error,
            ]);
        }

        return Inertia::render('marketplaces/orders', [
            'orders' => $table->paginate($row),
            'shops' => $shops->map(fn (MarketplaceShop $shop): array => ['id' => $shop->id, 'name' => $shop->name])->all(),
        ]);
    }

    /**
     * "Cập nhật đơn hàng": pull orders of the last 3 days now.
     */
    public function pull(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $shops = MarketplaceShop::query()->visibleTo($user)->where('status', MarketplaceShop::ACTIVE)
            ->when($request->integer('shop_id') > 0, fn (Builder $query) => $query->whereKey($request->integer('shop_id')))
            ->get();

        foreach ($shops as $shop) {
            PullMarketplaceOrders::dispatch((int) $shop->tenant_id, $shop->id, 72);
        }

        $this->toast($shops->isEmpty() ? 'Chưa có gian hàng nào đang kết nối.' : 'Đang cập nhật đơn hàng 3 ngày gần nhất từ sàn.', $shops->isEmpty() ? 'warning' : 'success');

        return back();
    }

    /**
     * "Đồng bộ lại" one order (after linking its products, or an error).
     */
    public function resync(Request $request, MarketplaceOrder $marketplaceOrder): RedirectResponse
    {
        $shop = $marketplaceOrder->shop;
        ShopController::authorizeShop($request, $shop);

        if (! $shop->isActive()) {
            $this->toast('Gian hàng không còn kết nối. Hãy cấp quyền lại.', 'error');

            return back();
        }

        SyncMarketplaceOrders::dispatch((int) $shop->tenant_id, $shop->id, [$marketplaceOrder->external_id]);
        $this->toast("Đang đồng bộ lại đơn {$marketplaceOrder->external_id}.");

        return back();
    }
}
