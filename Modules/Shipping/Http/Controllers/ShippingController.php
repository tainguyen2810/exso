<?php

namespace Modules\Shipping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Support\Activity\ActivityLogger;
use App\Support\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Order\Models\Order;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\Shipment;
use Modules\Shipping\Services\OrderShippingBridge;

/**
 * "Tổng quan vận chuyển" and "Cấu hình giao hàng".
 */
class ShippingController extends Controller
{
    /**
     * Delivery and reconciliation of the last 30 days, shipments and fees
     * per day of the chosen period, and the share of each partner.
     */
    public function overview(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $branchId = $request->integer('branch_id');
        $branches = $branchId > 0 && in_array($branchId, $user->branchIds(), true) ? [$branchId] : $user->branchIds();
        $carrierId = $request->integer('carrier_id') ?: null;
        $timezone = (string) config('app.timezone');
        $since = CarbonImmutable::now($timezone)->subDays(29)->startOfDay();
        $to = $this->date($request->query('to'))?->endOfDay() ?? CarbonImmutable::now($timezone)->endOfDay();
        $from = $this->date($request->query('from'))?->startOfDay() ?? $to->subDays(6)->startOfDay();
        $from = $from->greaterThan($to) ? $to->startOfDay() : $from;
        $from = $from->lessThan($to->subDays(92)) ? $to->subDays(92)->startOfDay() : $from;

        $shipments = fn (): Builder => Shipment::query()->whereIn('branch_id', $branches);
        $recent = fn (): Builder => $shipments()->where('created_at', '>=', $since);
        $states = [];

        foreach ([Shipment::PENDING, Shipment::SHIPPING, Shipment::REDELIVERY, Shipment::DELIVERED, Shipment::RETURNING, Shipment::RETURNED] as $status) {
            $states[] = [
                'status' => $status,
                'count' => (clone $recent())->where('status', $status)->count(),
                'cod' => (int) (clone $recent())->where('status', $status)->sum('cod_amount'),
            ];
        }

        // Approved orders to deliver that have no shipment yet ("Chờ đóng gói").
        $waiting = Order::query()->whereIn('branch_id', $branches)
            ->whereIn('status', [Order::APPROVED, Order::PACKED])
            ->where('delivery_method', '!=', 'pickup')
            ->whereNotIn('id', Shipment::query()->select('order_id')->whereIn('status', Shipment::ACTIVE))
            ->where('created_at', '>=', $since);

        $cod = fn (): Builder => $recent()->when($carrierId !== null, fn (Builder $query) => $query->where('carrier_id', $carrierId));
        $reconciliation = [
            ['key' => 'collecting', 'query' => (clone $cod())->whereIn('status', Shipment::COLLECTING)->where('cod_amount', '>', 0)],
            ['key' => 'waiting', 'query' => (clone $cod())->whereIn('status', [Shipment::DELIVERED, Shipment::RETURNED])->whereNull('reconciled_at')],
            ['key' => 'reconciled', 'query' => (clone $cod())->whereNotNull('reconciled_at')],
        ];

        $period = $shipments()->with('carrier:id,name')->whereBetween('created_at', [$from->utc(), $to->utc()])->get(['id', 'carrier_id', 'status', 'cod_amount', 'fee', 'created_at']);
        $days = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $days[$day->toDateString()] = ['label' => $day->format('d/m'), 'count' => 0, 'fee' => 0];
        }

        foreach ($period as $shipment) {
            $key = $shipment->created_at?->setTimezone($timezone)->toDateString();

            if ($key !== null && isset($days[$key])) {
                $days[$key]['count']++;
                $days[$key]['fee'] += $shipment->fee;
            }
        }

        $partners = $period->groupBy('carrier_id')->map(fn ($rows): array => [
            'carrier' => $rows->first()->carrier->name,
            'count' => $rows->count(),
            'fee' => (int) $rows->sum('fee'),
            'cod' => (int) $rows->sum('cod_amount'),
            'delivered' => $rows->where('status', Shipment::DELIVERED)->count(),
            'failed' => $rows->whereIn('status', [Shipment::RETURNING, Shipment::RETURNED])->count(),
        ])->sortByDesc('count')->values()->all();

        $late = (int) settings('shipping.late_pick_days');
        $lateDelivery = (int) settings('shipping.late_delivery_days');

        return Inertia::render('shipping/overview', [
            'states' => $states,
            'waiting' => ['count' => (clone $waiting)->count(), 'total' => (int) (clone $waiting)->sum('total')],
            'late' => [
                'pick' => $late > 0 ? $shipments()->where('status', Shipment::PENDING)->where('created_at', '<', now()->subDays($late))->count() : 0,
                'delivery' => $lateDelivery > 0 ? $shipments()->whereIn('status', [Shipment::SHIPPING, Shipment::REDELIVERY])->where('created_at', '<', now()->subDays($lateDelivery))->count() : 0,
                'pick_days' => $late,
                'delivery_days' => $lateDelivery,
            ],
            'reconciliation' => array_map(fn (array $row): array => [
                'key' => $row['key'],
                'count' => (clone $row['query'])->count(),
                'cod' => (int) (clone $row['query'])->sum('cod_amount'),
                'fee' => (int) (clone $row['query'])->sum('fee'),
            ], $reconciliation),
            'chart' => array_values($days),
            'partners' => $partners,
            'filters' => [
                'branch_id' => count($branches) === 1 && $branchId > 0 ? $branchId : null,
                'carrier_id' => $carrierId,
                'from' => $from->format('d/m/Y'),
                'to' => $to->format('d/m/Y'),
            ],
            'branches' => Branch::query()->whereIn('id', $user->branchIds())->orderBy('name')->get(['id', 'name']),
            'carriers' => Carrier::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function settings(): Response
    {
        return Inertia::render('shipping/settings', [
            'settings' => [
                'weight_mode' => settings('shipping.weight_mode'),
                'default_weight' => (int) settings('shipping.default_weight'),
                'default_length' => (int) settings('shipping.default_length'),
                'default_width' => (int) settings('shipping.default_width'),
                'default_height' => (int) settings('shipping.default_height'),
                'required_note' => settings('shipping.required_note'),
                'note' => settings('shipping.note') ?? '',
                'auto_export' => (bool) settings('shipping.auto_export'),
                'auto_receive_return' => (bool) settings('shipping.auto_receive_return'),
                'late_pick_days' => (int) settings('shipping.late_pick_days'),
                'late_delivery_days' => (int) settings('shipping.late_delivery_days'),
            ],
            'requiredNotes' => OrderShippingBridge::choices(Shipment::REQUIRED_NOTES),
        ]);
    }

    public function saveSettings(Request $request, SettingsRepository $settings, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate([
            'weight_mode' => ['required', Rule::in(['product', 'custom'])],
            'default_weight' => ['required', 'integer', 'min:1', 'max:1000000'],
            'default_length' => ['required', 'integer', 'min:1', 'max:1000'],
            'default_width' => ['required', 'integer', 'min:1', 'max:1000'],
            'default_height' => ['required', 'integer', 'min:1', 'max:1000'],
            'required_note' => ['required', Rule::in(array_keys(Shipment::REQUIRED_NOTES))],
            'note' => ['nullable', 'string', 'max:500'],
            'auto_export' => ['required', 'boolean'],
            'auto_receive_return' => ['required', 'boolean'],
            'late_pick_days' => ['required', 'integer', 'min:0', 'max:60'],
            'late_delivery_days' => ['required', 'integer', 'min:0', 'max:60'],
        ], [], ['default_weight' => 'khối lượng', 'required_note' => 'yêu cầu khi giao']);

        $values = [];

        foreach ($data as $key => $value) {
            $values["shipping.{$key}"] = is_bool($value) ? $value : (is_numeric($value) && $key !== 'note' ? (int) $value : $value);
        }

        $old = [];

        foreach ($values as $key => $value) {
            if ($settings->get($key) !== $value) {
                $old[$key] = $settings->get($key);
            }
        }

        $settings->setMany($values);

        if ($old !== []) {
            $activity->log('updated', 'Sửa cấu hình giao hàng', null, ['old' => $old, 'new' => array_intersect_key($values, $old)]);
        }

        $this->toast('Đã lưu cấu hình giao hàng.');

        return back();
    }

    protected function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('#^(\d{4}-\d{2}-\d{2}|\d{1,2}/\d{1,2}/\d{4})$#', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat(str_contains($value, '/') ? 'd/m/Y' : 'Y-m-d', $value, (string) config('app.timezone')) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
