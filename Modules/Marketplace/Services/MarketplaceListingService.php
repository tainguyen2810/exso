<?php

namespace Modules\Marketplace\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Marketplace\Drivers\MarketplaceManager;
use Modules\Marketplace\Models\MarketplaceListing;
use Modules\Marketplace\Models\MarketplaceShop;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;

/**
 * Platform products of a shop ("Sản phẩm sàn") and their link to EXSO
 * variants ("Liên kết sản phẩm"). A linked listing gets its stock from
 * "Có thể bán" and its orders become EXSO lines.
 */
class MarketplaceListingService
{
    public function __construct(protected MarketplaceManager $drivers) {}

    /**
     * Read every listing of the shop ("Cập nhật dữ liệu sản phẩm"); new
     * ones are linked by SKU. Links already made are kept.
     *
     * @return array{total: int, linked: int}
     */
    public function fetch(MarketplaceShop $shop): array
    {
        $listings = $this->drivers->for($shop)->listings();
        $linked = 0;

        foreach ($listings as $item) {
            $listing = MarketplaceListing::query()->firstOrNew(['shop_id' => $shop->id, 'item_id' => $item->itemId, 'model_id' => $item->modelId]);
            $listing->fill([
                'name' => mb_substr($item->name, 0, 255),
                'model_name' => $item->modelName === null ? null : mb_substr($item->modelName, 0, 255),
                'sku' => $item->sku === null ? null : mb_substr($item->sku, 0, 100),
                'image_url' => $item->imageUrl === null ? null : mb_substr($item->imageUrl, 0, 500),
                'price' => $item->price,
                'stock' => $item->stock,
                'status' => $item->status,
            ])->save();

            if ($listing->variant_id === null && $this->autoLink($listing)) {
                $linked++;
            }
        }

        $shop->forceFill(['products_synced_at' => now()])->save();

        return ['total' => count($listings), 'linked' => $linked];
    }

    /**
     * Link to the EXSO variant with the same SKU, if exactly one has it.
     */
    public function autoLink(MarketplaceListing $listing): bool
    {
        $sku = trim((string) $listing->sku);

        if ($sku === '') {
            return false;
        }

        $variants = ProductVariant::query()->whereHas('product', fn ($query) => $query->where('type', '!=', Product::TYPE_SERVICE))
            ->where('sku', $sku)->limit(2)->get();

        if ($variants->count() !== 1) {
            return false;
        }

        $this->link($listing, $variants->first());

        return true;
    }

    /**
     * "Liên kết nhanh": link every unlinked listing of the shop by SKU.
     */
    public function autoLinkAll(MarketplaceShop $shop): int
    {
        $count = 0;

        MarketplaceListing::query()->where('shop_id', $shop->id)->whereNull('variant_id')->whereNotNull('sku')
            ->each(function (MarketplaceListing $listing) use (&$count): void {
                $count += $this->autoLink($listing) ? 1 : 0;
            });

        return $count;
    }

    public function link(MarketplaceListing $listing, ProductVariant $variant): void
    {
        $variant->loadMissing('product');

        if ($variant->trashed() || $variant->product->type === Product::TYPE_SERVICE) {
            throw ValidationException::withMessages(['variant_id' => 'Chỉ liên kết được sản phẩm còn kinh doanh và có quản lý tồn kho.']);
        }

        DB::transaction(function () use ($listing, $variant): void {
            $listing->forceFill(['variant_id' => $variant->id, 'linked_at' => now(), 'stock_dirty' => true, 'push_error' => null])->save();
        });
    }

    public function unlink(MarketplaceListing $listing): void
    {
        $listing->forceFill(['variant_id' => null, 'linked_at' => null, 'stock_dirty' => false])->save();
    }
}
