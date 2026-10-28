<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Product\Models\ProductVariant;

/**
 * "In tem mã vạch": pick variants and how many labels, print from the browser.
 */
class BarcodeController extends Controller
{
    public function index(Request $request): Response
    {
        $productIds = array_map('intval', array_filter(explode(',', (string) $request->query('products', ''))));
        $variantIds = array_map('intval', array_filter(explode(',', (string) $request->query('variants', ''))));

        $variants = $productIds === [] && $variantIds === []
            ? collect()
            : ProductVariant::query()
                ->where(fn ($query) => $query->whereIn('product_id', $productIds)->orWhereIn('id', $variantIds))
                ->orderBy('product_id')
                ->orderBy('id')
                ->limit(200)
                ->get();

        return Inertia::render('products/barcodes', [
            'initial' => $variants->map(fn (ProductVariant $variant): array => self::label($variant))->values(),
            'storeName' => (string) settings('store.name', config('app.name')),
        ]);
    }

    /**
     * @return array{id: int, name: string, sku: string, barcode: string|null, retail_price: int, unit: string|null}
     */
    public static function label(ProductVariant $variant): array
    {
        return [
            'id' => $variant->id,
            'name' => $variant->name,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'retail_price' => $variant->retail_price,
            'unit' => $variant->unit,
        ];
    }
}
