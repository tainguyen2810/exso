<?php

namespace Modules\Shipping\Carriers;

use Modules\Shipping\Models\Shipment;

/**
 * A carrier connected by API. One instance works for one connection
 * (Carrier row) and its credentials. Add a carrier by implementing this and
 * registering it with Hook::filter('shipping.drivers').
 */
interface CarrierDriver
{
    /** Carrier name shown on "Kết nối đối tác". */
    public static function label(): string;

    /**
     * Check the credentials; returns the carrier's pick-up shops.
     *
     * @return list<array{id: string, name: string, address: string|null, district_id?: int|null}>
     *
     * @throws CarrierException
     */
    public function verify(): array;

    /**
     * Services and fees for a parcel ("Tính phí").
     *
     * @return list<Quote>
     *
     * @throws CarrierException
     */
    public function quote(ShipmentRequest $request): array;

    /**
     * Send the delivery request to the carrier ("Tạo đơn").
     *
     * @throws CarrierException
     */
    public function create(ShipmentRequest $request): CreatedShipment;

    /**
     * @throws CarrierException
     */
    public function cancel(Shipment $shipment): void;

    /**
     * @throws CarrierException
     */
    public function track(Shipment $shipment): Tracking;

    /**
     * URL of the carrier's printable label ("In nhãn").
     *
     * @throws CarrierException
     */
    public function labelUrl(Shipment $shipment): string;

    /**
     * Tracking code a webhook call is about, or null when the payload is
     * not for this connection. The status itself is fetched again with
     * track(), never taken from the payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function webhookTrackingCode(array $payload): ?string;

    /** Common shipment status for a raw carrier status (null = keep). */
    public function status(string $carrierStatus): ?string;

    public function statusLabel(string $carrierStatus): string;
}
