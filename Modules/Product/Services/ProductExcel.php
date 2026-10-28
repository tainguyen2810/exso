<?php

namespace Modules\Product\Services;

use App\Models\Branch;
use App\Support\Excel\ExcelExport;
use App\Support\Excel\ExcelImport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\InventoryService;
use Modules\Product\Models\Brand;
use Modules\Product\Models\Category;
use Modules\Product\Models\PriceList;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Product Excel files, one row per variant like Sapo's export:
 * product columns repeat on every variant row, rows with the same product
 * name (or "Đường dẫn/alias") form one product.
 *
 * Headings are matched loosely and accept Sapo's names, including the
 * price list columns "PL_<tên>" and branch columns "LC_<chi nhánh>_Tồn kho ban đầu".
 *
 * @phpstan-import-type ProductData from ProductService
 */
class ProductExcel
{
    /** @var array<string, string> loose heading key => field */
    public const ALIASES = [
        'ten_san_pham' => 'name',
        'ten_hang' => 'name',
        'ten_hang_hoa' => 'name',
        'duong_dan_alias' => 'alias',
        'duong_dan' => 'alias',
        'alias' => 'alias',
        'ma_sku' => 'sku',
        'sku' => 'sku',
        'ma_phien_ban' => 'sku',
        'ma_hang' => 'sku',
        'ma_vach' => 'barcode',
        'barcode' => 'barcode',
        'ma_vach_barcode' => 'barcode',
        'loai_hinh' => 'type',
        'loai_hinh_san_pham' => 'type',
        'kieu_san_pham' => 'type',
        'quan_ly_ton_kho' => 'track_stock',
        'loai_san_pham' => 'category',
        'danh_muc' => 'category',
        'nhom_hang' => 'category',
        'nhan_hieu' => 'brand',
        'thuong_hieu' => 'brand',
        'mo_ta' => 'description',
        'mo_ta_san_pham' => 'description',
        'tags' => 'tags',
        'tag' => 'tags',
        'don_vi' => 'unit',
        'don_vi_tinh' => 'unit',
        'khoi_luong' => 'weight',
        'don_vi_khoi_luong' => 'weight_unit',
        'thuoc_tinh_1' => 'option1',
        'gia_tri_thuoc_tinh_1' => 'value1',
        'thuoc_tinh_2' => 'option2',
        'gia_tri_thuoc_tinh_2' => 'value2',
        'thuoc_tinh_3' => 'option3',
        'gia_tri_thuoc_tinh_3' => 'value3',
        'gia_ban_le' => 'retail_price',
        'pl_gia_ban_le' => 'retail_price',
        'gia_ban' => 'retail_price',
        'gia_ban_buon' => 'wholesale_price',
        'pl_gia_ban_buon' => 'wholesale_price',
        'gia_si' => 'wholesale_price',
        'gia_nhap' => 'import_price',
        'pl_gia_nhap' => 'import_price',
        'gia_von' => 'cost',
        'gia_von_khoi_tao' => 'cost',
        'ton_kho_ban_dau' => 'quantity',
        'ton_kho' => 'quantity',
        'cho_phep_ban' => 'is_sellable',
        'cho_ban_am' => 'allow_negative',
        'cho_phep_ban_am' => 'allow_negative',
        'anh_dai_dien' => 'image_url',
        'anh_san_pham' => 'image_url',
        'hinh_anh' => 'image_url',
        'link_anh' => 'image_url',
    ];

    public function __construct(protected ProductService $products, protected InventoryService $inventory) {}

    /**
     * @return list<string>
     */
    public static function headings(): array
    {
        return [
            'Tên sản phẩm*', 'Mã SKU', 'Mã vạch', 'Loại hình', 'Loại sản phẩm', 'Nhãn hiệu', 'Mô tả', 'Tags',
            'Đơn vị', 'Khối lượng', 'Thuộc tính 1', 'Giá trị thuộc tính 1', 'Thuộc tính 2', 'Giá trị thuộc tính 2',
            'Thuộc tính 3', 'Giá trị thuộc tính 3', 'Giá bán lẻ', 'Giá bán buôn', 'Giá nhập', 'Cho phép bán', 'Cho bán âm', 'Ảnh đại diện',
        ];
    }

    /**
     * All variants of the filtered products, plus custom prices and stock per branch.
     *
     * @param  Builder<Product>  $query
     */
    public static function export(Builder $query): StreamedResponse
    {
        $priceLists = PriceList::query()->orderBy('name')->get();
        $branches = Branch::query()->orderByDesc('is_default')->orderBy('name')->get();

        $headings = [...self::headings(), 'Giá vốn'];

        foreach ($priceLists as $list) {
            $headings[] = "PL_{$list->name}";
        }

        foreach ($branches as $branch) {
            $headings[] = "LC_{$branch->name}_Tồn kho";
        }

        return ExcelExport::download('san-pham-'.now()->format('Ymd-His'), $headings, function () use ($query, $priceLists, $branches): iterable {
            $query = (clone $query)->with(['category', 'brand', 'variants' => fn ($variants) => $variants->whereNull('base_variant_id')->with('prices')]);

            foreach ($query->lazy(200) as $product) {
                $stock = Inventory::query()->whereIn('variant_id', $product->variants->pluck('id'))->get()->groupBy('variant_id');

                foreach ($product->variants as $variant) {
                    $row = [
                        $product->name, $variant->sku, $variant->barcode, Product::TYPES[$product->type] ?? $product->type,
                        $product->category?->name, $product->brand?->name, $product->description, $product->tags,
                        $product->unit, $variant->weight ?: null,
                        $product->option1, $variant->option1, $product->option2, $variant->option2, $product->option3, $variant->option3,
                        $variant->retail_price, $variant->wholesale_price, $variant->import_price,
                        $product->is_sellable ? 'Có' : 'Không', $product->allow_negative_stock ? 'Có' : 'Không', $product->image_url,
                        $variant->cost_price,
                    ];

                    foreach ($priceLists as $list) {
                        $row[] = $variant->prices->firstWhere('price_list_id', $list->id)?->price;
                    }

                    foreach ($branches as $branch) {
                        $row[] = $product->tracksInventory() ? (int) $stock->get($variant->id)?->firstWhere('branch_id', $branch->id)?->on_hand : null;
                    }

                    yield $row;
                }
            }
        });
    }

    public static function template(): StreamedResponse
    {
        $branches = Branch::query()->orderByDesc('is_default')->orderBy('name')->get();
        $headings = self::headings();

        foreach ($branches as $branch) {
            $headings[] = "LC_{$branch->name}_Tồn kho ban đầu";
            $headings[] = "LC_{$branch->name}_Giá vốn khởi tạo";
        }

        $stock = fn (int $quantity, int $cost): array => $branches->isEmpty() ? [] : [$quantity, $cost];

        return ExcelExport::download('mau-nhap-san-pham', $headings, fn () => [
            ['Áo thun basic', 'AT-DO-M', '8930000000011', 'Sản phẩm thường', 'Áo', 'ITSO', 'Áo cotton', 'ao,cotton', 'Cái', 200, 'Màu', 'Đỏ', 'Size', 'M', null, null, 150000, 120000, 80000, 'Có', 'Không', null, ...$stock(10, 80000)],
            ['Áo thun basic', 'AT-DO-L', '8930000000028', 'Sản phẩm thường', 'Áo', 'ITSO', 'Áo cotton', 'ao,cotton', 'Cái', 220, 'Màu', 'Đỏ', 'Size', 'L', null, null, 150000, 120000, 80000, 'Có', 'Không', null, ...$stock(5, 80000)],
            ['Microsoft 365 Business Standard (1 năm)', 'M365-BS', null, 'Dịch vụ', 'License', 'Microsoft', null, null, 'Gói', null, null, null, null, null, null, null, 3200000, 3000000, 2700000, 'Có', 'Không', null, ...$stock(0, 0)],
        ]);
    }

    /**
     * Read, check every row, then save everything in one transaction.
     * Existing SKUs are updated (stock untouched); new ones are created with
     * their opening stock. Any invalid row: nothing is saved.
     *
     * @return array{created: int, updated: int}
     *
     * @throws ValidationException with "rows" listing errors by line
     */
    public function import(string $path, ?int $defaultBranchId): array
    {
        $sheet = ExcelImport::read($path, 10000);

        if ($sheet['rows'] === []) {
            throw ValidationException::withMessages(['file' => 'Tệp không có dòng dữ liệu nào.']);
        }

        [$columns, $priceColumns, $branchColumns, $errors] = $this->mapColumns($sheet['headings']);

        if (! in_array('name', $columns, true)) {
            $errors[] = 'Không thấy cột "Tên sản phẩm".';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => 'Tệp không đúng mẫu.', 'rows' => implode("\n", $errors)]);
        }

        // Read every row into fields.
        $rows = [];

        foreach ($sheet['rows'] as $raw) {
            $fields = [];
            $prices = [];
            $stock = [];

            foreach ($columns as $position => $field) {
                if ($field !== null && ! isset($fields[$field])) {
                    $fields[$field] = $raw['values'][$position] ?? null;
                }
            }

            foreach ($priceColumns as $position => $listName) {
                $prices[$listName] = $raw['values'][$position] ?? null;
            }

            foreach ($branchColumns as $position => [$branchId, $kind]) {
                $stock[$branchId][$kind] = $raw['values'][$position] ?? null;
            }

            $rows[] = [...$fields, 'line' => $raw['line'], 'prices' => $prices, 'stock' => $stock];
        }

        $groups = $this->group($rows);
        $errors = [];
        $plans = [];
        $skus = [];
        $barcodes = [];

        $products = [];

        foreach ($groups as $group) {
            $plan = $this->plan($group, $defaultBranchId, $errors, $skus, $barcodes);

            if ($plan === null) {
                continue;
            }

            if ($plan['product'] !== null) {
                $id = $plan['product']->id;

                if (isset($products[$id])) {
                    $line = $plan['lines'][0];
                    $errors[$line] = "Dòng {$line}: mã SKU thuộc cùng sản phẩm với dòng {$products[$id]} nhưng khác tên sản phẩm.";
                }

                $products[$id] ??= $plan['lines'][0];
            }

            $plans[] = $plan;
        }

        $this->checkCodes($skus, $barcodes, $plans, $errors);

        if ($errors !== []) {
            ksort($errors);

            throw ValidationException::withMessages([
                'file' => 'Có '.count($errors).' dòng lỗi, chưa nhập dòng nào. Sửa tệp rồi nhập lại.',
                'rows' => implode("\n", array_slice(array_values($errors), 0, 30)),
            ]);
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($plans, $priceColumns, &$created, &$updated): void {
            $priceListIds = $this->priceLists(array_values(array_unique($priceColumns)));

            foreach ($plans as $plan) {
                $plan['data']['category_id'] = $this->lookup(Category::class, $plan['category']) ?? $plan['product']?->category_id;
                $plan['data']['brand_id'] = $this->lookup(Brand::class, $plan['brand']) ?? $plan['product']?->brand_id;

                foreach ($plan['prices'] as $index => $prices) {
                    foreach ($prices as $name => $price) {
                        $plan['data']['variants'][$index]['prices'][$priceListIds[$name]] = $price;
                    }
                }

                $product = $plan['product'];
                $openings = $plan['openings'];
                $saved = $this->products->save($product, $plan['data']);

                $product === null ? $created++ : $updated++;

                // Opening stock in other branches than the first one.
                foreach ($openings as [$sku, $branchId, $quantity, $cost]) {
                    $variant = $saved->variants()->where('sku', $sku)->first();

                    if ($variant !== null && $saved->tracksInventory()) {
                        $this->inventory->move($branchId, $variant, $quantity, StockMovement::OPENING, [
                            'unit_cost' => $cost,
                            'update_cost' => true,
                            'reference' => $saved,
                            'note' => 'Khởi tạo kho từ Excel',
                            'allow_negative' => true,
                        ]);
                    }
                }
            }
        });

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * @param  list<string>  $headings
     * @return array{0: array<int, string|null>, 1: array<int, string>, 2: array<int, array{0: int, 1: string}>, 3: list<string>}
     */
    protected function mapColumns(array $headings): array
    {
        $branches = Branch::query()->get();
        $columns = [];
        $prices = [];
        $stock = [];
        $errors = [];

        foreach ($headings as $position => $heading) {
            $key = ExcelImport::key($heading);
            $columns[$position] = self::ALIASES[$key] ?? null;

            if ($columns[$position] !== null) {
                continue;
            }

            $text = trim(str_replace('*', '', $heading));

            if (preg_match('/^PL[_\s](.+)$/iu', $text, $match)) {
                $prices[$position] = trim($match[1]);

                continue;
            }

            if (preg_match('/^LC[_\s](.+)[_\s](T[ồo]n kho( ban [đd][ầa]u)?|Gi[áa] v[ốo]n( kh[ởo]i t[ạa]o)?)$/iu', $text, $match)) {
                $branchKey = ExcelImport::key($match[1]);
                $branch = $branches->first(fn (Branch $branch): bool => ExcelImport::key($branch->name) === $branchKey || ExcelImport::key($branch->code) === $branchKey);

                if ($branch === null) {
                    $errors[] = "Cột \"{$heading}\": không có chi nhánh \"{$match[1]}\". Hãy tạo chi nhánh hoặc đổi tên cột.";

                    continue;
                }

                $stock[$position] = [$branch->id, str_starts_with(ExcelImport::key($match[2]), 'ton') ? 'quantity' : 'cost'];
            }
        }

        return [$columns, $prices, $stock, $errors];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<list<array<string, mixed>>>
     */
    protected function group(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $key = trim((string) ($row['alias'] ?? '')) !== ''
                ? 'a:'.mb_strtolower(trim((string) $row['alias']))
                : 'n:'.mb_strtolower(trim((string) ($row['name'] ?? '')));

            if ($key === 'n:') {
                $key = 'line:'.$row['line'];
            }

            $groups[$key][] = $row;
        }

        return array_values($groups);
    }

    /**
     * Check one product's rows and turn them into ProductService data.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, string>  $errors  line => message
     * @param  array<string, array{line: int, product: int|null}>  $skus
     * @param  array<string, array{line: int, sku: string}>  $barcodes
     * @return array{product: Product|null, data: ProductData, category: string|null, brand: string|null, openings: list<array{0: string, 1: int, 2: int, 3: int}>, prices: list<array<array-key, int|null>>, lines: list<int>}|null
     */
    protected function plan(array $rows, ?int $defaultBranchId, array &$errors, array &$skus, array &$barcodes): ?array
    {
        $first = $rows[0];
        $line = (int) $first['line'];
        $name = trim((string) ($first['name'] ?? ''));
        $fail = function (int $line, string $message) use (&$errors): void {
            $errors[$line] = isset($errors[$line]) ? $errors[$line].' '.$message : "Dòng {$line}: {$message}";
        };

        if ($name === '') {
            $fail($line, 'Thiếu tên sản phẩm.');

            return null;
        }

        if (mb_strlen($name) > 255) {
            $fail($line, 'Tên sản phẩm dài quá 255 ký tự.');
        }

        $type = $this->type($first);

        if ($type === Product::TYPE_COMBO) {
            $fail($line, 'Combo cần tạo trên màn hình sản phẩm (chọn thành phần), không nhập từ Excel.');

            return null;
        }

        // The product this file updates: the one owning any of the SKUs.
        $existingVariants = ProductVariant::query()->withTrashed()
            ->whereIn('sku', array_values(array_filter(array_map(fn (array $row): string => trim((string) ($row['sku'] ?? '')), $rows))))
            ->get();
        $productIds = $existingVariants->pluck('product_id')->unique()->values();

        if ($existingVariants->contains(fn (ProductVariant $variant): bool => $variant->trashed() || $variant->base_variant_id !== null)) {
            $fail($line, 'Có mã SKU thuộc sản phẩm đã xóa hoặc là đơn vị quy đổi.');

            return null;
        }

        if ($productIds->count() > 1) {
            $fail($line, 'Các mã SKU của "'.$name.'" thuộc nhiều sản phẩm khác nhau.');

            return null;
        }

        $product = $productIds->isEmpty() ? null : Product::query()->with('variants')->whereKey($productIds->first())->first();

        if ($product !== null && $product->type === Product::TYPE_COMBO) {
            $fail($line, 'Không cập nhật combo từ Excel.');

            return null;
        }

        if ($product !== null) {
            $type = $product->type;
        }

        $options = [];

        foreach ([1, 2, 3] as $i) {
            $option = trim((string) ($first["option{$i}"] ?? ''));

            if ($option !== '') {
                $options[$i] = $option;
            }
        }

        if ($type !== Product::TYPE_NORMAL) {
            $options = [];
        }

        if ($options === [] && count($rows) > 1) {
            foreach (array_slice($rows, 1) as $row) {
                $fail((int) $row['line'], 'Trùng tên sản phẩm "'.$name.'" nhưng không có thuộc tính để phân biệt phiên bản.');
            }
        }

        $variants = [];
        $variantPrices = [];
        $variantBranches = [];
        $openings = [];
        $combinations = [];

        foreach ($rows as $row) {
            $rowLine = (int) $row['line'];
            $sku = trim((string) ($row['sku'] ?? ''));
            $barcode = trim((string) ($row['barcode'] ?? ''));
            $values = [];

            foreach (array_keys($options) as $i) {
                $value = trim((string) ($row["value{$i}"] ?? ''));

                if ($value === '') {
                    $fail($rowLine, "Thiếu giá trị thuộc tính {$options[$i]}.");
                }

                $values[] = $value;
            }

            $comboKey = mb_strtolower(implode('|', $values));

            if ($options !== [] && isset($combinations[$comboKey])) {
                $fail($rowLine, 'Phiên bản '.implode(' / ', $values).' bị trùng.');
            }

            $combinations[$comboKey] = true;

            if (mb_strlen($sku) > 50 || mb_strlen($barcode) > 50) {
                $fail($rowLine, 'Mã SKU / mã vạch dài quá 50 ký tự.');
            }

            if ($sku !== '') {
                if (isset($skus[mb_strtolower($sku)])) {
                    $fail($rowLine, "Mã SKU {$sku} bị trùng với dòng {$skus[mb_strtolower($sku)]['line']}.");
                }

                $skus[mb_strtolower($sku)] = ['line' => $rowLine, 'product' => $product?->id];
            }

            if ($barcode !== '') {
                if (isset($barcodes[mb_strtolower($barcode)])) {
                    $fail($rowLine, "Mã vạch {$barcode} bị trùng với dòng {$barcodes[mb_strtolower($barcode)]['line']}.");
                }

                $barcodes[mb_strtolower($barcode)] = ['line' => $rowLine, 'sku' => $sku];
            }

            $money = [];

            foreach (['retail_price' => 'giá bán lẻ', 'wholesale_price' => 'giá bán buôn', 'import_price' => 'giá nhập', 'cost' => 'giá vốn'] as $field => $label) {
                $number = $this->number($row[$field] ?? null);

                if ($number === false || ($number ?? 0) < 0) {
                    $fail($rowLine, "Sai {$label}: \"{$row[$field]}\".");
                    $number = null;
                }

                $money[$field] = $number;
            }

            $prices = [];

            foreach ($row['prices'] as $listName => $value) {
                $price = $this->number($value);

                if ($price === false || ($price ?? 0) < 0) {
                    $fail($rowLine, "Sai giá \"{$listName}\": \"{$value}\".");

                    continue;
                }

                $prices[$listName] = $price;
            }

            $weight = trim(str_replace(',', '.', (string) ($row['weight'] ?? '')));

            if ($weight !== '' && ! is_numeric($weight)) {
                $fail($rowLine, "Sai khối lượng: \"{$row['weight']}\".");
                $weight = null;
            } elseif ($weight !== '') {
                $isKg = in_array(mb_strtolower(trim((string) ($row['weight_unit'] ?? ''))), ['kg', 'kilogram'], true);
                $weight = (int) round((float) $weight * ($isKg ? 1000 : 1));
            } else {
                $weight = null;
            }

            $existing = $sku === '' ? null : $product?->variants->first(fn (ProductVariant $variant): bool => mb_strtolower($variant->sku) === mb_strtolower($sku));

            // Opening stock: LC_ columns per branch, or "Tồn kho ban đầu" for the current branch.
            $stock = $row['stock'];

            if (isset($row['quantity']) && $defaultBranchId !== null && ! isset($stock[$defaultBranchId]['quantity'])) {
                $stock[$defaultBranchId] = ['quantity' => $row['quantity'], 'cost' => $row['cost'] ?? null];
            }

            $variantOpenings = [];

            foreach ($stock as $branchId => $values2) {
                $quantity = $this->number($values2['quantity'] ?? null);
                $cost = $this->number($values2['cost'] ?? null);

                if ($quantity === false || $cost === false || ($cost ?? 0) < 0) {
                    $fail($rowLine, 'Sai tồn kho / giá vốn khởi tạo.');

                    continue;
                }

                if ($existing === null && $type === Product::TYPE_NORMAL && (int) $quantity !== 0) {
                    $variantOpenings[] = [(int) $branchId, (int) $quantity, (int) ($cost ?? $money['cost'] ?? $money['import_price'] ?? 0)];
                }
            }

            $firstOpening = array_shift($variantOpenings);

            foreach ($variantOpenings as [$branchId, $quantity, $cost]) {
                $openings[] = [$sku, $branchId, $quantity, $cost];
            }

            if ($variantOpenings !== [] && $sku === '') {
                $fail($rowLine, 'Cần mã SKU khi khởi tạo tồn ở nhiều chi nhánh.');
            }

            $variants[] = [
                'id' => $existing?->id,
                'options' => $values,
                'sku' => $sku === '' ? $existing?->sku : $sku,
                'barcode' => $barcode === '' ? ($existing?->barcode) : $barcode,
                'retail_price' => (int) ($money['retail_price'] ?? $existing->retail_price ?? 0),
                'wholesale_price' => (int) ($money['wholesale_price'] ?? $existing->wholesale_price ?? 0),
                'import_price' => (int) ($money['import_price'] ?? $existing->import_price ?? 0),
                'weight' => (int) ($weight ?? $existing->weight ?? 0),
                'opening_quantity' => $firstOpening[1] ?? 0,
                'opening_cost' => $firstOpening[2] ?? ($money['cost'] ?? null),
            ];
            $variantPrices[] = $prices;
            $variantBranches[] = $firstOpening[0] ?? null;
        }

        // ProductService opens stock in one branch; openings in other branches are done after it.
        $branchIds = array_values(array_unique(array_filter($variantBranches)));
        $openingBranch = $branchIds[0] ?? null;

        foreach ($variants as $index => $variant) {
            $branchId = $variantBranches[$index];

            if ($branchId !== null && $branchId !== $openingBranch) {
                $openings[] = [(string) $variant['sku'], $branchId, (int) $variant['opening_quantity'], (int) $variant['opening_cost']];
                $variants[$index]['opening_quantity'] = 0;
            }
        }

        // Keep variants of the product that are not in the file.
        if ($product !== null) {
            $listed = array_filter(array_column($variants, 'id'));

            foreach ($product->variants->whereNull('base_variant_id') as $other) {
                if (! in_array($other->id, $listed, true)) {
                    $variants[] = [
                        'id' => $other->id,
                        'options' => array_slice([$other->option1, $other->option2, $other->option3], 0, count($options)),
                        'sku' => $other->sku,
                        'barcode' => $other->barcode,
                        'retail_price' => $other->retail_price,
                        'wholesale_price' => $other->wholesale_price,
                        'import_price' => $other->import_price,
                        'weight' => $other->weight,
                    ];
                }
            }
        }

        $sellable = $this->boolean($first['is_sellable'] ?? null);
        $negative = $this->boolean($first['allow_negative'] ?? null);
        $image = trim(explode(',', (string) ($first['image_url'] ?? ''))[0]);

        return [
            'product' => $product,
            'category' => $this->text($first['category'] ?? null),
            'brand' => $this->text($first['brand'] ?? null),
            'openings' => $openings,
            'prices' => $variantPrices,
            'lines' => array_map(fn (array $row): int => (int) $row['line'], $rows),
            'data' => [
                'type' => $type,
                'name' => mb_substr($name, 0, 255),
                'description' => $this->text($first['description'] ?? null) ?? $product?->description,
                'tags' => $this->text($first['tags'] ?? null, 255) ?? $product?->tags,
                'unit' => $this->text($first['unit'] ?? null, 30) ?? $product?->unit,
                'image_url' => filter_var($image, FILTER_VALIDATE_URL) !== false ? mb_substr($image, 0, 500) : $product?->image_url,
                'is_sellable' => $sellable ?? $product->is_sellable ?? true,
                'allow_negative_stock' => $negative ?? $product->allow_negative_stock ?? false,
                'options' => array_values($options),
                'variants' => $variants,
                'opening_branch_id' => $openingBranch,
            ],
        ];
    }

    /**
     * SKUs/barcodes in the file must not belong to other products.
     *
     * @param  array<string, array{line: int, product: int|null}>  $skus
     * @param  array<string, array{line: int, sku: string}>  $barcodes
     * @param  list<array<string, mixed>>  $plans
     * @param  array<int, string>  $errors
     */
    protected function checkCodes(array $skus, array $barcodes, array $plans, array &$errors): void
    {
        if ($barcodes === []) {
            return;
        }

        $taken = ProductVariant::query()->withTrashed()
            ->whereIn('barcode', array_keys($barcodes))
            ->get(['id', 'sku', 'barcode']);

        foreach ($taken as $variant) {
            $entry = $barcodes[mb_strtolower((string) $variant->barcode)] ?? null;

            if ($entry !== null && mb_strtolower($entry['sku']) !== mb_strtolower($variant->sku)) {
                $line = $entry['line'];
                $message = "Mã vạch {$variant->barcode} đã dùng cho SKU {$variant->sku}.";
                $errors[$line] = isset($errors[$line]) ? $errors[$line].' '.$message : "Dòng {$line}: {$message}";
            }
        }
    }

    /**
     * Find or create the custom price lists named in PL_ columns.
     *
     * @param  list<string>  $names
     * @return array<string, int>
     */
    protected function priceLists(array $names): array
    {
        $ids = [];

        foreach ($names as $name) {
            $list = PriceList::query()->where('name', $name)->first()
                ?? PriceList::query()->create(['name' => $name, 'code' => $this->priceListCode($name), 'status' => 'active']);
            $ids[$name] = $list->id;
        }

        return $ids;
    }

    protected function priceListCode(string $name): string
    {
        $base = Str::upper(Str::limit(Str::slug($name, ''), 20, ''));
        $code = $base;
        $i = 2;

        while (PriceList::query()->withTrashed()->where('code', $code)->exists()) {
            $code = $base.$i++;
        }

        return $code;
    }

    /**
     * @param  class-string<Category|Brand>  $model
     */
    protected function lookup(string $model, ?string $name): ?int
    {
        return $name === null ? null : $model::query()->firstOrCreate(['name' => $name])->id;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function type(array $row): string
    {
        $type = ExcelImport::key((string) ($row['type'] ?? ''));

        if (str_contains($type, 'combo')) {
            return Product::TYPE_COMBO;
        }

        if (str_contains($type, 'dich_vu') || str_contains($type, 'license') || $this->boolean($row['track_stock'] ?? null) === false) {
            return Product::TYPE_SERVICE;
        }

        return Product::TYPE_NORMAL;
    }

    /**
     * "1.200.000", "1,200,000", "1200000", "1.200.000 đ" → 1200000. Blank → null, invalid → false.
     */
    protected function number(mixed $value): int|false|null
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        $text = (string) preg_replace('/[\s₫đĐ]|vnd/iu', '', $text);
        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '-');

        if (preg_match('/^\d+([.,]\d{1,2})$/', $text) === 1 && preg_match('/^\d{1,3}[.,]\d{3}$/', $text) !== 1) {
            $number = (int) round((float) str_replace(',', '.', $text));
        } elseif (preg_match('/^\d{1,3}([.,]\d{3})+$/', $text) === 1 || preg_match('/^\d+$/', $text) === 1) {
            $number = (int) str_replace(['.', ','], '', $text);
        } else {
            return false;
        }

        return $negative ? -$number : $number;
    }

    protected function boolean(mixed $value): ?bool
    {
        $key = ExcelImport::key((string) $value);

        return match (true) {
            $key === '' => null,
            in_array($key, ['co', 'yes', 'y', '1', 'true', 'x', 'cho_phep'], true) => true,
            in_array($key, ['khong', 'no', 'n', '0', 'false', 'khong_cho_phep'], true) => false,
            default => null,
        };
    }

    protected function text(mixed $value, int $max = 10000): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
