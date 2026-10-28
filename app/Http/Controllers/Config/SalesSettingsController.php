<?php

namespace App\Http\Controllers\Config;

use App\Http\Controllers\Controller;
use App\Support\Activity\ActivityLogger;
use App\Support\Settings\SettingsRepository;
use App\Support\Settings\StoreSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Cấu hình bán hàng": on/off rules for selling, the counter, online
 * orders, returns and "Quản lý khác".
 */
class SalesSettingsController extends Controller
{
    public function show(SettingsRepository $settings): Response
    {
        $groups = [];

        foreach (StoreSettings::SALES_TOGGLES as $group => $toggles) {
            $items = [];

            foreach ($toggles as $key => [$label, $description]) {
                $items[] = ['key' => $key, 'label' => $label, 'description' => $description];
            }

            $groups[] = ['label' => $group, 'items' => $items];
        }

        return Inertia::render('config/sales', [
            'groups' => $groups,
            'values' => [
                ...collect(self::keys())->mapWithKeys(fn (string $key): array => [$key => (bool) $settings->get($key)])->all(),
                'sales.print_copies' => (int) $settings->get('sales.print_copies'),
            ],
        ]);
    }

    public function update(Request $request, SettingsRepository $settings, ActivityLogger $activity): RedirectResponse
    {
        // Keys are sent with "_" for "." (sales_print_copies), since Laravel
        // reads dots in input names as nesting.
        $rules = ['sales_print_copies' => ['required', 'integer', 'min:1', 'max:3']];

        foreach (self::keys() as $key) {
            $rules[str_replace('.', '_', $key)] = ['required', 'boolean'];
        }

        $validated = $request->validate($rules);
        $data = [];

        foreach ([...self::keys(), 'sales.print_copies'] as $key) {
            $data[$key] = $validated[str_replace('.', '_', $key)];
        }

        $values = [];
        $old = [];
        $new = [];

        foreach (array_keys($data) as $key) {
            $value = $key === 'sales.print_copies' ? (int) $data[$key] : (bool) $data[$key];
            $values[$key] = $value;

            if ($settings->get($key) !== $value) {
                $old[$key] = $settings->get($key);
                $new[$key] = $value;
            }
        }

        $settings->setMany($values);

        if ($new !== []) {
            $activity->log('updated', 'Sửa cấu hình bán hàng', null, ['old' => $old, 'new' => $new]);
        }

        $this->toast('Đã lưu cấu hình bán hàng.');

        return back();
    }

    /**
     * @return list<string>
     */
    protected static function keys(): array
    {
        return array_merge(...array_map(array_keys(...), array_values(StoreSettings::SALES_TOGGLES)));
    }
}
