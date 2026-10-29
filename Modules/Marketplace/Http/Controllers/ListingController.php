<?php

namespace Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\DataTable\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Marketplace\Jobs\FetchMarketplaceListings;
use Modules\Marketplace\Jobs\PushMarketplaceStock;
use Modules\Marketplace\Jobs\SyncMarketplaceOrders;
use Modules\Marketplace\Models\MarketplaceListing;
use Modules\Marketplace\Models\MarketplaceOrder;
use Modules\Marketplace\Models\MarketplaceShop;
use Modules\Marketplace\Services\MarketplaceListingService;
use Modules\Marketplace\Services\MarketplaceStockService;
use Modules\Product\Models\ProductVariant;

/**
 * Sàn TMĐT → "Sản phẩm": platform products and their link to EXSO
 * variants, like Sapo's "Danh sách sản phẩm" (tabs Liên kết thành công /
 * Chưa liên kết), "Liên kết nhanh" by SKU and "Cập nhật dữ liệu sản phẩm".
 */
class ListingController extends Controller
{
    public function __construct(protected MarketplaceListingService $listings) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $shops = MarketplaceShop::query()->visibleTo($user)->orderBy('id')->get(['id', 'name', 'platform', 'branch_id', 'settings']);

        $table = DataTable::for(MarketplaceListing::query()->whereIn('shop_id', $shops->modelKeys())->with(['shop:id,name', 'variant:id,name,sku,product_id']), $request, 'marketplace_listings')
            ->search(['name', 'model_name', 'sku', 'item_id', 'variant.sku', 'variant.name'])
            ->filterExact('shop_id')
            ->filter('link', fn (Builder $query, mixed $value) => match ($value) {
                'linked' => $query->whereNotNull('variant_id'),
                'unlinked' => $query->whereNull('variant_id'),
                'push_failed' => $query->whereNotNull('push_error'),
                default => null,
            })
            ->sortable(['name', 'stock', 'price', 'pushed_at'], 'name');

        return Inertia::render('marketplaces/products', [
            'listings' => $table->paginate(fn (MarketplaceListing $listing): array => [
                'id' => $listing->id,
                'shop' => $listing->shop->name,
                'item_id' => $listing->item_id,
                'model_id' => $listing->model_id,
                'name' => $listing->name,
                'model_name' => $listing->model_name,
                'sku' => $listing->sku,
                'image_url' => $listing->image_url,
                'price' => $listing->price,
                'stock' => $listing->stock,
                'variant' => $listing->variant === null ? null : [
                    'id' => $listing->variant->id,
                    'name' => $listing->variant->name,
                    'sku' => $listing->variant->sku,
                    'product_id' => $listing->variant->product_id,
                ],
                'pushed_stock' => $listing->pushed_stock,
                'pushed_at' => $listing->pushed_at?->toIso8601String(),
                'push_error' => $listing->push_error,
                'stock_dirty' => $listing->stock_dirty,
            ]),
            'shops' => $shops->map(fn (MarketplaceShop $shop): array => ['id' => $shop->id, 'name' => $shop->name])->all(),
        ]);
    }

    /**
     * "Cập nhật dữ liệu sản phẩm": read the products of the shops again.
     */
    public function fetch(Request $request): RedirectResponse
    {
        $shops = $this->activeShops($request);

        foreach ($shops as $shop) {
            FetchMarketplaceListings::dispatch((int) $shop->tenant_id, $shop->id);
        }

        $this->toast($shops->isEmpty() ? 'Chưa có gian hàng nào đang kết nối.' : 'Đang tải sản phẩm từ sàn, tải lại trang sau ít phút.', $shops->isEmpty() ? 'warning' : 'success');

        return back();
    }

    /**
     * "Liên kết nhanh": link products by SKU.
     */
    public function autoLink(Request $request, MarketplaceStockService $stock): RedirectResponse
    {
        $count = 0;

        foreach ($this->activeShops($request) as $shop) {
            $linked = $this->listings->autoLinkAll($shop);
            $count += $linked;

            if ($linked > 0) {
                $this->afterLinking($shop);
            }
        }

        $this->toast($count > 0 ? "Đã liên kết {$count} sản phẩm theo mã SKU." : 'Không có sản phẩm nào trùng mã SKU để liên kết.', $count > 0 ? 'success' : 'warning');

        return back();
    }

    public function link(Request $request, MarketplaceListing $listing): RedirectResponse
    {
        ShopController::authorizeShop($request, $listing->shop);
        $data = $request->validate([
            'variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->where('tenant_id', tenancy()->id())->whereNull('deleted_at')],
        ]);

        $this->listings->link($listing, ProductVariant::query()->with('product')->findOrFail((int) $data['variant_id']));
        $this->afterLinking($listing->shop);
        $this->toast("Đã liên kết {$listing->fullName()}.");

        return back();
    }

    public function unlink(Request $request, MarketplaceListing $listing): RedirectResponse
    {
        ShopController::authorizeShop($request, $listing->shop);
        $this->listings->unlink($listing);
        $this->toast("Đã hủy liên kết {$listing->fullName()}.");

        return back();
    }

    /**
     * "Đồng bộ tồn kho": push the stock of every linked product now.
     */
    public function pushStock(Request $request, MarketplaceStockService $stock): RedirectResponse
    {
        $shops = $this->activeShops($request);

        foreach ($shops as $shop) {
            $stock->markAll($shop);
            PushMarketplaceStock::dispatch((int) $shop->tenant_id, $shop->id);
        }

        $this->toast('Đang đẩy tồn kho "Có thể bán" lên sàn.');

        return back();
    }

    /**
     * A new link: push its stock and bring in orders that waited for it.
     */
    protected function afterLinking(MarketplaceShop $shop): void
    {
        if (! $shop->isActive()) {
            return;
        }

        PushMarketplaceStock::dispatch((int) $shop->tenant_id, $shop->id);
        $waiting = MarketplaceOrder::query()->where('shop_id', $shop->id)->where('sync_status', MarketplaceOrder::UNLINKED)->limit(50)->get(['external_id'])
            ->map(fn (MarketplaceOrder $order): string => $order->external_id)->all();

        if ($waiting !== []) {
            SyncMarketplaceOrders::dispatch((int) $shop->tenant_id, $shop->id, array_values($waiting));
        }
    }

    /**
     * @return Collection<int, MarketplaceShop>
     */
    protected function activeShops(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        return MarketplaceShop::query()->visibleTo($user)->where('status', MarketplaceShop::ACTIVE)
            ->when($request->integer('shop_id') > 0, fn (Builder $query) => $query->whereKey($request->integer('shop_id')))
            ->get();
    }
}
