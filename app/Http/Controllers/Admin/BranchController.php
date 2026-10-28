<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\DataTable\DataTable;
use App\Support\Excel\ExcelExport;
use App\Support\Excel\ExcelImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BranchController extends Controller
{
    public function index(Request $request): Response|StreamedResponse
    {
        $table = DataTable::for(Branch::query()->withCount('users'), $request, 'branches')
            ->search(['name', 'code', 'phone', 'address'])
            ->filterExact('status')
            ->sortable(['code', 'name', 'users_count', 'created_at'], 'code');

        if ($request->query('export') === 'xlsx') {
            return $table->export('chi-nhanh-'.now()->format('Ymd-His'), self::exportColumns());
        }

        return Inertia::render('admin/branches/index', [
            'branches' => $table->paginate(fn (Branch $branch): array => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
                'phone' => $branch->phone,
                'address' => $branch->address,
                'is_default' => $branch->is_default,
                'status' => $branch->status,
                'users_count' => $branch->users_count,
            ]),
        ]);
    }

    /**
     * Excel template for importing branches: the export headings, no rows.
     */
    public function template(): StreamedResponse
    {
        return ExcelExport::download('mau-nhap-chi-nhanh', array_keys(self::importHeadings()), fn () => [
            ['CN03', 'Chi nhánh Thủ Đức', '0909000003', '1 Võ Văn Ngân, Thủ Đức'],
        ]);
    }

    /**
     * Import branches from Excel: add new codes, update existing ones.
     * Nothing is saved when any row is invalid.
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:xlsx,csv,txt'],
        ], [], ['file' => 'tệp Excel']);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $path = $file->getRealPath().'.'.strtolower($file->getClientOriginalExtension());
        copy($file->getRealPath(), $path);

        try {
            $rows = ExcelImport::rows($path, self::importHeadings());
        } catch (\Throwable) {
            return back()->withErrors(['file' => 'Không đọc được tệp. Hãy dùng tệp mẫu .xlsx.']);
        } finally {
            @unlink($path);
        }

        if ($rows === []) {
            return back()->withErrors(['file' => 'Tệp không có dòng dữ liệu nào.']);
        }

        $errors = [];
        $codes = [];

        foreach ($rows as $row) {
            $validator = Validator::make($row['data'], [
                'code' => ['required', 'string', 'max:20', 'alpha_dash'],
                'name' => ['required', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:20'],
                'address' => ['nullable', 'string', 'max:255'],
            ], [], ['code' => 'mã chi nhánh', 'name' => 'tên chi nhánh', 'phone' => 'điện thoại', 'address' => 'địa chỉ']);

            $code = strtoupper((string) $row['data']['code']);

            if (isset($codes[$code])) {
                $validator->after(fn ($validator) => $validator->errors()->add('code', "Mã {$code} bị trùng trong tệp."));
            }

            $codes[$code] = true;

            if ($validator->fails()) {
                $errors[] = "Dòng {$row['line']}: ".implode(' ', $validator->errors()->all());
            }
        }

        if ($errors !== []) {
            return back()->withErrors([
                'file' => 'Có '.count($errors).' dòng lỗi, chưa nhập dòng nào. Sửa tệp rồi nhập lại.',
                'rows' => implode("\n", array_slice($errors, 0, 20)),
            ]);
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($rows, &$created, &$updated): void {
            foreach ($rows as $row) {
                $branch = Branch::withTrashed()->firstOrNew(['code' => strtoupper((string) $row['data']['code'])]);
                $branch->fill([
                    'name' => $row['data']['name'],
                    'phone' => $row['data']['phone'],
                    'address' => $row['data']['address'],
                ]);

                $branch->exists ? $updated++ : $created++;

                if ($branch->trashed()) {
                    $branch->restore();
                }

                $branch->save();
            }
        });

        $this->toast("Đã nhập chi nhánh: thêm {$created}, cập nhật {$updated}.");

        return to_route('branches.index');
    }

    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['activate', 'deactivate'])],
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        $branches = Branch::query()
            ->whereKey($data['ids'])
            ->when($data['action'] === 'deactivate', fn ($query) => $query->where('is_default', false))
            ->get();

        foreach ($branches as $branch) {
            $branch->update(['status' => $data['action'] === 'activate' ? 'active' : 'inactive']);
        }

        $this->toast("Đã cập nhật {$branches->count()} chi nhánh.");

        return back();
    }

    /**
     * @return array<string, string> heading => field
     */
    protected static function importHeadings(): array
    {
        return [
            'Mã chi nhánh*' => 'code',
            'Tên chi nhánh*' => 'name',
            'Điện thoại' => 'phone',
            'Địa chỉ' => 'address',
        ];
    }

    /**
     * @return array<string, \Closure(Branch): mixed>
     */
    protected static function exportColumns(): array
    {
        return [
            'Mã chi nhánh*' => fn (Branch $branch) => $branch->code,
            'Tên chi nhánh*' => fn (Branch $branch) => $branch->name,
            'Điện thoại' => fn (Branch $branch) => $branch->phone,
            'Địa chỉ' => fn (Branch $branch) => $branch->address,
            'Mặc định' => fn (Branch $branch) => $branch->is_default ? 'Có' : '',
            'Trạng thái' => fn (Branch $branch) => $branch->status === 'active' ? 'Đang hoạt động' : 'Ngừng hoạt động',
            'Số nhân viên' => fn (Branch $branch) => $branch->users_count,
        ];
    }

    public function create(): Response
    {
        return Inertia::render('admin/branches/form', ['branch' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $branch = Branch::query()->create($this->validated($request));
        $this->ensureSingleDefault($branch);

        $this->toast("Đã thêm chi nhánh {$branch->name}.");

        return to_route('branches.index');
    }

    public function edit(Branch $branch): Response
    {
        return Inertia::render('admin/branches/form', ['branch' => $branch]);
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $data = $this->validated($request, $branch);

        if ($branch->is_default && ! $data['is_default']) {
            return back()->withErrors(['is_default' => 'Phải có một chi nhánh mặc định. Hãy chọn chi nhánh khác làm mặc định trước.']);
        }

        $branch->update($data);
        $this->ensureSingleDefault($branch);

        $this->toast("Đã lưu chi nhánh {$branch->name}.");

        return to_route('branches.index');
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        if ($branch->is_default) {
            $this->toast('Không thể xóa chi nhánh mặc định.', 'error');

            return back();
        }

        $branch->delete();

        $this->toast("Đã xóa chi nhánh {$branch->name}.");

        return to_route('branches.index');
    }

    /**
     * @return array{code: string, name: string, phone: ?string, address: ?string, is_default: bool, status: string}
     */
    protected function validated(Request $request, ?Branch $branch = null): array
    {
        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:20', 'alpha_dash',
                Rule::unique('branches')->where('tenant_id', tenancy()->id())->ignore($branch),
            ],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_default' => ['boolean'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $data['is_default'] = (bool) ($data['is_default'] ?? false);

        if ($data['is_default'] && $data['status'] !== 'active') {
            $data['status'] = 'active';
        }

        /** @var array{code: string, name: string, phone: ?string, address: ?string, is_default: bool, status: string} $data */
        return $data;
    }

    protected function ensureSingleDefault(Branch $branch): void
    {
        if ($branch->is_default) {
            Branch::query()->whereKeyNot($branch->id)->where('is_default', true)->update(['is_default' => false]);
        }
    }
}
