<?php

namespace Modules\Marketplace\Drivers;

/**
 * One sellable row of a shop: an item, or one variation of it.
 */
final class PlatformListing
{
    public function __construct(
        public readonly string $itemId,
        public readonly string $modelId,
        public readonly string $name,
        public readonly ?string $modelName,
        public readonly ?string $sku,
        public readonly int $price,
        public readonly int $stock,
        public readonly ?string $imageUrl = null,
        public readonly ?string $status = null,
    ) {}
}
