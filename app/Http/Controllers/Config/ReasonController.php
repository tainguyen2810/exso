<?php

namespace App\Http\Controllers\Config;

use App\Http\Controllers\Controller;
use App\Models\OrderReason;
use App\Support\Activity\ActivityLogger;
use App\Support\Settings\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Lý do hủy, trả hàng": reasons staff pick when cancelling an order or
 * taking goods back, and whether picking one is required.
 */
class ReasonController extends Controller
{
    public function index(SettingsRepository $settings): Response
    {
        return Inertia::render('config/reasons', [
            'reasons' => OrderReason::query()->orderBy('sort')->orderBy('id')->get(['id', 'type', 'name', 'is_active']),
            'types' => OrderReason::TYPES,
            'required' => [
                'cancel' => (bool) $settings->get('reasons.require_cancel'),
                'return' => (bool) $settings->get('reasons.require_return'),
            ],
        ]);
    }

    public function updateSettings(Request $request, SettingsRepository $settings, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate([
            'cancel' => ['required', 'boolean'],
            'return' => ['required', 'boolean'],
        ]);

        $old = ['cancel' => $settings->get('reasons.require_cancel'), 'return' => $settings->get('reasons.require_return')];
        $settings->setMany([
            'reasons.require_cancel' => (bool) $data['cancel'],
            'reasons.require_return' => (bool) $data['return'],
        ]);
        $activity->log('updated', 'Sửa thiết lập lý do hủy, trả hàng', null, ['old' => $old, 'new' => $data]);

        $this->toast('Đã lưu thiết lập.');

        return back();
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['sort'] = (int) OrderReason::query()->where('type', $data['type'])->max('sort') + 1;

        OrderReason::query()->create($data);

        $this->toast('Đã thêm lý do.');

        return back();
    }

    public function update(Request $request, OrderReason $reason): RedirectResponse
    {
        $data = $this->validated($request, $reason);

        if (! $data['is_active'] && $this->lastActive($reason)) {
            return back()->withErrors(['is_active' => 'Cần giữ ít nhất một lý do đang dùng.']);
        }

        $reason->update($data);

        $this->toast('Đã lưu lý do.');

        return back();
    }

    public function destroy(OrderReason $reason): RedirectResponse
    {
        if ($this->lastActive($reason)) {
            $this->toast('Cần giữ ít nhất một lý do đang dùng.', 'error');

            return back();
        }

        $reason->delete();

        $this->toast('Đã xóa lý do.');

        return back();
    }

    /**
     * A required reason needs at least one active option to pick from.
     */
    protected function lastActive(OrderReason $reason): bool
    {
        return $reason->is_active
            && ! OrderReason::query()->where('type', $reason->type)->where('is_active', true)->whereKeyNot($reason->id)->exists();
    }

    /**
     * @return array{type: string, name: string, is_active: bool}
     */
    protected function validated(Request $request, ?OrderReason $reason = null): array
    {
        $type = $reason->type ?? $request->input('type');

        $data = $request->validate([
            'type' => [$reason ? 'nullable' : 'required', Rule::in(array_keys(OrderReason::TYPES))],
            'name' => ['required', 'string', 'max:150', Rule::unique('order_reasons')->where('tenant_id', tenancy()->id())->where('type', $type)->ignore($reason)],
            'is_active' => ['boolean'],
        ], ['name.unique' => 'Lý do này đã có.']);

        return [
            // The type of an existing reason never changes.
            'type' => $reason->type ?? $data['type'],
            'name' => $data['name'],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }
}
