<?php

use App\Models\Branch;
use App\Support\Excel\ExcelExport;
use Illuminate\Http\UploadedFile;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\StockMovement;
use Modules\Product\Models\PriceList;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;

/**
 * @param  list<string>  $headings
 * @param  list<list<mixed>>  $rows
 */
function productFile(array $headings, array $rows): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
    ExcelExport::store($path, $headings, $rows);

    return new UploadedFile($path, 'san-pham.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/** Columns the way a Sapo product export names them. */
const SAPO_HEADINGS = [
    'Tên sản phẩm*', 'Mã SKU*', 'Barcode', 'Loại sản phẩm', 'Nhãn hiệu', 'Mô tả sản phẩm', 'Tags',
    'Thuộc tính 1', 'Giá trị thuộc tính 1', 'Thuộc tính 2', 'Giá trị thuộc tính 2',
    'Đơn vị', 'Khối lượng', 'Đơn vị khối lượng', 'Ảnh đại diện',
    'PL_Giá bán lẻ', 'PL_Giá bán buôn', 'PL_Giá nhập', 'PL_Giá đại lý',
    'LC_Chi nhánh chính_Tồn kho ban đầu', 'LC_Chi nhánh chính_Giá vốn khởi tạo', 'LC_Chi nhánh chính_Điểm lưu kho',
    'LC_Kho Quận 3_Tồn kho ban đầu', 'LC_Kho Quận 3_Giá vốn khởi tạo',
    'Cho phép bán', 'Áp dụng thuế',
];

test('a Sapo product export imports without editing', function () {
    $owner = owner();
    $second = Branch::factory()->create(['code' => 'Q3', 'name' => 'Kho Quận 3']);

    $this->actingAs($owner)->post('/products/import', ['file' => productFile(SAPO_HEADINGS, [
        ['Áo thun basic', 'AT-DO-M', '8930000000011', 'Áo', 'ITSO', 'Áo cotton', 'ao,cotton', 'Màu', 'Đỏ', 'Size', 'M', 'Cái', 0.2, 'kg', 'https://img.example.com/a.jpg', '150.000', '120,000', 80000, 110000, 10, 80000, 'A1', 2, 80000, 'Có', 'Không'],
        ['Áo thun basic', 'AT-DO-L', '8930000000028', 'Áo', 'ITSO', 'Áo cotton', 'ao,cotton', 'Màu', 'Đỏ', 'Size', 'L', 'Cái', 0.2, 'kg', null, 150000, 120000, 80000, null, 5, 80000, null, 0, null, 'Có', 'Không'],
        ['Office 365 E3', 'O365-E3', null, 'License', 'Microsoft', null, null, null, null, null, null, 'Gói', null, null, null, '3.200.000 đ', null, 2700000, null, -1, null, null, null, null, 'Có', 'Có'],
    ])])->assertRedirect('/products')->assertSessionHasNoErrors();

    expect(Product::query()->count())->toBe(2);

    $shirt = Product::query()->where('name', 'Áo thun basic')->with('variants', 'category', 'brand')->sole();
    expect($shirt->variants)->toHaveCount(2)
        ->and($shirt->option1)->toBe('Màu')
        ->and($shirt->category->name)->toBe('Áo')
        ->and($shirt->image_url)->toBe('https://img.example.com/a.jpg');

    $m = ProductVariant::query()->where('sku', 'AT-DO-M')->sole();
    expect($m->retail_price)->toBe(150000)
        ->and($m->wholesale_price)->toBe(120000)
        ->and($m->import_price)->toBe(80000)
        ->and($m->cost_price)->toBe(80000)
        ->and($m->weight)->toBe(200)
        ->and($m->option2)->toBe('M')
        ->and($m->prices()->value('price'))->toBe(110000)
        ->and(PriceList::query()->sole()->name)->toBe('Giá đại lý');

    $main = mainBranch();
    expect(Inventory::query()->where('variant_id', $m->id)->where('branch_id', $main->id)->value('on_hand'))->toBe(10)
        ->and(Inventory::query()->where('variant_id', $m->id)->where('branch_id', $second->id)->value('on_hand'))->toBe(2);

    $office = ProductVariant::query()->where('sku', 'O365-E3')->sole();
    expect($office->retail_price)->toBe(3200000)
        ->and($office->product->allow_negative_stock)->toBeFalse()
        ->and(Inventory::query()->where('variant_id', $office->id)->value('on_hand'))->toBe(-1)
        ->and(StockMovement::query()->sum('quantity'))->toEqual(10 + 5 + 2 - 1);
});

test('importing again updates prices but leaves stock alone', function () {
    $owner = owner();
    $file = fn (int $price) => productFile(['Tên sản phẩm', 'Mã SKU', 'Giá bán lẻ', 'Tồn kho ban đầu'], [['Chuột', 'CH-01', $price, 10]]);

    $this->actingAs($owner)->post('/products/import', ['file' => $file(100000)])->assertSessionHasNoErrors();
    $this->actingAs($owner)->post('/products/import', ['file' => $file(120000)])->assertSessionHasNoErrors();

    $variant = ProductVariant::query()->sole();
    expect($variant->retail_price)->toBe(120000)
        ->and(Inventory::query()->sole()->on_hand)->toBe(10)
        ->and(StockMovement::query()->count())->toBe(1);
});

test('our own export imports back', function () {
    $owner = owner();
    Branch::factory()->create(['code' => 'Q3', 'name' => 'Kho Quận 3']);
    $this->actingAs($owner)->post('/products/import', ['file' => productFile(SAPO_HEADINGS, [
        ['Áo thun basic', 'AT-DO-M', null, 'Áo', null, null, null, 'Màu', 'Đỏ', null, null, 'Cái', null, null, null, 150000, null, null, null, 3, 10000, null, null, null, 'Có', null],
    ])])->assertSessionHasNoErrors();

    $response = $this->actingAs($owner)->get('/products?export=xlsx');
    $response->assertOk()->assertDownload();

    $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
    file_put_contents($path, $response->streamedContent());
    ProductVariant::query()->update(['retail_price' => 1]);

    $this->actingAs($owner)->post('/products/import', [
        'file' => new UploadedFile($path, 'export.xlsx', null, null, true),
    ])->assertSessionHasNoErrors();

    expect(ProductVariant::query()->sole()->retail_price)->toBe(150000)
        ->and(Product::query()->count())->toBe(1);
});

test('nothing is imported when a row is wrong, and errors name the lines', function () {
    $this->actingAs(owner())->post('/products/import', ['file' => productFile(
        ['Tên sản phẩm', 'Mã SKU', 'Barcode', 'Giá bán lẻ'],
        [
            ['Chuột', 'CH-01', '111', 100000],
            [null, 'CH-02', null, 100000],
            ['Bàn phím', 'CH-01', null, 'abc'],
            ['Tai nghe', 'TN-01', '111', 1],
        ],
    )])->assertSessionHasErrors(['file', 'rows']);

    expect(Product::query()->count())->toBe(0);
    expect(session('errors')->first('rows'))
        ->toContain('Dòng 3')
        ->toContain('Dòng 4')
        ->toContain('Dòng 5');
});

test('an unknown branch column is reported', function () {
    $this->actingAs(owner())->post('/products/import', ['file' => productFile(
        ['Tên sản phẩm', 'LC_Kho Bình Dương_Tồn kho ban đầu'],
        [['Chuột', 5]],
    )])->assertSessionHasErrors('rows');

    expect(session('errors')->first('rows'))->toContain('Kho Bình Dương');
});

test('the import template downloads', function () {
    $this->actingAs(owner())->get('/products/import/template')->assertOk()->assertDownload('mau-nhap-san-pham.xlsx');
});
