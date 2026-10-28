<?php

namespace App\Http\Controllers\Config;

use App\Http\Controllers\Controller;
use App\Models\TaxRate;
use App\Support\Activity\ActivityLogger;
use App\Support\Settings\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Thuế": default sale/purchase tax and the list of tax rates.
 */
class TaxController extends Controller
{
    public function index(SettingsRepository $settings): Response
    {
        return Inertia::render('config/taxes', [
            'rates' => TaxRate::query()->orderBy('id')->get(['id', 'name', 'code', 'rate_bp', 'type']),
            'types' => TaxRate::TYPES,
            'defaults' => [
                'sale' => $settings->get('tax.default_sale'),
                'purchase' => $settings->get('tax.default_purchase'),
                'price_includes_tax' => (bool) $settings->get('tax.price_includes_tax'),
            ],
        ]);
    }

    public function updateDefaults(Request $request, SettingsRepository $settings, ActivityLogger $activity): RedirectResponse
    {
        $codes = TaxRate::query()->pluck('code')->all();

        $data = $request->validate([
            'sale' => ['required', Rule::in($codes)],
            'purchase' => ['required', Rule::in($codes)],
            'price_includes_tax' => ['required', 'boolean'],
        ]);

        $old = ['sale' => $settings->get('tax.default_sale'), 'purchase' => $settings->get('tax.default_purchase'), 'price_includes_tax' => $settings->get('tax.price_includes_tax')];

        $settings->setMany([
            'tax.default_sale' => $data['sale'],
            'tax.default_purchase' => $data['purchase'],
            'tax.price_includes_tax' => (bool) $data['price_includes_tax'],
        ]);
        $activity->log('updated', 'Sửa thiết lập thuế', null, ['old' => $old, 'new' => $data]);

        $this->toast('Đã lưu thiết lập thuế.');

        return back();
    }

    public function store(Request $request): RedirectResponse
    {
        TaxRate::query()->create($this->validated($request));

        $this->toast('Đã thêm mức thuế.');

        return back();
    }

    public function update(Request $request, TaxRate $taxRate): RedirectResponse
    {
        $data = $this->validated($request, $taxRate);

        if ($this->inUse($taxRate) && $data['code'] !== $taxRate->code) {
            return back()->withErrors(['code' => 'Mức thuế đang được dùng làm mặc định, không đổi mã được.']);
        }

        $taxRate->update($data);

        $this->toast('Đã lưu mức thuế.');

        return back();
    }

    public function destroy(TaxRate $taxRate): RedirectResponse
    {
        if ($this->inUse($taxRate)) {
            $this->toast('Mức thuế đang được dùng làm mặc định, không xóa được.', 'error');

            return back();
        }

        $taxRate->delete();

        $this->toast('Đã xóa mức thuế.');

        return back();
    }

    protected function inUse(TaxRate $rate): bool
    {
        return in_array($rate->code, [settings('tax.default_sale'), settings('tax.default_purchase')], true);
    }

    /**
     * @return array{name: string, code: string, rate_bp: int, type: string}
     */
    protected function validated(Request $request, ?TaxRate $rate = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        /** @var array{name: string, code: string, rate: numeric-string|int|float, type: string} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_]+$/', Rule::unique('tax_rates')->where('tenant_id', tenancy()->id())->ignore($rate)],
            'rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'type' => ['required', Rule::in(array_keys(TaxRate::TYPES))],
        ], ['code.regex' => 'Mã chỉ gồm chữ in hoa không dấu, số và dấu _.']);

        return [
            'name' => $data['name'],
            'code' => $data['code'],
            // "10.5" → 1050 without float rounding.
            'rate_bp' => (int) round(((float) $data['rate']) * 100),
            'type' => $data['type'],
        ];
    }
}
