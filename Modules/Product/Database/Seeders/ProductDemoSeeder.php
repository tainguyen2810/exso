<?php

namespace Modules\Product\Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;
use Modules\Product\Models\Brand;
use Modules\Product\Models\Category;
use Modules\Product\Models\PriceList;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Services\ProductService;

/**
 * Demo catalog for the sample store (run inside the store's tenant).
 */
class ProductDemoSeeder extends Seeder
{
    public function run(ProductService $products): void
    {
        $main = Branch::query()->where('is_default', true)->value('id');
        $category = fn (string $name): int => Category::query()->firstOrCreate(['name' => $name])->id;
        $brand = fn (string $name): int => Brand::query()->firstOrCreate(['name' => $name])->id;
        $dealer = PriceList::query()->firstOrCreate(['code' => 'DAILY'], ['name' => 'Giá đại lý']);

        $simple = [
            ['Chuột không dây Logitech M331', 'LOGI-M331', '097855141483', 'Phụ kiện', 'Logitech', 'Cái', 390000, 340000, 280000, 25],
            ['Bàn phím cơ Keychron K2', 'KEY-K2', '766217300112', 'Phụ kiện', 'Keychron', 'Cái', 2190000, 1990000, 1650000, 8],
            ['Tai nghe Jabra Evolve2 30', 'JAB-E230', '5706991023423', 'Phụ kiện', 'Jabra', 'Cái', 1850000, 1700000, 1400000, 3],
            ['Cáp HDMI 2.0 Ugreen 2m', 'UG-HDMI2', '6957303840025', 'Cáp', 'Ugreen', 'Sợi', 159000, 130000, 90000, 60],
            ['Ổ cứng SSD Samsung 980 500GB', 'SS-980-500', '8801643646549', 'Linh kiện', 'Samsung', 'Cái', 1450000, 1350000, 1180000, 0],
        ];

        $created = [];

        foreach ($simple as [$name, $sku, $barcode, $cat, $br, $unit, $retail, $wholesale, $import, $stock]) {
            $created[$sku] = $products->save(null, [
                'type' => 'normal',
                'name' => $name,
                'category_id' => $category($cat),
                'brand_id' => $brand($br),
                'unit' => $unit,
                'variants' => [[
                    'sku' => $sku, 'barcode' => $barcode, 'retail_price' => $retail, 'wholesale_price' => $wholesale,
                    'import_price' => $import, 'prices' => [$dealer->id => $wholesale - 30000],
                    'opening_quantity' => $stock, 'opening_cost' => $import,
                ]],
                'opening_branch_id' => $main,
            ]);
        }

        $products->save(null, [
            'type' => 'normal',
            'name' => 'Áo thun ITSO',
            'category_id' => $category('Đồng phục'),
            'brand_id' => $brand('ITSO'),
            'unit' => 'Cái',
            'options' => ['Màu', 'Size'],
            'variants' => [
                ['options' => ['Đen', 'M'], 'sku' => 'AT-DEN-M', 'retail_price' => 180000, 'import_price' => 90000, 'opening_quantity' => 12],
                ['options' => ['Đen', 'L'], 'sku' => 'AT-DEN-L', 'retail_price' => 180000, 'import_price' => 90000, 'opening_quantity' => 9],
                ['options' => ['Trắng', 'M'], 'sku' => 'AT-TRANG-M', 'retail_price' => 180000, 'import_price' => 90000, 'opening_quantity' => 4],
                ['options' => ['Trắng', 'L'], 'sku' => 'AT-TRANG-L', 'retail_price' => 180000, 'import_price' => 90000, 'opening_quantity' => 0],
            ],
            'opening_branch_id' => $main,
        ]);

        $products->save(null, [
            'type' => 'normal',
            'name' => 'Giấy in A4 Double A 70gsm',
            'category_id' => $category('Văn phòng phẩm'),
            'brand_id' => $brand('Double A'),
            'unit' => 'Ram',
            'variants' => [['sku' => 'DA-A4-70', 'barcode' => '8852002000017', 'retail_price' => 85000, 'wholesale_price' => 78000, 'import_price' => 65000, 'opening_quantity' => 50]],
            'units' => [['unit' => 'Thùng', 'ratio' => 5, 'sku' => 'DA-A4-70-T', 'retail_price' => 400000, 'wholesale_price' => 380000]],
            'opening_branch_id' => $main,
        ]);

        foreach ([
            ['Microsoft 365 Business Standard (1 năm)', 'M365-BS', 3200000, 2700000],
            ['Windows 11 Pro bản quyền', 'WIN11-PRO', 4500000, 3900000],
            ['Dịch vụ cài đặt máy tính tại chỗ', 'DV-CAIDAT', 300000, 0],
        ] as [$name, $sku, $retail, $import]) {
            $products->save(null, [
                'type' => 'service',
                'name' => $name,
                'category_id' => $category(str_starts_with($sku, 'DV') ? 'Dịch vụ' : 'License'),
                'brand_id' => str_starts_with($sku, 'DV') ? null : $brand('Microsoft'),
                'unit' => str_starts_with($sku, 'DV') ? 'Lần' : 'Gói',
                'variants' => [['sku' => $sku, 'retail_price' => $retail, 'import_price' => $import]],
            ]);
        }

        $mouse = ProductVariant::query()->where('sku', 'LOGI-M331')->value('id');
        $keyboard = ProductVariant::query()->where('sku', 'KEY-K2')->value('id');

        $products->save(null, [
            'type' => 'combo',
            'name' => 'Combo bàn phím + chuột văn phòng',
            'category_id' => $category('Phụ kiện'),
            'variants' => [['sku' => 'CB-VP-01', 'retail_price' => 2450000]],
            'combo_items' => [['variant_id' => (int) $keyboard, 'quantity' => 1], ['variant_id' => (int) $mouse, 'quantity' => 1]],
        ]);
    }
}
