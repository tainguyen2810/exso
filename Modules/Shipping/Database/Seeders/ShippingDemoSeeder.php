<?php

namespace Modules\Shipping\Database\Seeders;

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Sequences\Sequence;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Modules\Customer\Models\Customer;
use Modules\Order\Services\OrderService;
use Modules\Product\Models\ProductVariant;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\Shipment;
use Modules\Shipping\Services\CodReconciliationService;
use Modules\Shipping\Services\ShipmentService;

/**
 * Demo partners the store contacts itself, shipments at every status and
 * COD reconciliations (run inside the store's tenant, after the order
 * demo). GHN is left to connect with a real token.
 */
class ShippingDemoSeeder extends Seeder
{
    public function run(OrderService $orders, ShipmentService $shipments, CodReconciliationService $reconciliations): void
    {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        Auth::setUser($owner);
        $main = (int) Branch::query()->where('is_default', true)->value('id');
        $transfer = (int) (PaymentMethod::query()->where('type', 'bank')->value('id') ?? PaymentMethod::query()->value('id'));

        $partner = fn (string $name, string $phone): Carrier => Carrier::query()->firstOrCreate(['name' => $name], [
            'kind' => Carrier::MANUAL,
            'code' => Sequence::next('DTVC', Carrier::class),
            'phone' => $phone,
            'created_by' => $owner->id,
        ]);
        $ahamove = $partner('Ahamove (tự liên hệ)', '1900545411');
        $shipper = $partner('Shipper Minh', '0909111222');

        $ship = function (Carrier $carrier, string $phone, string $sku, int $quantity, string $tracking, int $fee, array $steps = []) use ($orders, $shipments, $owner, $main): Shipment {
            $customer = Customer::query()->where('phone', $phone)->firstOrFail();
            $address = $customer->addresses()->first();
            $order = $orders->create([
                'branch_id' => $main,
                'items' => $this->lines([[$sku, $quantity]]),
                'customer_id' => $customer->id,
                'delivery_method' => 'external',
                'receiver_name' => $customer->name,
                'receiver_phone' => $customer->phone,
                'shipping_address' => implode(', ', array_filter([$address?->address, $address?->district, $address?->province])),
                'shipping_fee' => 30000,
            ], $owner, 'approve');

            $shipment = $shipments->create($order, $shipments->validate([
                ...$shipments->defaults($order),
                'carrier_id' => $carrier->id,
                'tracking_code' => $tracking,
                'fee' => $fee,
            ]), $owner);

            foreach ($steps as $status) {
                $shipments->setStatus($shipment, $status, $owner);
            }

            return $shipment->refresh();
        };

        $ship($ahamove, '0901234567', 'LOGI-M331', 1, 'AHA2611001', 25000);
        $ship($shipper, '0912345678', 'UG-HDMI2', 2, 'SM-0012', 20000, [Shipment::SHIPPING]);
        $ship($ahamove, '0987654321', 'KEY-K2', 1, 'AHA2611002', 32000, [Shipment::SHIPPING, Shipment::REDELIVERY]);
        $ship($shipper, '0933111222', 'DA-A4-70', 3, 'SM-0013', 20000, [Shipment::SHIPPING, Shipment::RETURNING]);
        $returned = $ship($ahamove, '0901234567', 'UG-HDMI2', 1, 'AHA2611003', 25000, [Shipment::SHIPPING, Shipment::RETURNING, Shipment::RETURNED]);

        // Delivered: two already reconciled and paid, two waiting.
        $done = [
            $ship($ahamove, '0912345678', 'LOGI-M331', 2, 'AHA2611004', 28000, [Shipment::SHIPPING, Shipment::DELIVERED]),
            $ship($ahamove, '0987654321', 'UG-HDMI2', 3, 'AHA2611005', 30000, [Shipment::SHIPPING, Shipment::DELIVERED]),
        ];
        $ship($ahamove, '0933111222', 'KEY-K2', 1, 'AHA2611006', 35000, [Shipment::SHIPPING, Shipment::DELIVERED]);
        $waiting = $ship($shipper, '0901234567', 'DA-A4-70', 2, 'SM-0014', 20000, [Shipment::SHIPPING, Shipment::DELIVERED]);

        $paid = $reconciliations->create([
            'carrier_id' => $ahamove->id,
            'branch_id' => $main,
            'note' => 'Ahamove chuyển khoản tuần 1',
            'items' => [
                ['shipment_id' => $done[0]->id, 'partner_cod' => null, 'partner_fee' => null],
                ['shipment_id' => $done[1]->id, 'partner_cod' => null, 'partner_fee' => $done[1]->fee + 2000],
                ['shipment_id' => $returned->id, 'partner_cod' => null, 'partner_fee' => null],
            ],
        ], $owner);
        $reconciliations->complete($paid, $transfer, $owner);

        $reconciliations->create([
            'carrier_id' => $shipper->id,
            'branch_id' => $main,
            'items' => [['shipment_id' => $waiting->id, 'partner_cod' => null, 'partner_fee' => null]],
        ], $owner);
    }

    /**
     * @param  list<array{0: string, 1: int}>  $items  SKU and quantity
     * @return list<array{variant_id: int, quantity: int}>
     */
    protected function lines(array $items): array
    {
        return array_map(fn (array $item): array => [
            'variant_id' => (int) ProductVariant::query()->where('sku', $item[0])->value('id'),
            'quantity' => $item[1],
        ], $items);
    }
}
