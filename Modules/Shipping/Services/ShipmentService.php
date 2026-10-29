<?php

namespace Modules\Shipping\Services;

use App\Models\User;
use App\Support\Sequences\Sequence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Services\OrderService;
use Modules\Shipping\Carriers\CarrierException;
use Modules\Shipping\Carriers\CarrierManager;
use Modules\Shipping\Carriers\Quote;
use Modules\Shipping\Carriers\ShipmentRequest;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\Shipment;
use Modules\Shipping\Models\ShipmentEvent;

/**
 * Everything that changes a shipment, and what it does to its order:
 *
 *  - create: the order is packed and, for an integrated carrier, the
 *    delivery request is sent (in the same transaction, so a refused
 *    request leaves nothing behind)
 *  - the partner picks the parcel up → the order is exported ("Xuất kho")
 *  - delivered → the order is completed (the COD waits for "đối soát")
 *  - failed and coming back → "Hủy giao - chờ nhận"; back at the store →
 *    goods return to stock (automatically when the store chose so)
 *  - cancelled before pick-up ("Hủy đóng gói") → the order is approved again
 *
 * The other way round, steps done on the order page (Xuất kho, Giao hàng
 * thành công, Nhận lại hàng, Hủy đơn) move its shipment: followOrder().
 *
 * @phpstan-type ShipmentInput array{carrier_id: int, service_id?: string|null, service_name?: string|null, fee?: int, cod_amount: int, insurance_value?: int, fee_payer?: string, weight: int, length?: int, width?: int, height?: int, receiver_name: string, receiver_phone: string, address: string, ward?: string|null, district?: string|null, province?: string|null, required_note?: string|null, note?: string|null, tracking_code?: string|null}
 */
class ShipmentService
{
    public function __construct(
        protected OrderService $orders,
        protected CarrierManager $carriers,
    ) {}

    /**
     * Validate the shipment fields of the order form or the "Giao hàng" dialog.
     *
     * @param  array<string, mixed>  $input
     * @return ShipmentInput
     */
    public function validate(array $input): array
    {
        $money = ['integer', 'min:0', 'max:999999999999'];
        $size = ['nullable', 'integer', 'min:0', 'max:1000'];

        /** @var ShipmentInput $data */
        $data = Validator::make($input, [
            'carrier_id' => ['required', 'integer'],
            'service_id' => ['nullable', 'string', 'max:50'],
            'service_name' => ['nullable', 'string', 'max:100'],
            'fee' => ['nullable', ...$money],
            'cod_amount' => ['required', ...$money],
            'insurance_value' => ['nullable', ...$money],
            'fee_payer' => ['nullable', Rule::in(array_keys(Shipment::FEE_PAYERS))],
            'weight' => ['required', 'integer', 'min:1', 'max:1000000'],
            'length' => $size,
            'width' => $size,
            'height' => $size,
            'receiver_name' => ['required', 'string', 'max:255'],
            'receiver_phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+ .()-]{8,20}$/'],
            'address' => ['required', 'string', 'max:500'],
            'ward' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'required_note' => ['nullable', Rule::in(array_keys(Shipment::REQUIRED_NOTES))],
            'note' => ['nullable', 'string', 'max:500'],
            'tracking_code' => ['nullable', 'string', 'max:100'],
        ], [], [
            'carrier_id' => 'đối tác vận chuyển',
            'cod_amount' => 'tiền thu hộ',
            'weight' => 'khối lượng',
            'receiver_name' => 'tên người nhận',
            'receiver_phone' => 'số điện thoại người nhận',
            'address' => 'địa chỉ giao hàng',
        ])->validate();

        return $data;
    }

    /**
     * Fees of every service of an integrated carrier for a parcel.
     *
     * @param  ShipmentInput  $data
     * @return list<Quote>
     */
    public function quote(Carrier $carrier, array $data, int $branchId): array
    {
        return $this->carrier(fn () => $this->carriers->for($carrier)->quote($this->request($data, $branchId, 'QUOTE')));
    }

    /**
     * Hand the order to a partner: "Đẩy qua hãng vận chuyển" (integrated)
     * or "Đẩy vận chuyển ngoài" (a partner the store contacts itself).
     *
     * @param  ShipmentInput  $data
     */
    public function create(Order $order, array $data, User $user): Shipment
    {
        $carrier = Carrier::query()->where('is_active', true)->find($data['carrier_id'])
            ?? throw ValidationException::withMessages(['carrier_id' => 'Đối tác vận chuyển không tồn tại hoặc đã ngừng hoạt động.']);

        if ($carrier->isIntegrated() && ! $this->carriers->has((string) $carrier->driver)) {
            throw ValidationException::withMessages(['carrier_id' => 'Đối tác này chưa được hỗ trợ kết nối.']);
        }

        return DB::transaction(function () use ($order, $data, $user, $carrier): Shipment {
            /** @var Order $locked */
            $locked = Order::query()->with('items.variant')->lockForUpdate()->findOrFail($order->id);

            if (! in_array($locked->status, [Order::APPROVED, Order::PACKED], true)) {
                throw ValidationException::withMessages(['status' => $locked->status === Order::PENDING
                    ? 'Đơn hàng chưa được duyệt.'
                    : 'Chỉ tạo phiếu giao hàng cho đơn đã duyệt, chưa xuất kho.']);
            }

            if (Shipment::query()->where('order_id', $locked->id)->whereIn('status', Shipment::ACTIVE)->exists()) {
                throw ValidationException::withMessages(['status' => 'Đơn hàng đang có phiếu giao hàng chưa hoàn tất.']);
            }

            $feePayer = $data['fee_payer'] ?? 'shop';
            /** @var Shipment $shipment */
            $shipment = Shipment::query()->create([
                'branch_id' => $locked->branch_id,
                'order_id' => $locked->id,
                'carrier_id' => $carrier->id,
                'code' => Sequence::next('FUN', Shipment::class),
                'tracking_code' => $carrier->isIntegrated() ? null : $this->text($data['tracking_code'] ?? null),
                'status' => Shipment::PENDING,
                'service_id' => $data['service_id'] ?? null,
                'service_name' => $data['service_name'] ?? null,
                'cod_amount' => (int) $data['cod_amount'],
                'fee' => $carrier->isIntegrated() || $feePayer === 'receiver' ? 0 : (int) ($data['fee'] ?? 0),
                'fee_payer' => $feePayer,
                'insurance_value' => (int) ($data['insurance_value'] ?? 0),
                'weight' => (int) $data['weight'],
                'length' => (int) ($data['length'] ?? 0),
                'width' => (int) ($data['width'] ?? 0),
                'height' => (int) ($data['height'] ?? 0),
                'receiver_name' => trim($data['receiver_name']),
                'receiver_phone' => trim($data['receiver_phone']),
                'address' => trim($data['address']),
                'ward' => $this->text($data['ward'] ?? null),
                'district' => $this->text($data['district'] ?? null),
                'province' => $this->text($data['province'] ?? null),
                'required_note' => $data['required_note'] ?? settings('shipping.required_note'),
                'note' => $this->text($data['note'] ?? null),
                'created_by' => $user->id,
            ]);

            if ($carrier->isIntegrated()) {
                // Sent last: if the carrier refuses, the transaction rolls back.
                $created = $this->carrier(fn () => $this->carriers->for($carrier)->create(
                    $this->request($data, $locked->branch_id, $shipment->code, $this->items($locked)),
                ));
                $shipment->forceFill([
                    'tracking_code' => $created->trackingCode,
                    'carrier_status' => $created->carrierStatus,
                    'fee' => $feePayer === 'receiver' ? 0 : $created->fee,
                    'expected_delivery_at' => $created->expectedAt,
                ])->save();
            }

            $this->event($shipment, Shipment::PENDING, $shipment->carrier_status, 'Tạo phiếu giao hàng', now(), $user->id);
            $this->orders->assignShipment($locked, $carrier->isIntegrated() ? 'carrier' : 'external', $carrier->name, $shipment->tracking_code, $shipment->code);
            $order->setRawAttributes(Order::query()->findOrFail($order->id)->getAttributes(), true);

            return $shipment;
        });
    }

    /**
     * Hủy đóng gói: only before the partner picks the parcel up. An
     * integrated carrier is asked to cancel first.
     */
    public function cancel(Shipment $shipment, User $user, ?string $reason = null): void
    {
        if ($shipment->status !== Shipment::PENDING) {
            throw ValidationException::withMessages(['status' => 'Chỉ hủy được phiếu giao hàng đang chờ lấy hàng.']);
        }

        $carrier = $shipment->carrier;

        if ($carrier->isIntegrated() && $shipment->tracking_code !== null) {
            $this->carrier(fn () => $this->carriers->for($carrier)->cancel($shipment));
        }

        $this->apply($shipment, Shipment::CANCELLED, $carrier->isIntegrated() ? 'cancel' : null, implode(' - ', array_filter(['Hủy giao hàng', $reason])), userId: $user->id);
    }

    /**
     * Status set by staff ("Tình trạng") for a partner the store contacts itself.
     */
    public function setStatus(Shipment $shipment, string $status, User $user, ?string $note = null): void
    {
        if ($shipment->carrier->isIntegrated()) {
            throw ValidationException::withMessages(['status' => 'Trạng thái của đối tác tích hợp được cập nhật tự động. Hãy bấm Đồng bộ trạng thái.']);
        }

        if ($status === Shipment::CANCELLED) {
            $this->cancel($shipment, $user, $note);

            return;
        }

        if (! in_array($status, Shipment::MANUAL_STEPS[$shipment->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'Không chuyển được từ "'.Shipment::STATUSES[$shipment->status].'" sang "'.(Shipment::STATUSES[$status] ?? $status).'".']);
        }

        $this->apply($shipment, $status, null, $note, userId: $user->id);
    }

    /**
     * Ask the carrier where the parcel is and follow it. Returns whether
     * the status changed.
     */
    public function sync(Shipment $shipment): bool
    {
        $carrier = $shipment->carrier;

        if (! $carrier->isIntegrated() || $shipment->tracking_code === null || ! $shipment->isActive()) {
            return false;
        }

        $driver = $this->carriers->for($carrier);
        $tracking = $this->carrier(fn () => $driver->track($shipment));
        $before = $shipment->status;
        $known = ShipmentEvent::query()->where('shipment_id', $shipment->id)->whereNotNull('carrier_status')
            ->get(['carrier_status', 'occurred_at'])
            ->map(fn (ShipmentEvent $event): string => $event->carrier_status.'|'.$event->occurred_at->getTimestamp())
            ->all();
        $logged = false;

        foreach ($tracking->events as $step) {
            $key = $step['carrier_status'].'|'.$step['occurred_at']->getTimestamp();

            if (in_array($key, $known, true)) {
                $logged = $logged || $step['carrier_status'] === $tracking->carrierStatus;

                continue;
            }

            $this->event($shipment, $driver->status($step['carrier_status']), $step['carrier_status'], $step['note'] ?? $driver->statusLabel($step['carrier_status']), $step['occurred_at']);
            $known[] = $key;
            $logged = $logged || $step['carrier_status'] === $tracking->carrierStatus;
        }

        if ($tracking->fee !== null && $shipment->fee_payer === 'shop' && $shipment->reconciled_at === null) {
            $shipment->forceFill(['fee' => $tracking->fee])->save();
        }

        $status = $driver->status($tracking->carrierStatus) ?? $shipment->status;
        $this->apply($shipment, $status, $tracking->carrierStatus, $driver->statusLabel($tracking->carrierStatus), record: ! $logged);
        $shipment->forceFill(['synced_at' => now()])->save();

        return $shipment->status !== $before;
    }

    /**
     * Move the shipment to $status (and its order with it, unless the change
     * came from the order). Running it again with the same status does nothing.
     */
    public function apply(Shipment $shipment, string $status, ?string $carrierStatus = null, ?string $note = null, ?CarbonImmutable $at = null, bool $fromOrder = false, bool $record = true, ?int $userId = null): void
    {
        DB::transaction(function () use ($shipment, $status, $carrierStatus, $note, $at, $fromOrder, $record, $userId): void {
            /** @var Shipment $locked */
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            $changed = $locked->status !== $status;
            $carrierChanged = $carrierStatus !== null && $carrierStatus !== $locked->carrier_status;

            if (! $changed && ! $carrierChanged) {
                return;
            }

            // A finished parcel does not move again (late webhooks).
            if ($changed && ! $locked->isActive()) {
                return;
            }

            $at ??= CarbonImmutable::now();
            $locked->forceFill([
                'status' => $status,
                'carrier_status' => $carrierStatus ?? $locked->carrier_status,
                'picked_at' => $locked->picked_at ?? (in_array($status, [Shipment::SHIPPING, Shipment::DELIVERED], true) ? $at : null),
                'delivered_at' => $status === Shipment::DELIVERED ? $at : $locked->delivered_at,
                'returned_at' => $status === Shipment::RETURNED ? $at : $locked->returned_at,
                'cancelled_at' => $status === Shipment::CANCELLED ? $at : $locked->cancelled_at,
            ])->save();

            if ($record) {
                $this->event($locked, $status, $carrierStatus, $note ?? ($changed ? Shipment::STATUSES[$status] : null), $at, $userId ?? $this->userId());
            }

            if ($changed && ! $fromOrder) {
                $this->moveOrder($locked, $status);
            }

            $shipment->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * A step done on the order page moves its open shipment.
     */
    public function followOrder(Order $order, string $action): void
    {
        /** @var Shipment|null $shipment */
        $shipment = Shipment::query()->with('carrier')->where('order_id', $order->id)->whereIn('status', Shipment::ACTIVE)->latest('id')->first();

        if ($shipment === null) {
            return;
        }

        match ($action) {
            'shipped' => ! $shipment->carrier->isIntegrated() && $shipment->status === Shipment::PENDING
                ? $this->apply($shipment, Shipment::SHIPPING, note: 'Xuất kho, giao cho đối tác', fromOrder: true)
                : null,
            'delivered' => $this->apply($shipment, Shipment::DELIVERED, note: 'Giao hàng thành công', fromOrder: true),
            'delivery_failed' => $this->apply($shipment, Shipment::RETURNING, note: 'Giao hàng thất bại', fromOrder: true),
            'received_back' => $this->apply($shipment, Shipment::RETURNED, note: 'Đã nhận lại hàng', fromOrder: true),
            'cancelled' => $this->orderCancelled($shipment),
            default => null,
        };
    }

    /**
     * What the order form and the "Giao hàng" dialog start with: receiver,
     * address split into its parts, COD = what is left to pay, weight of
     * the products (or the store's default).
     *
     * @return array<string, mixed>
     */
    public function defaults(Order $order): array
    {
        $order->loadMissing('items.variant');
        $parts = array_map('trim', explode(',', (string) $order->shipping_address));
        $count = count($parts);
        $weight = 0;

        foreach ($order->items as $item) {
            $weight += ($item->variant->weight ?? 0) * $item->quantity;
        }

        return [
            'receiver_name' => $order->receiver_name ?? $order->customer_name ?? '',
            'receiver_phone' => $order->receiver_phone ?? $order->customer_phone ?? '',
            // "street, ward, district, province" or "street, district, province".
            'address' => match (true) {
                $count >= 4 => implode(', ', array_slice($parts, 0, $count - 3)),
                $count === 3 => $parts[0],
                default => (string) $order->shipping_address,
            },
            'ward' => $count >= 4 ? $parts[$count - 3] : '',
            'district' => $count >= 3 ? $parts[$count - 2] : '',
            'province' => $count >= 3 ? $parts[$count - 1] : '',
            'cod_amount' => max(0, $order->remaining()),
            'weight' => settings('shipping.weight_mode') === 'custom' || $weight <= 0 ? (int) settings('shipping.default_weight') : $weight,
            ...self::packageDefaults(),
        ];
    }

    /**
     * @return array{length: int, width: int, height: int, required_note: string, note: string, fee_payer: string}
     */
    public static function packageDefaults(): array
    {
        return [
            'length' => (int) settings('shipping.default_length'),
            'width' => (int) settings('shipping.default_width'),
            'height' => (int) settings('shipping.default_height'),
            'required_note' => (string) settings('shipping.required_note'),
            'note' => (string) settings('shipping.note'),
            'fee_payer' => 'shop',
        ];
    }

    protected function orderCancelled(Shipment $shipment): void
    {
        if ($shipment->status !== Shipment::PENDING) {
            $this->event($shipment, null, null, 'Đơn hàng đã hủy khi hàng đang ở đối tác vận chuyển: hãy liên hệ đối tác để nhận lại hàng.', now(), $this->userId());

            return;
        }

        if ($shipment->carrier->isIntegrated() && $shipment->tracking_code !== null) {
            try {
                $this->carriers->for($shipment->carrier)->cancel($shipment);
            } catch (CarrierException $exception) {
                Log::warning('Không hủy được vận đơn khi hủy đơn hàng', ['shipment' => $shipment->code, 'error' => $exception->getMessage()]);
                $this->event($shipment, null, null, 'Không hủy được ở đối tác: '.$exception->getMessage(), now(), $this->userId());
            }
        }

        $this->apply($shipment, Shipment::CANCELLED, note: 'Hủy đơn hàng', fromOrder: true);
    }

    /**
     * The order follows its shipment. A step the order refuses (e.g. not
     * enough stock to export) is noted on the shipment instead of failing
     * the carrier's update.
     */
    protected function moveOrder(Shipment $shipment, string $status): void
    {
        /** @var Order $order */
        $order = Order::query()->findOrFail($shipment->order_id);

        try {
            if (in_array($status, [Shipment::SHIPPING, Shipment::REDELIVERY, Shipment::DELIVERED], true) && $order->holdsStock() && settings('shipping.auto_export')) {
                $this->orders->export($order);
                $order->refresh();
            }

            match ($status) {
                Shipment::DELIVERED => $order->status === Order::SHIPPED ? $this->orders->markDelivered($order) : null,
                Shipment::RETURNING => $order->status === Order::SHIPPED && $order->delivery_status === 'shipping'
                    ? $this->orders->markDeliveryFailed($order, "Phiếu giao hàng {$shipment->code}")
                    : null,
                Shipment::RETURNED => settings('shipping.auto_receive_return') && $order->status === Order::SHIPPED && $order->delivery_status === 'returning'
                    ? $this->orders->receiveBack($order)
                    : null,
                Shipment::CANCELLED => $order->holdsStock() ? $this->orders->cancelShipment($order, $shipment->code) : null,
                default => null,
            };
        } catch (ValidationException $exception) {
            $this->event($shipment, null, null, 'Không cập nhật được đơn hàng: '.collect($exception->errors())->flatten()->first(), now(), $this->userId());
        }
    }

    /**
     * @param  ShipmentInput  $data
     * @param  list<array{name: string, code: string|null, quantity: int, price: int, weight: int}>  $items
     */
    protected function request(array $data, int $branchId, string $code, array $items = []): ShipmentRequest
    {
        return new ShipmentRequest(
            clientCode: $code,
            branchId: $branchId,
            receiverName: trim($data['receiver_name']),
            receiverPhone: trim($data['receiver_phone']),
            address: trim($data['address']),
            ward: $this->text($data['ward'] ?? null),
            district: $this->text($data['district'] ?? null),
            province: $this->text($data['province'] ?? null),
            weight: (int) $data['weight'],
            length: (int) ($data['length'] ?? 0),
            width: (int) ($data['width'] ?? 0),
            height: (int) ($data['height'] ?? 0),
            codAmount: (int) $data['cod_amount'],
            insuranceValue: (int) ($data['insurance_value'] ?? 0),
            feePayer: $data['fee_payer'] ?? 'shop',
            serviceId: $data['service_id'] ?? null,
            requiredNote: $data['required_note'] ?? (string) settings('shipping.required_note'),
            note: $this->text($data['note'] ?? null),
            items: $items,
        );
    }

    /**
     * @return list<array{name: string, code: string|null, quantity: int, price: int, weight: int}>
     */
    protected function items(Order $order): array
    {
        return array_values($order->items->map(fn (OrderItem $item): array => [
            'name' => $item->name,
            'code' => $item->sku,
            'quantity' => $item->quantity,
            'price' => $item->price,
            'weight' => $item->variant->weight ?? 0,
        ])->all());
    }

    protected function userId(): ?int
    {
        $id = auth()->id();

        return $id === null ? null : (int) $id;
    }

    protected function event(Shipment $shipment, ?string $status, ?string $carrierStatus, ?string $note, CarbonImmutable $at, ?int $userId = null): void
    {
        ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'status' => $status,
            'carrier_status' => $carrierStatus,
            'note' => $note === null ? null : mb_substr($note, 0, 500),
            'occurred_at' => $at->setTimezone((string) config('app.timezone')),
            'created_by' => $userId,
        ]);
    }

    /**
     * Run a carrier call; its refusal becomes a form error.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    protected function carrier(callable $call): mixed
    {
        try {
            return $call();
        } catch (CarrierException $exception) {
            throw ValidationException::withMessages(['carrier' => $exception->getMessage()]);
        }
    }

    protected function text(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
