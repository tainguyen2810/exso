<?php

namespace Modules\Marketplace\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Services\InventoryService;
use Modules\Marketplace\Drivers\MarketplaceException;
use Modules\Marketplace\Drivers\MarketplaceManager;
use Modules\Marketplace\Jobs\PushMarketplaceStock;
use Modules\Marketplace\Models\MarketplaceListing;
use Modules\Marketplace\Models\MarketplaceShop;
use Modules\Product\Models\ProductVariant;

/**
 * Stock shown on the platforms = "Có thể bán" (stock − held for orders)
 * of the shop's stock branches, in the linked variant's unit (combos: what
 * their components allow). Changes are marked on the listings, then one
 * queued job per shop pushes them, so a busy day sends a few calls, not one
 * per sale.
 */
class MarketplaceStockService
{
    public function __construct(protected InventoryService $inventory, protected MarketplaceManager $drivers) {}

    /**
     * Stock of these base variants changed: mark every listing that sells
     * them (directly, as another unit, or inside a combo) and queue a push.
     *
     * @param  list<int>  $variantIds
     */
    public function changed(array $variantIds): void
    {
        $variantIds = array_values(array_unique($variantIds));

        if ($variantIds === [] || ! MarketplaceListing::query()->whereNotNull('variant_id')->exists()) {
            return;
        }

        $units = ProductVariant::query()->whereIn('base_variant_id', $variantIds)->pluck('id')->all();
        $comboProducts = DB::table('combo_items')->where('tenant_id', tenancy()->id())->whereIn('variant_id', [...$variantIds, ...$units])->pluck('product_id')->all();
        $combos = $comboProducts === [] ? [] : ProductVariant::query()->whereIn('product_id', $comboProducts)->pluck('id')->all();
        $ids = array_values(array_unique([...$variantIds, ...$units, ...$combos]));

        $shops = MarketplaceListing::query()->whereIn('variant_id', $ids)->distinct()->pluck('shop_id')->all();

        if ($shops === []) {
            return;
        }

        MarketplaceListing::query()->whereIn('variant_id', $ids)->update(['stock_dirty' => true]);

        foreach (MarketplaceShop::query()->whereKey($shops)->where('status', MarketplaceShop::ACTIVE)->get() as $shop) {
            if ($shop->setting('sync_stock', true)) {
                PushMarketplaceStock::dispatch((int) $shop->tenant_id, $shop->id);
            }
        }
    }

    /**
     * Mark every linked listing of the shop (hourly safety net, new links).
     */
    public function markAll(MarketplaceShop $shop): void
    {
        MarketplaceListing::query()->where('shop_id', $shop->id)->whereNotNull('variant_id')->update(['stock_dirty' => true]);
    }

    /**
     * Push the marked listings of a shop.
     *
     * @return array{pushed: int, failed: int}
     */
    public function push(MarketplaceShop $shop): array
    {
        if (! $shop->isActive() || ! $shop->setting('sync_stock', true)) {
            return ['pushed' => 0, 'failed' => 0];
        }

        $pushed = 0;
        $failed = 0;
        $branches = $shop->stockBranchIds();

        MarketplaceListing::query()->with('variant.product')
            ->where('shop_id', $shop->id)->where('stock_dirty', true)->whereNotNull('variant_id')
            ->chunkById(50, function ($listings) use ($shop, $branches, &$pushed, &$failed): void {
                $stocks = [];
                $quantities = [];

                foreach ($listings as $listing) {
                    /** @var MarketplaceListing $listing */
                    $quantity = $listing->variant === null ? 0 : max(0, $this->inventory->stockOf($listing->variant, $branches)['available']);
                    $quantities[$listing->id] = $quantity;
                    $stocks[] = ['item_id' => $listing->item_id, 'model_id' => $listing->model_id, 'stock' => $quantity];
                }

                try {
                    $errors = $this->drivers->for($shop)->updateStock($stocks);
                } catch (MarketplaceException $exception) {
                    $errors = array_fill_keys($listings->map(fn (MarketplaceListing $listing): string => "{$listing->item_id}:{$listing->model_id}")->all(), $exception->getMessage());
                }

                foreach ($listings as $listing) {
                    /** @var MarketplaceListing $listing */
                    $error = $errors["{$listing->item_id}:{$listing->model_id}"] ?? null;

                    if ($error === null) {
                        $listing->forceFill(['stock_dirty' => false, 'pushed_stock' => $quantities[$listing->id], 'stock' => $quantities[$listing->id], 'pushed_at' => now(), 'push_error' => null])->save();
                        $pushed++;
                    } else {
                        // Stays marked: the next run tries again.
                        $listing->forceFill(['push_error' => mb_substr($error, 0, 500)])->save();
                        $failed++;
                    }
                }
            });

        return ['pushed' => $pushed, 'failed' => $failed];
    }
}
