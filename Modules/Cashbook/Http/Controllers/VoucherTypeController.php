<?php

namespace Modules\Cashbook\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Cashbook\Models\CashVoucher;
use Modules\Cashbook\Models\CashVoucherType;

/**
 * "Loại phiếu thu" / "Loại phiếu chi". Built-in types can be renamed but
 * not deleted; a type in use cannot be deleted either.
 */
class VoucherTypeController extends Controller
{
    public function receipts(): Response
    {
        return $this->index(CashVoucher::RECEIPT);
    }

    public function payments(): Response
    {
        return $this->index(CashVoucher::PAYMENT);
    }

    public function store(Request $request): RedirectResponse
    {
        $kind = $request->string('kind')->toString();
        abort_unless(in_array($kind, [CashVoucher::RECEIPT, CashVoucher::PAYMENT], true), 422);

        $data = $this->validated($request, $kind);
        CashVoucherType::query()->create([...$data, 'kind' => $kind, 'code' => $data['code'] ?: $this->uniqueCode($kind, $data['name'])]);

        $this->toast('Đã thêm '.(new CashVoucherType(['kind' => $kind]))->activityLabel().'.');

        return back();
    }

    public function update(Request $request, CashVoucherType $type): RedirectResponse
    {
        $data = $this->validated($request, $type->kind, $type);

        if ($type->is_system || $data['code'] === null) {
            unset($data['code']);
        }

        if ($type->code === CashVoucherType::AUTO) {
            // "Tự động" stays out of the profit report.
            $data['affects_profit'] = false;
        }

        $type->update($data);

        $this->toast('Đã lưu loại phiếu.');

        return back();
    }

    public function destroy(CashVoucherType $type): RedirectResponse
    {
        if ($type->is_system) {
            $this->toast('Không xóa được loại phiếu có sẵn.', 'error');

            return back();
        }

        if (CashVoucher::query()->where('type_id', $type->id)->exists()) {
            $this->toast('Loại phiếu đã có phiếu thu/chi, không xóa được.', 'error');

            return back();
        }

        $type->delete();
        $this->toast('Đã xóa loại phiếu.');

        return back();
    }

    protected function index(string $kind): Response
    {
        $used = CashVoucher::query()->where('kind', $kind)->distinct()->pluck('type_id')->all();

        return Inertia::render('cash-voucher-types/index', [
            'kind' => $kind,
            'types' => CashVoucherType::query()->where('kind', $kind)->orderByDesc('id')->get()
                ->map(fn (CashVoucherType $type): array => [
                    'id' => $type->id,
                    'code' => $type->code,
                    'name' => $type->name,
                    'affects_profit' => $type->affects_profit,
                    'description' => $type->description,
                    'is_system' => $type->is_system,
                    'is_auto' => $type->code === CashVoucherType::AUTO,
                    'in_use' => in_array($type->id, $used, true),
                    'created_at' => $type->created_at?->toIso8601String(),
                ]),
        ]);
    }

    /**
     * @return array{name: string, code: string|null, affects_profit: bool, description: string|null}
     */
    protected function validated(Request $request, string $kind, ?CashVoucherType $type = null): array
    {
        $unique = fn (string $column) => Rule::unique('cash_voucher_types', $column)
            ->where('tenant_id', tenancy()->id())
            ->where('kind', $kind)
            ->ignore($type);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', $unique('name')],
            'code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_\-]+$/', $unique('code')],
            'affects_profit' => ['boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ], [
            'name.unique' => 'Đã có loại phiếu tên này.',
            'code.unique' => 'Mã loại đã được dùng.',
            'code.regex' => 'Mã loại chỉ gồm chữ không dấu, số, gạch dưới hoặc gạch ngang.',
        ], ['name' => 'tên loại', 'code' => 'mã loại']);

        return [
            'name' => $data['name'],
            'code' => isset($data['code']) && $data['code'] !== '' ? Str::upper($data['code']) : null,
            'affects_profit' => (bool) ($data['affects_profit'] ?? false),
            'description' => $data['description'] ?? null,
        ];
    }

    protected function uniqueCode(string $kind, string $name): string
    {
        $base = ($kind === CashVoucher::RECEIPT ? 'RVG' : 'PVG').(Str::upper(Str::limit(Str::slug($name, ''), 20, '')) ?: 'KHAC');
        $code = $base;

        for ($i = 2; CashVoucherType::query()->where('kind', $kind)->where('code', $code)->exists(); $i++) {
            $code = $base.$i;
        }

        return $code;
    }
}
