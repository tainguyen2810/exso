<?php

namespace Modules\Shipping\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Order\Contracts\OrderShipping;
use Modules\Order\Models\Order;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\Shipment;
use Modules\Shipping\Models\ShipmentEvent;

/**
 * Orders get their shipping partners and shipments from here
 * (Modules\Order\Contracts\OrderShipping).
 */
class OrderShippingBridge implements OrderShipping
{
    public function __construct(protected ShipmentService $shipments) {}

    public function options(User $user): array
    {
        $carriers = Carrier::query()->where('is_active', true)->orderBy('name')->get(['id', 'kind', 'driver', 'name']);

        return [
            'enabled' => true,
            'canCreate' => $user->can('shipments.create'),
            'carriers' => $carriers->where('kind', Carrier::INTEGRATED)->map(fn (Carrier $carrier): array => $carrier->only('id', 'name', 'driver'))->values()->all(),
            'partners' => $carriers->where('kind', Carrier::MANUAL)->map(fn (Carrier $carrier): array => $carrier->only('id', 'name'))->values()->all(),
            'requiredNotes' => self::choices(Shipment::REQUIRED_NOTES),
            'feePayers' => self::choices(Shipment::FEE_PAYERS),
            'package' => [...ShipmentService::packageDefaults(), 'weight' => (int) settings('shipping.default_weight')],
        ];
    }

    public function dispatch(Order $order, array $input, User $user): ?string
    {
        if (! $user->can('shipments.create')) {
            return 'Bạn không có quyền tạo phiếu giao hàng.';
        }

        try {
            $this->shipments->create($order, $this->shipments->validate($input), $user);
        } catch (ValidationException $exception) {
            return (string) collect($exception->errors())->flatten()->first();
        }

        return null;
    }

    public function panel(Order $order, User $user): array
    {
        $shipments = Shipment::query()->with(['carrier', 'events' => fn ($query) => $query->limit(30)])
            ->where('order_id', $order->id)
            ->latest('id')
            ->get();

        return [
            'shipments' => array_values($shipments->map(fn (Shipment $shipment): array => self::summary($shipment))->all()),
            'defaults' => in_array($order->status, [Order::APPROVED, Order::PACKED], true) && $user->can('shipments.create')
                ? $this->shipments->defaults($order)
                : null,
        ];
    }

    /**
     * A shipment as the order page and the shipment list show it.
     *
     * @return array<string, mixed>
     */
    public static function summary(Shipment $shipment): array
    {
        $carrier = $shipment->carrier;

        return [
            'id' => $shipment->id,
            'code' => $shipment->code,
            'tracking_code' => $shipment->tracking_code,
            'status' => $shipment->status,
            'carrier' => $carrier->name,
            'integrated' => $carrier->isIntegrated(),
            'carrier_status' => $shipment->carrier_status === null ? null : CarrierStatus::label($carrier, $shipment->carrier_status),
            'service_name' => $shipment->service_name,
            'cod_amount' => $shipment->cod_amount,
            'fee' => $shipment->fee,
            'fee_payer' => Shipment::FEE_PAYERS[$shipment->fee_payer] ?? $shipment->fee_payer,
            'weight' => $shipment->weight,
            'size' => $shipment->length > 0 ? "{$shipment->length} x {$shipment->width} x {$shipment->height} cm" : null,
            'required_note' => $shipment->required_note === null ? null : (Shipment::REQUIRED_NOTES[$shipment->required_note] ?? $shipment->required_note),
            'note' => $shipment->note,
            'cod_status' => $shipment->codStatus(),
            'receiver_name' => $shipment->receiver_name,
            'receiver_phone' => $shipment->receiver_phone,
            'address' => $shipment->fullAddress(),
            'created_at' => $shipment->created_at?->toIso8601String(),
            'events' => $shipment->relationLoaded('events') ? array_values($shipment->events->map(fn (ShipmentEvent $event): array => [
                'id' => $event->id,
                'status' => $event->status,
                'note' => $event->note,
                'occurred_at' => $event->occurred_at->toIso8601String(),
            ])->all()) : [],
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    public static function choices(array $labels): array
    {
        return array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys($labels), $labels);
    }
}
