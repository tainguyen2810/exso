<?php

namespace Modules\Shipping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Support\Branches\CurrentBranch;
use App\Support\DataTable\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Shipping\Carriers\CarrierException;
use Modules\Shipping\Carriers\CarrierManager;
use Modules\Shipping\Carriers\Quote;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\Shipment;
use Modules\Shipping\Services\OrderShippingBridge;
use Modules\Shipping\Services\ShipmentService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Quản lý vận đơn": the list with its status tabs, the shipment page,
 * creating one from an order, fees of the partners, cancelling, statuses
 * set by hand, syncing with the carrier and printing. Every shipment is
 * limited to the user's branches.
 */
class ShipmentController extends Controller
{
    public function __construct(protected ShipmentService $shipments) {}

    public function index(Request $request): Response|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        $table = DataTable::for(Shipment::query()->visibleTo($user)->with(['carrier:id,name,kind,driver', 'order:id,code', 'branch:id,name']), $request, 'shipments')
            ->search(['code', 'tracking_code', 'receiver_name', 'receiver_phone', 'order.code'])
            ->filterExact('status')
            ->filterExact('carrier_id')
            ->filterExact('branch_id')
            ->filter('cod_status', fn (Builder $query, mixed $value) => match ($value) {
                'collecting' => $query->whereIn('status', Shipment::COLLECTING)->where('cod_amount', '>', 0),
                'waiting' => $query->whereIn('status', [Shipment::DELIVERED, Shipment::RETURNED])->whereNull('cod_reconciliation_id')->whereNull('reconciled_at'),
                'reconciling' => $query->whereNotNull('cod_reconciliation_id')->whereNull('reconciled_at'),
                'reconciled' => $query->whereNotNull('reconciled_at'),
                default => null,
            })
            ->filterDateRange('created_at')
            ->filterDateRange('delivered_at')
            ->sortable(['code', 'created_at', 'delivered_at', 'cod_amount', 'fee'], '-id');

        if ($request->query('export') === 'xlsx') {
            abort_unless($user->can('shipments.export'), 403);

            return $table->export('van-don-'.now()->format('Ymd-His'), [
                'Mã đóng gói' => fn (Shipment $shipment) => $shipment->code,
                'Mã vận đơn' => fn (Shipment $shipment) => $shipment->tracking_code,
                'Mã đơn hàng' => fn (Shipment $shipment) => $shipment->order->code,
                'Ngày đóng gói' => fn (Shipment $shipment) => $shipment->created_at?->setTimezone((string) config('app.timezone')),
                'Người nhận' => fn (Shipment $shipment) => $shipment->receiver_name,
                'SĐT người nhận' => fn (Shipment $shipment) => $shipment->receiver_phone,
                'Địa chỉ' => fn (Shipment $shipment) => $shipment->fullAddress(),
                'Đối tác vận chuyển' => fn (Shipment $shipment) => $shipment->carrier->name,
                'Trạng thái giao hàng' => fn (Shipment $shipment) => Shipment::STATUSES[$shipment->status],
                'Phí trả ĐTVC' => fn (Shipment $shipment) => $shipment->fee,
                'Cần thu hộ COD' => fn (Shipment $shipment) => $shipment->cod_amount,
                'Đối soát' => fn (Shipment $shipment) => Shipment::COD_STATUSES[$shipment->codStatus()],
                'Chi nhánh' => fn (Shipment $shipment) => $shipment->branch->name,
            ]);
        }

        return Inertia::render('shipments/index', [
            'shipments' => $table->paginate(fn (Shipment $shipment): array => [
                'id' => $shipment->id,
                'code' => $shipment->code,
                'tracking_code' => $shipment->tracking_code,
                'order_id' => $shipment->order_id,
                'order_code' => $shipment->order->code,
                'status' => $shipment->status,
                'cod_status' => $shipment->codStatus(),
                'carrier' => $shipment->carrier->name,
                'receiver_name' => $shipment->receiver_name,
                'receiver_phone' => $shipment->receiver_phone,
                'address' => $shipment->fullAddress(),
                'branch' => $shipment->branch->name,
                'fee' => $shipment->fee,
                'cod_amount' => $shipment->cod_amount,
                'created_at' => $shipment->created_at?->toIso8601String(),
                'delivered_at' => $shipment->delivered_at?->toIso8601String(),
            ]),
            'carriers' => Carrier::query()->withTrashed()->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::query()->whereIn('id', $user->branchIds())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, Shipment $shipment): Response
    {
        $this->authorizeShipment($request, $shipment);
        $shipment->load(['carrier', 'branch', 'order.items', 'creator:id,name', 'reconciliation:id,code,status', 'events.creator:id,name']);
        $order = $shipment->order;

        return Inertia::render('shipments/show', [
            'shipment' => [
                ...OrderShippingBridge::summary($shipment),
                'order' => [
                    'id' => $order->id,
                    'code' => $order->code,
                    'status' => $order->status,
                    'subtotal' => $order->subtotal,
                    'discount' => $order->discount,
                    'shipping_fee' => $order->shipping_fee,
                    'total' => $order->total,
                    'items' => $order->items->map(fn (OrderItem $item): array => $item->only('id', 'name', 'sku', 'unit', 'quantity', 'price', 'discount', 'total'))->all(),
                ],
                'branch' => $shipment->branch->only('name', 'address', 'phone'),
                'creator' => $shipment->creator?->name,
                'expected_delivery_at' => $shipment->expected_delivery_at?->toIso8601String(),
                'delivered_at' => $shipment->delivered_at?->toIso8601String(),
                'synced_at' => $shipment->synced_at?->toIso8601String(),
                'reconciliation' => $shipment->reconciliation?->only('id', 'code'),
                'cod_editable' => ! $shipment->carrier->isIntegrated() && $shipment->reconciled_at === null && $shipment->cod_reconciliation_id === null && $shipment->status !== Shipment::CANCELLED,
                'next_statuses' => $shipment->carrier->isIntegrated() ? [] : Shipment::MANUAL_STEPS[$shipment->status] ?? [],
                'events' => $shipment->events->map(fn ($event): array => [
                    'id' => $event->id,
                    'status' => $event->status,
                    'note' => $event->note,
                    'creator' => $event->creator?->name,
                    'occurred_at' => $event->occurred_at->toIso8601String(),
                ])->all(),
            ],
        ]);
    }

    /**
     * "Giao hàng" on the order page: hand the order to a partner.
     */
    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $order = $this->order($request);

        $shipment = $this->shipments->create($order, $this->shipments->validate($request->except('order_id')), $user);
        $this->toast("Đã tạo phiếu giao hàng {$shipment->code}".($shipment->tracking_code ? " - mã vận đơn {$shipment->tracking_code}" : '').'.');

        return back();
    }

    /**
     * Fees of the connected carriers for a parcel ("Chọn gói cước").
     */
    public function quotes(Request $request, CarrierManager $carriers): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canAny(['shipments.create', 'orders.create', 'orders.update']), 403);

        $branchId = $request->integer('branch_id') ?: (app(CurrentBranch::class)->id($user) ?? 0);

        if ($request->filled('order_id')) {
            $branchId = $this->order($request)->branch_id;
        }

        abort_unless(in_array($branchId, $user->branchIds(), true), 403);

        $input = [...$request->except(['order_id', 'branch_id', 'carrier_id']), 'carrier_id' => 0];

        try {
            $data = $this->shipments->validate($input);
        } catch (ValidationException $exception) {
            return response()->json(['message' => collect($exception->errors())->flatten()->first(), 'quotes' => []], 422);
        }

        $quotes = [];

        foreach (Carrier::query()->where('kind', Carrier::INTEGRATED)->where('is_active', true)->orderBy('name')->get() as $carrier) {
            if (! $carriers->has((string) $carrier->driver)) {
                continue;
            }

            try {
                foreach ($this->shipments->quote($carrier, $data, $branchId) as $quote) {
                    /** @var Quote $quote */
                    $quotes[] = ['carrier_id' => $carrier->id, 'carrier' => $carrier->name, ...$quote->toArray(), 'error' => null];
                }
            } catch (ValidationException $exception) {
                $quotes[] = ['carrier_id' => $carrier->id, 'carrier' => $carrier->name, 'service_id' => null, 'service_name' => null, 'fee' => null, 'expected_at' => null, 'error' => (string) collect($exception->errors())->flatten()->first()];
            }
        }

        usort($quotes, fn (array $a, array $b): int => [$a['error'] !== null, $a['fee'] ?? PHP_INT_MAX] <=> [$b['error'] !== null, $b['fee'] ?? PHP_INT_MAX]);

        return response()->json(['quotes' => $quotes]);
    }

    public function cancel(Request $request, Shipment $shipment): RedirectResponse
    {
        $this->authorizeShipment($request, $shipment);
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $this->shipments->cancel($shipment, $user, $data['reason'] ?? null);
        $this->toast("Đã hủy phiếu giao hàng {$shipment->code}.");

        return back();
    }

    /**
     * "Tình trạng" chosen by hand (partners the store contacts itself).
     */
    public function status(Request $request, Shipment $shipment): RedirectResponse
    {
        $this->authorizeShipment($request, $shipment);
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Shipment::STATUSES))],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['status'] === Shipment::CANCELLED) {
            Gate::authorize('shipments.cancel');
        }

        $this->shipments->setStatus($shipment, $data['status'], $user, $data['note'] ?? null);
        $this->toast("Đã cập nhật phiếu giao hàng {$shipment->code}: ".Shipment::STATUSES[$data['status']].'.');

        return back();
    }

    public function sync(Request $request, Shipment $shipment): RedirectResponse
    {
        $this->authorizeShipment($request, $shipment);

        $changed = $this->shipments->sync($shipment);
        $this->toast($changed ? "Đã cập nhật trạng thái: {$this->statusName($shipment)}." : 'Trạng thái vận đơn chưa thay đổi.');

        return back();
    }

    /**
     * Note, and for a partner the store contacts itself the COD and fee
     * (before reconciliation).
     */
    public function update(Request $request, Shipment $shipment): RedirectResponse
    {
        $this->authorizeShipment($request, $shipment);
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            'cod_amount' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'fee' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'tracking_code' => ['nullable', 'string', 'max:100'],
        ]);

        $changes = ['note' => $data['note'] ?? $shipment->note];
        $money = array_filter(['cod_amount' => $data['cod_amount'] ?? null, 'fee' => $data['fee'] ?? null, 'tracking_code' => $data['tracking_code'] ?? null], fn (mixed $value): bool => $value !== null);

        if ($money !== []) {
            if ($shipment->carrier->isIntegrated() || $shipment->reconciled_at !== null || $shipment->cod_reconciliation_id !== null || $shipment->status === Shipment::CANCELLED) {
                throw ValidationException::withMessages(['cod_amount' => 'Không sửa được tiền thu hộ, phí của vận đơn này.']);
            }

            $changes += $money;
        }

        $shipment->forceFill($changes)->save();
        $this->toast("Đã lưu phiếu giao hàng {$shipment->code}.");

        return back();
    }

    /**
     * Shipping label printed by the store (any partner).
     */
    public function print(Request $request, Shipment $shipment): Response
    {
        $this->authorizeShipment($request, $shipment);
        $shipment->load(['carrier', 'branch', 'order.items']);

        return Inertia::render('shipments/print', [
            'labels' => [[
                ...OrderShippingBridge::summary($shipment),
                'order_code' => $shipment->order->code,
                'branch' => $shipment->branch->only('name', 'address', 'phone'),
                'items' => $shipment->order->items->map(fn (OrderItem $item): array => $item->only('name', 'sku', 'quantity'))->all(),
            ]],
            'storeName' => (string) settings('store.name'),
            'size' => in_array($request->query('size'), ['a5', '80'], true) ? $request->query('size') : 'a5',
        ]);
    }

    /**
     * The carrier's own label (GHN A5), opened in a new tab.
     */
    public function label(Request $request, Shipment $shipment, CarrierManager $carriers): RedirectResponse
    {
        $this->authorizeShipment($request, $shipment);

        if (! $shipment->carrier->isIntegrated() || $shipment->tracking_code === null) {
            return to_route('shipments.print', $shipment);
        }

        try {
            return redirect()->away($carriers->for($shipment->carrier)->labelUrl($shipment));
        } catch (CarrierException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return to_route('shipments.show', $shipment);
        }
    }

    /**
     * Finished shipments not reconciled yet, for "Tạo phiếu đối soát".
     */
    public function search(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('cod_reconciliations.create'), 403);
        $term = addcslashes(mb_substr($request->string('q')->trim()->toString(), 0, 100), '%_\\');

        $rows = Shipment::query()->visibleTo($user)->with('order:id,code')
            ->where('carrier_id', $request->integer('carrier_id'))
            ->where('branch_id', $request->integer('branch_id'))
            ->whereIn('status', [Shipment::DELIVERED, Shipment::RETURNED])
            ->whereNull('cod_reconciliation_id')
            ->whereNull('reconciled_at')
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('code', 'like', "%{$term}%")
                ->orWhere('tracking_code', 'like', "%{$term}%")
                ->orWhere('receiver_name', 'like', "%{$term}%")
                ->orWhere('receiver_phone', 'like', "%{$term}%")
                ->orWhereHas('order', fn (Builder $query) => $query->where('code', 'like', "%{$term}%"))))
            ->orderBy('delivered_at')
            ->orderBy('id')
            ->limit($request->boolean('all') ? 500 : 30)
            ->get();

        return response()->json($rows->map(fn (Shipment $shipment): array => [
            'id' => $shipment->id,
            'code' => $shipment->code,
            'tracking_code' => $shipment->tracking_code,
            'order_code' => $shipment->order->code,
            'status' => $shipment->status,
            'receiver_name' => $shipment->receiver_name,
            'cod' => $shipment->collectedCod(),
            'fee' => $shipment->fee,
        ])->values());
    }

    protected function order(Request $request): Order
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Order|null $order */
        $order = Order::query()->find($request->integer('order_id'));

        abort_if($order === null, 404);
        abort_unless($order->isVisibleTo($user), 403, 'Bạn không xem được đơn hàng này.');

        return $order;
    }

    protected function authorizeShipment(Request $request, Shipment $shipment): void
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($shipment->isVisibleTo($user), 403, 'Bạn không xem được phiếu giao hàng này.');
    }

    protected function statusName(Shipment $shipment): string
    {
        return Shipment::STATUSES[$shipment->status];
    }
}
