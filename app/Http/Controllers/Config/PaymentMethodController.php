<?php

namespace App\Http\Controllers\Config;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Support\Activity\ActivityLogger;
use App\Support\Settings\Banks;
use App\Support\Settings\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Thanh toán": payment methods, bank accounts for VietQR and the default
 * method at the counter and online.
 */
class PaymentMethodController extends Controller
{
    public function index(SettingsRepository $settings): Response
    {
        return Inertia::render('config/payment-methods', [
            'methods' => PaymentMethod::query()->orderBy('sort')->orderBy('id')->get(),
            'types' => PaymentMethod::TYPES,
            'banks' => Banks::LIST,
            'defaults' => [
                'pos' => $settings->get('payment.default_pos'),
                'online' => $settings->get('payment.default_online'),
            ],
        ]);
    }

    public function updateDefaults(Request $request, SettingsRepository $settings, ActivityLogger $activity): RedirectResponse
    {
        $active = PaymentMethod::query()->where('is_active', true)->pluck('id')->all();

        $data = $request->validate([
            'pos' => ['required', 'integer', Rule::in($active)],
            'online' => ['required', 'integer', Rule::in($active)],
        ]);

        $old = ['pos' => $settings->get('payment.default_pos'), 'online' => $settings->get('payment.default_online')];
        $settings->setMany(['payment.default_pos' => (int) $data['pos'], 'payment.default_online' => (int) $data['online']]);
        $activity->log('updated', 'Sửa hình thức thanh toán mặc định', null, ['old' => $old, 'new' => $data]);

        $this->toast('Đã lưu thanh toán mặc định.');

        return back();
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['sort'] = (int) PaymentMethod::query()->max('sort') + 1;

        PaymentMethod::query()->create($data);

        $this->toast('Đã thêm hình thức thanh toán.');

        return back();
    }

    public function update(Request $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        $data = $this->validated($request, $paymentMethod);

        if (! $data['is_active'] && $this->isDefault($paymentMethod)) {
            return back()->withErrors(['is_active' => 'Đây là hình thức mặc định, chọn hình thức mặc định khác trước khi tắt.']);
        }

        $paymentMethod->update($data);

        $this->toast('Đã lưu hình thức thanh toán.');

        return back();
    }

    public function destroy(PaymentMethod $paymentMethod): RedirectResponse
    {
        if ($paymentMethod->is_system || $this->isDefault($paymentMethod)) {
            $this->toast('Không xóa được hình thức có sẵn hoặc đang là mặc định; bạn có thể tắt nó.', 'error');

            return back();
        }

        $paymentMethod->delete();

        $this->toast('Đã xóa hình thức thanh toán.');

        return back();
    }

    protected function isDefault(PaymentMethod $method): bool
    {
        return in_array($method->id, [(int) settings('payment.default_pos'), (int) settings('payment.default_online')], true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?PaymentMethod $method = null): array
    {
        $types = $method?->is_system ? [$method->type] : ['bank', 'ewallet'];

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in($types)],
            'bank_bin' => ['nullable', 'required_with:account_number', Rule::in(array_keys(Banks::LIST))],
            'account_number' => ['nullable', 'string', 'max:30', 'regex:/^[0-9A-Za-z]+$/'],
            'account_name' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ], [
            'type.in' => 'Chỉ thêm được hình thức chuyển khoản hoặc ví điện tử.',
            'account_number.regex' => 'Số tài khoản chỉ gồm chữ và số.',
            'bank_bin.required_with' => 'Chọn ngân hàng của tài khoản.',
        ]);

        $isBank = $data['type'] === 'bank';

        return [
            'name' => $data['name'],
            'type' => $data['type'],
            'bank_bin' => $isBank ? ($data['bank_bin'] ?? null) : null,
            'account_number' => $isBank ? ($data['account_number'] ?? null) : null,
            'account_name' => $isBank && isset($data['account_name']) ? mb_strtoupper($data['account_name']) : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }
}
