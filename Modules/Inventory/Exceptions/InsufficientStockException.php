<?php

namespace Modules\Inventory\Exceptions;

use Modules\Product\Models\ProductVariant;
use RuntimeException;

/**
 * Taking more than is in stock for a product that does not allow selling
 * below zero.
 */
class InsufficientStockException extends RuntimeException
{
    public function __construct(public readonly ProductVariant $variant, public readonly int $onHand, public readonly int $requested)
    {
        parent::__construct("Không đủ tồn kho cho {$variant->sku}: còn {$onHand}, cần {$requested}.");
    }
}
