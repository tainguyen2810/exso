<?php

namespace Modules\Shipping\Carriers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\Shipment;

/**
 * Giao Hàng Nhanh (GHN) through its public API v2 (api.ghn.vn): token +
 * ShopId headers, JSON answers {code, message, data}. The test environment
 * is dev-online-gateway.ghn.vn.
 *
 * Addresses are sent as GHN district / ward ids, looked up from the names
 * in GHN's master data (cached a week).
 */
class GhnDriver implements CarrierDriver
{
    public const PRODUCTION = 'https://online-gateway.ghn.vn';

    public const SANDBOX = 'https://dev-online-gateway.ghn.vn';

    /** GHN service types when the shop's district is unknown. */
    public const SERVICE_TYPES = [
        '2' => 'Hàng nhẹ (Chuẩn)',
        '5' => 'Hàng nặng',
    ];

    /** GHN order statuses (Trạng thái đối tác) => common status. */
    public const STATUSES = [
        'ready_to_pick' => ['Chờ lấy hàng', Shipment::PENDING],
        'picking' => ['Đang lấy hàng', Shipment::PENDING],
        'money_collect_picking' => ['Đang tương tác với người gửi', Shipment::PENDING],
        'picked' => ['Đã lấy hàng', Shipment::SHIPPING],
        'storing' => ['Hàng đang nằm ở kho', Shipment::SHIPPING],
        'transporting' => ['Đang luân chuyển hàng', Shipment::SHIPPING],
        'sorting' => ['Đang phân loại hàng hóa', Shipment::SHIPPING],
        'delivering' => ['Đang giao hàng', Shipment::SHIPPING],
        'money_collect_delivering' => ['Đang tương tác với người nhận', Shipment::SHIPPING],
        'delivered' => ['Giao hàng thành công', Shipment::DELIVERED],
        'delivery_fail' => ['Giao hàng thất bại', Shipment::REDELIVERY],
        'waiting_to_return' => ['Chờ trả hàng', Shipment::RETURNING],
        'return' => ['Trả hàng', Shipment::RETURNING],
        'return_transporting' => ['Đang luân chuyển hàng trả', Shipment::RETURNING],
        'return_sorting' => ['Đang phân loại hàng trả', Shipment::RETURNING],
        'returning' => ['Đang trả hàng', Shipment::RETURNING],
        'return_fail' => ['Trả hàng thất bại', Shipment::RETURNING],
        'returned' => ['Trả hàng thành công', Shipment::RETURNED],
        'cancel' => ['Hủy đơn hàng', Shipment::CANCELLED],
        'exception' => ['Đơn hàng ngoại lệ', null],
        'damage' => ['Hàng bị hư hỏng', null],
        'lost' => ['Hàng bị thất lạc', null],
    ];

    public function __construct(protected Carrier $carrier) {}

    public static function label(): string
    {
        return 'Giao Hàng Nhanh (GHN)';
    }

    public function verify(): array
    {
        $data = $this->call('get', '/v2/shop/all', ['offset' => 0, 'limit' => 50], false);
        $shops = is_array($data['shops'] ?? null) ? $data['shops'] : [];

        return array_values(array_map(fn (array $shop): array => [
            'id' => (string) ($shop['_id'] ?? ''),
            'name' => (string) ($shop['name'] ?? ''),
            'address' => isset($shop['address']) ? (string) $shop['address'] : null,
            'district_id' => isset($shop['district_id']) ? (int) $shop['district_id'] : null,
        ], array_filter($shops, 'is_array')));
    }

    public function quote(ShipmentRequest $request): array
    {
        [$districtId, $wardCode] = $this->resolveAddress($request);
        $quotes = [];

        foreach ($this->services($request->branchId, $districtId) as $service) {
            $data = $this->call('post', '/v2/shipping-order/fee', [
                ...$service['key'],
                'to_district_id' => $districtId,
                'to_ward_code' => $wardCode,
                'weight' => max(1, $request->weight),
                'length' => max(1, $request->length),
                'width' => max(1, $request->width),
                'height' => max(1, $request->height),
                'insurance_value' => max(0, $request->insuranceValue),
                'cod_value' => max(0, $request->codAmount),
            ], true, $request->branchId);

            $quotes[] = new Quote($service['id'], $service['name'], (int) ($data['total'] ?? 0));
        }

        return $quotes;
    }

    public function create(ShipmentRequest $request): CreatedShipment
    {
        [$districtId, $wardCode] = $this->resolveAddress($request);
        [$type, $id] = $this->serviceKey($request->serviceId);

        $data = $this->call('post', '/v2/shipping-order/create', [
            'payment_type_id' => $request->feePayer === 'receiver' ? 2 : 1,
            'note' => $request->note,
            'required_note' => $request->requiredNote ?? 'CHOXEMHANGKHONGTHU',
            'client_order_code' => $request->clientCode,
            'to_name' => $request->receiverName,
            'to_phone' => $request->receiverPhone,
            'to_address' => implode(', ', array_filter([$request->address, $request->ward, $request->district, $request->province])),
            'to_ward_code' => $wardCode,
            'to_district_id' => $districtId,
            'cod_amount' => max(0, $request->codAmount),
            'insurance_value' => max(0, $request->insuranceValue),
            'weight' => max(1, $request->weight),
            'length' => max(1, $request->length),
            'width' => max(1, $request->width),
            'height' => max(1, $request->height),
            ...($id !== null ? ['service_id' => $id] : ['service_type_id' => $type]),
            'items' => array_map(fn (array $item): array => [
                'name' => mb_substr($item['name'], 0, 200),
                'code' => $item['code'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'weight' => max(1, $item['weight']),
            ], $request->items),
        ], true, $request->branchId);

        $code = (string) ($data['order_code'] ?? '');

        if ($code === '') {
            throw new CarrierException('GHN không trả về mã vận đơn.');
        }

        return new CreatedShipment(
            trackingCode: $code,
            fee: (int) ($data['total_fee'] ?? 0),
            carrierStatus: 'ready_to_pick',
            expectedAt: $this->date($data['expected_delivery_time'] ?? null),
        );
    }

    public function cancel(Shipment $shipment): void
    {
        $data = $this->call('post', '/v2/switch-status/cancel', ['order_codes' => [(string) $shipment->tracking_code]], true, $shipment->branch_id);
        $result = is_array($data) ? ($data[0] ?? null) : null;

        if (is_array($result) && ($result['result'] ?? true) === false) {
            throw new CarrierException('GHN không hủy được vận đơn: '.($result['message'] ?? 'đơn đã được lấy hàng.'));
        }
    }

    public function track(Shipment $shipment): Tracking
    {
        $data = $this->call('post', '/v2/shipping-order/detail', ['order_code' => (string) $shipment->tracking_code], true, $shipment->branch_id);
        $status = (string) ($data['status'] ?? '');

        if ($status === '') {
            throw new CarrierException('GHN không trả về trạng thái vận đơn.');
        }

        $events = [];

        foreach (is_array($data['log'] ?? null) ? $data['log'] : [] as $log) {
            $at = is_array($log) ? $this->date($log['updated_date'] ?? null) : null;

            if ($at !== null && isset($log['status'])) {
                $events[] = ['carrier_status' => (string) $log['status'], 'note' => null, 'occurred_at' => $at];
            }
        }

        usort($events, fn (array $a, array $b): int => $a['occurred_at'] <=> $b['occurred_at']);

        return new Tracking(
            carrierStatus: $status,
            events: $events,
            codAmount: isset($data['cod_amount']) ? (int) $data['cod_amount'] : null,
            fee: isset($data['total_fee']) ? (int) $data['total_fee'] : null,
        );
    }

    public function labelUrl(Shipment $shipment): string
    {
        $data = $this->call('post', '/v2/a5/gen-token', ['order_codes' => [(string) $shipment->tracking_code]], false);
        $token = (string) ($data['token'] ?? '');

        if ($token === '') {
            throw new CarrierException('GHN không tạo được nhãn in.');
        }

        return $this->baseUrl().'/a5/public-api/printA5?token='.urlencode($token);
    }

    public function webhookTrackingCode(array $payload): ?string
    {
        $code = $payload['OrderCode'] ?? null;
        $shop = $payload['ShopID'] ?? null;

        if (! is_string($code) || $code === '') {
            return null;
        }

        // A call for another GHN shop than the ones connected is ignored.
        if ($shop !== null && ! in_array((string) $shop, $this->shopIds(), true)) {
            return null;
        }

        return $code;
    }

    public function status(string $carrierStatus): ?string
    {
        return self::STATUSES[$carrierStatus][1] ?? null;
    }

    public function statusLabel(string $carrierStatus): string
    {
        return self::STATUSES[$carrierStatus][0] ?? $carrierStatus;
    }

    /**
     * GHN shop of a branch (Cấu hình: "Cửa hàng GHN theo chi nhánh"), or the default one.
     */
    public function shopId(?int $branchId = null): string
    {
        $branches = $this->carrier->setting('branch_shops', []);
        $shop = is_array($branches) && $branchId !== null ? ($branches[(string) $branchId] ?? null) : null;
        $shop = $shop ?: $this->carrier->setting('shop_id');

        if ($shop === null || (string) $shop === '') {
            throw new CarrierException('Chưa chọn cửa hàng GHN (ShopId) trong cấu hình kết nối.');
        }

        return (string) $shop;
    }

    /**
     * @return list<string>
     */
    protected function shopIds(): array
    {
        $branches = $this->carrier->setting('branch_shops', []);

        return array_values(array_unique(array_filter(array_map('strval', [
            $this->carrier->setting('shop_id'),
            ...(is_array($branches) ? array_values($branches) : []),
        ]))));
    }

    /**
     * Services offered between the shop's district and the receiver's, or
     * GHN's standard service types when the shop's district is unknown.
     *
     * @return list<array{id: string, name: string, key: array<string, int>}>
     */
    protected function services(int $branchId, int $toDistrict): array
    {
        $from = (int) $this->carrier->setting('from_district_id', 0);

        if ($from > 0) {
            $data = $this->call('post', '/v2/shipping-order/available-services', [
                'shop_id' => (int) $this->shopId($branchId),
                'from_district' => $from,
                'to_district' => $toDistrict,
            ], false);

            $services = [];

            foreach (is_array($data) ? $data : [] as $service) {
                if (is_array($service) && isset($service['service_id'])) {
                    $services[] = [
                        'id' => 'service:'.(int) $service['service_id'],
                        'name' => (string) ($service['short_name'] ?? 'Dịch vụ '.$service['service_id']),
                        'key' => ['service_id' => (int) $service['service_id']],
                    ];
                }
            }

            if ($services !== []) {
                return $services;
            }
        }

        $types = [];

        foreach (self::SERVICE_TYPES as $type => $name) {
            $types[] = ['id' => 'type:'.$type, 'name' => $name, 'key' => ['service_type_id' => (int) $type]];
        }

        return $types;
    }

    /**
     * "service:53320" or "type:2" (from quote()) => [service type, service id].
     *
     * @return array{0: int, 1: int|null}
     */
    protected function serviceKey(?string $service): array
    {
        if ($service !== null && preg_match('/^service:(\d+)$/', $service, $match) === 1) {
            return [2, (int) $match[1]];
        }

        if ($service !== null && preg_match('/^type:(\d+)$/', $service, $match) === 1) {
            return [(int) $match[1], null];
        }

        return [2, null];
    }

    /**
     * GHN district id and ward code of the receiver's address.
     *
     * @return array{0: int, 1: string}
     */
    public function resolveAddress(ShipmentRequest $request): array
    {
        if (trim((string) $request->province) === '' || trim((string) $request->district) === '' || trim((string) $request->ward) === '') {
            throw new CarrierException('Hãy nhập đủ Tỉnh/Thành phố, Quận/Huyện, Phường/Xã của người nhận.');
        }

        $province = $this->match($this->master('province', '/master-data/province', []), 'ProvinceID', 'ProvinceName', (string) $request->province);

        if ($province === null) {
            throw new CarrierException("GHN không có tỉnh/thành phố \"{$request->province}\".");
        }

        $district = $this->match($this->master("district:{$province}", '/master-data/district', ['province_id' => (int) $province]), 'DistrictID', 'DistrictName', (string) $request->district);

        if ($district === null) {
            throw new CarrierException("GHN không có quận/huyện \"{$request->district}\" ở {$request->province}.");
        }

        $ward = $this->match($this->master("ward:{$district}", '/master-data/ward', ['district_id' => (int) $district]), 'WardCode', 'WardName', (string) $request->ward);

        if ($ward === null) {
            throw new CarrierException("GHN không có phường/xã \"{$request->ward}\" ở {$request->district}.");
        }

        return [(int) $district, (string) $ward];
    }

    /**
     * @param  array<string, int>  $query
     * @return list<array<string, mixed>>
     */
    protected function master(string $key, string $path, array $query): array
    {
        $cacheKey = 'ghn:'.($this->carrier->is_sandbox ? 'dev' : 'prod').':'.$key;
        $rows = Cache::get($cacheKey);

        if (! is_array($rows)) {
            $data = $this->call('get', $path, $query, false);
            $rows = array_values(array_filter(is_array($data) ? $data : [], 'is_array'));

            if ($rows !== []) {
                Cache::put($cacheKey, $rows, now()->addWeek());
            }
        }

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * Find a place by name, ignoring accents, case and words such as
     * "Tỉnh", "Quận", "Phường"; GHN's NameExtension lists other spellings.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function match(array $rows, string $idKey, string $nameKey, string $name): int|string|null
    {
        $wanted = self::normalizePlace($name);

        foreach ($rows as $row) {
            $names = [(string) ($row[$nameKey] ?? ''), ...array_map('strval', is_array($row['NameExtension'] ?? null) ? $row['NameExtension'] : [])];

            foreach ($names as $candidate) {
                if ($candidate !== '' && self::normalizePlace($candidate) === $wanted) {
                    $id = $row[$idKey] ?? null;

                    return is_int($id) || is_string($id) ? $id : null;
                }
            }
        }

        return null;
    }

    public static function normalizePlace(string $name): string
    {
        $value = Str::lower(Str::ascii(trim($name)));
        $value = (string) preg_replace('/^(tinh|thanh pho|tp\.?|quan|huyen|thi xa|tx\.?|phuong|xa|thi tran|tt\.?|p\.|q\.)\s+/', '', $value);
        $value = (string) preg_replace('/[^a-z0-9]+/', '', $value);

        // "Quận 3" and "3" are the same district.
        return ltrim($value, '0') ?: $value;
    }

    /**
     * Call the GHN API and return its "data", or throw with GHN's message.
     *
     * @param  array<string, mixed>  $body
     */
    protected function call(string $method, string $path, array $body, bool $withShop, ?int $branchId = null): mixed
    {
        $token = $this->carrier->credential('token');

        if ($token === null) {
            throw new CarrierException('Chưa nhập Token API của GHN.');
        }

        $request = $this->http()->withHeaders(array_filter([
            'Token' => $token,
            'ShopId' => $withShop ? $this->shopId($branchId) : null,
        ]));

        try {
            $response = $method === 'get'
                ? $request->get($this->baseUrl().'/shiip/public-api'.$path, $body)
                : $request->post($this->baseUrl().'/shiip/public-api'.$path, $body);
        } catch (ConnectionException) {
            throw new CarrierException('Không kết nối được GHN, hãy thử lại sau.');
        }

        $json = $response->json();

        if (! is_array($json) || ($json['code'] ?? $response->status()) !== 200 || $response->failed()) {
            $message = is_array($json) ? ($json['code_message_value'] ?? $json['message'] ?? null) : null;

            throw new CarrierException('GHN: '.(is_string($message) && $message !== '' ? $message : 'lỗi '.$response->status().'.'));
        }

        return $json['data'] ?? null;
    }

    protected function http(): PendingRequest
    {
        return Http::acceptJson()->asJson()->timeout(15)->connectTimeout(5);
    }

    protected function baseUrl(): string
    {
        // A local test server can stand in for GHN (development only).
        $override = config('shipping.ghn_url');

        if (is_string($override) && $override !== '') {
            return rtrim($override, '/');
        }

        return $this->carrier->is_sandbox ? self::SANDBOX : self::PRODUCTION;
    }

    protected function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            // Stored datetimes are in the app timezone.
            return CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
