<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\DataTable\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Purchase\Models\PurchaseReceipt;
use Modules\Purchase\Models\Supplier;
use Modules\Purchase\Models\SupplierDebt;
use Modules\Purchase\Services\PurchaseService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Nhà cung cấp": list with debt, create/edit, purchase history and debt ledger.
 */
class SupplierController extends Controller
{
    public const STATUSES = ['active' => 'Đang giao dịch', 'inactive' => 'Ngừng giao dịch'];

    public function index(Request $request): Response|StreamedResponse
    {
        $table = DataTable::for(Supplier::query(), $request, 'suppliers')
            ->search(['code', 'name', 'phone', 'email', 'tax_code'])
            ->filterExact('status')
            ->filter('debt', fn (Builder $query, mixed $value) => match ($value) {
                'owing' => $query->where('debt', '>', 0),
                'none' => $query->where('debt', '<=', 0),
                default => null,
            })
            ->filterDateRange('created_at')
            ->sortable(['code', 'name', 'debt', 'created_at'], '-id');

        if ($request->query('export') === 'xlsx') {
            abort_unless($request->user()?->can('suppliers.export'), 403);

            return $table->export('nha-cung-cap-'.now()->format('Ymd-His'), [
                'Mã nhà cung cấp' => fn (Supplier $supplier) => $supplier->code,
                'Tên nhà cung cấp' => fn (Supplier $supplier) => $supplier->name,
                'Số điện thoại' => fn (Supplier $supplier) => $supplier->phone,
                'Email' => fn (Supplier $supplier) => $supplier->email,
                'Địa chỉ' => fn (Supplier $supplier) => $supplier->address,
                'Mã số thuế' => fn (Supplier $supplier) => $supplier->tax_code,
                'Công nợ hiện tại' => fn (Supplier $supplier) => $supplier->debt,
                'Trạng thái' => fn (Supplier $supplier) => self::STATUSES[$supplier->status] ?? $supplier->status,
            ]);
        }

        return Inertia::render('suppliers/index', [
            'suppliers' => $table->paginate(fn (Supplier $supplier): array => [
                'id' => $supplier->id,
                'code' => $supplier->code,
                'name' => $supplier->name,
                'phone' => $supplier->phone,
                'email' => $supplier->email,
                'debt' => $supplier->debt,
                'status' => $supplier->status,
                'created_at' => $supplier->created_at?->toIso8601String(),
            ]),
            'totalDebt' => (int) Supplier::query()->where('debt', '>', 0)->sum('debt'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('suppliers/form', ['supplier' => null]);
    }

    public function store(Request $request, PurchaseService $purchases): RedirectResponse
    {
        $data = $this->validated($request, null);
        $supplier = Supplier::query()->create([
            ...$data,
            'code' => $data['code'] ?? $purchases->supplierCode(),
            'created_by' => $request->user()?->id,
        ]);

        $this->toast("Đã thêm nhà cung cấp {$supplier->name}.");

        return to_route('suppliers.edit', $supplier);
    }

    public function edit(Request $request, Supplier $supplier): Response
    {
        $branchIds = $request->user()?->branchIds() ?? [];

        return Inertia::render('suppliers/form', [
            'supplier' => [
                ...$supplier->only('id', 'code', 'name', 'phone', 'email', 'address', 'tax_code', 'contact_name', 'note', 'status', 'debt'),
                'created_at' => $supplier->created_at?->toIso8601String(),
                'receipts_count' => $supplier->receipts()->where('status', PurchaseReceipt::RECEIVED)->count(),
                'receipts_total' => (int) $supplier->receipts()->where('status', PurchaseReceipt::RECEIVED)->sum('total'),
            ],
            'receipts' => $supplier->receipts()
                ->with('branch:id,name')
                ->whereIn('branch_id', $branchIds)
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (PurchaseReceipt $receipt): array => [
                    'id' => $receipt->id,
                    'code' => $receipt->code,
                    'branch' => $receipt->branch->name,
                    'status' => $receipt->status,
                    'payment_status' => $receipt->paymentStatus(),
                    'total' => $receipt->total,
                    'created_at' => $receipt->created_at?->toIso8601String(),
                ]),
            'debts' => $supplier->debts()
                ->with('creator:id,name')
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (SupplierDebt $line): array => [
                    'id' => $line->id,
                    'type' => $line->typeLabel(),
                    'amount' => $line->amount,
                    'debt_after' => $line->debt_after,
                    'reference_code' => $line->reference_code,
                    'reference_type' => $line->reference_type,
                    'reference_id' => $line->reference_id,
                    'payment_method' => $line->payment_method === null ? null : (SupplierDebt::PAYMENT_METHODS[$line->payment_method] ?? $line->payment_method),
                    'note' => $line->note,
                    'creator' => $line->creator?->name,
                    'created_at' => $line->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $this->validated($request, $supplier);
        $supplier->update([...$data, 'code' => $data['code'] ?? $supplier->code]);

        $this->toast("Đã lưu nhà cung cấp {$supplier->name}.");

        return to_route('suppliers.edit', $supplier);
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        if ($supplier->debt !== 0 || $supplier->receipts()->exists() || PurchaseReceipt::query()->where('supplier_id', $supplier->id)->exists()) {
            return back()->withErrors(['supplier' => 'Nhà cung cấp đã có phiếu nhập hoặc công nợ, chỉ chuyển được sang Ngừng giao dịch.']);
        }

        $supplier->delete();
        $this->toast("Đã xóa nhà cung cấp {$supplier->name}.");

        return to_route('suppliers.index');
    }

    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['activate', 'deactivate', 'delete'])],
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        abort_unless($request->user()?->can($data['action'] === 'delete' ? 'suppliers.delete' : 'suppliers.update'), 403);

        $suppliers = Supplier::query()->whereIn('id', $data['ids'])->get();
        $done = 0;

        foreach ($suppliers as $supplier) {
            if ($data['action'] === 'delete') {
                if ($supplier->debt === 0 && ! $supplier->receipts()->exists()) {
                    $supplier->delete();
                    $done++;
                }

                continue;
            }

            $supplier->update(['status' => $data['action'] === 'activate' ? 'active' : 'inactive']);
            $done++;
        }

        $skipped = $suppliers->count() - $done;
        $this->toast("Đã cập nhật {$done} nhà cung cấp.".($skipped > 0 ? " Bỏ qua {$skipped} nhà cung cấp đã có phiếu nhập hoặc công nợ." : ''));

        return back();
    }

    /**
     * Supplier picker on purchase forms.
     */
    public function search(Request $request): JsonResponse
    {
        $term = addcslashes(mb_substr($request->string('q')->trim()->toString(), 0, 100), '%_\\');

        $suppliers = Supplier::query()
            ->where('status', 'active')
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")))
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json($suppliers->map(fn (Supplier $supplier): array => self::option($supplier)));
    }

    /**
     * @return array{id: int, code: string, name: string, phone: string|null, address: string|null, debt: int}
     */
    public static function option(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'code' => $supplier->code,
            'name' => $supplier->name,
            'phone' => $supplier->phone,
            'address' => $supplier->address,
            'debt' => $supplier->debt,
        ];
    }

    /**
     * @return array{code: string|null, name: string, phone: string|null, email: string|null, address: string|null, tax_code: string|null, contact_name: string|null, note: string|null, status: string}
     */
    protected function validated(Request $request, ?Supplier $supplier): array
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:50', Rule::unique('suppliers', 'code')->where('tenant_id', tenancy()->id())->ignore($supplier?->id)],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'tax_code' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(array_keys(self::STATUSES))],
        ], [], [
            'code' => 'mã nhà cung cấp',
            'name' => 'tên nhà cung cấp',
            'phone' => 'số điện thoại',
        ]);

        return [
            'code' => isset($data['code']) && $data['code'] !== '' ? $data['code'] : null,
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'tax_code' => $data['tax_code'] ?? null,
            'contact_name' => $data['contact_name'] ?? null,
            'note' => $data['note'] ?? null,
            'status' => $data['status'],
        ];
    }
}
