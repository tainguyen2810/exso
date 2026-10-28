<?php

namespace Modules\Inventory\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        parent::__construct("Không đủ tồn kho cho {$variant->name} ({$variant->sku}): còn {$onHand}, cần {$requested}.");
    }

    /**
     * Shown on the page that was submitted, like a validation error.
     */
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $this->getMessage(), 'errors' => ['stock' => [$this->getMessage()]]], 422);
        }

        return back()->withErrors(['stock' => $this->getMessage()]);
    }
}
