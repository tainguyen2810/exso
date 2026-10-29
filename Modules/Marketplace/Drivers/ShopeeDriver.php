<?php

namespace Modules\Marketplace\Drivers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Marketplace\Models\MarketplaceShop;

/**
 * Shopee Open Platform API v2 (partner.shopeemobile.com; the test
 * environment is partner.test-stable.shopeemobile.com).
 *
 * Every call is signed: sign = HMAC-SHA256(partner key, partner_id + path +
 * timestamp [+ access_token + shop_id]). The access token lasts 4 hours and
 * is refreshed here (row locked, so two workers never refresh at once);
 * the refresh token lasts 30 days, after that the seller authorises again.
 *
 * Push calls carry Authorization = HMAC-SHA256(partner key, URL + "|" + body).
 */
class ShopeeDriver implements MarketplaceDriver
{
    public const PRODUCTION = 'https://partner.shopeemobile.com';

    public const SANDBOX = 'https://partner.test-stable.shopeemobile.com';

    /** Shopee order_status => [label, common state]. */
    public const STATUSES = [
        'UNPAID' => ['Chờ thanh toán', 'unpaid'],
        'READY_TO_SHIP' => ['Chờ xác nhận', 'to_ship'],
        'PROCESSED' => ['Chờ lấy hàng', 'to_ship'],
        'RETRY_SHIP' => ['Giao lại', 'to_ship'],
        'INVOICE_PENDING' => ['Chờ hóa đơn', 'to_ship'],
        'SHIPPED' => ['Đang giao', 'shipped'],
        'TO_CONFIRM_RECEIVE' => ['Đã giao, chờ xác nhận', 'shipped'],
        'COMPLETED' => ['Hoàn thành', 'completed'],
        'IN_CANCEL' => ['Đang hủy', 'to_ship'],
        'CANCELLED' => ['Đã hủy', 'cancelled'],
        'TO_RETURN' => ['Trả hàng/Hoàn tiền', 'returned'],
    ];

    /** Errors that mean the token is no longer valid. */
    protected const AUTH_ERRORS = ['error_auth', 'invalid_access_token', 'invalid_acceess_token', 'error_permission'];

    public function __construct(protected MarketplaceShop $shop) {}

    public static function label(): string
    {
        return 'Shopee';
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUSES[$status][0] ?? $status;
    }

    public function authorizeUrl(string $redirect): string
    {
        $path = '/api/v2/shop/auth_partner';
        $timestamp = time();

        return $this->baseUrl().$path.'?'.http_build_query([
            'partner_id' => $this->partnerId(),
            'timestamp' => $timestamp,
            'sign' => $this->sign($path, $timestamp),
            'redirect' => $redirect,
        ]);
    }

    public function connect(array $query): array
    {
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        $shopId = is_scalar($query['shop_id'] ?? null) ? (string) $query['shop_id'] : '';

        if ($code === '' || ! ctype_digit($shopId)) {
            throw new MarketplaceException('Shopee không trả về mã cấp quyền. Hãy thử kết nối lại.');
        }

        $data = $this->public('/api/v2/auth/token/get', ['code' => $code, 'shop_id' => (int) $shopId, 'partner_id' => $this->partnerId()]);
        $this->storeTokens($data);
        $this->shop->external_id = $shopId;

        $info = $this->call('get', '/api/v2/shop/get_shop_info');

        return [
            'external_id' => $shopId,
            'name' => is_string($info['shop_name'] ?? null) && $info['shop_name'] !== '' ? $info['shop_name'] : "Shopee {$shopId}",
        ];
    }

    public function changedOrders(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = [];

        // Shopee answers at most 15 days per call.
        for ($start = $from; $start < $to; $start = $start->addDays(15)) {
            $end = $start->addDays(15)->min($to);
            $cursor = '';

            do {
                $data = $this->call('get', '/api/v2/order/get_order_list', [
                    'time_range_field' => 'update_time',
                    'time_from' => $start->getTimestamp(),
                    'time_to' => $end->getTimestamp(),
                    'page_size' => 100,
                    'cursor' => $cursor,
                ]);

                foreach ((array) ($data['order_list'] ?? []) as $order) {
                    if (is_array($order) && is_scalar($order['order_sn'] ?? null)) {
                        $ids[] = (string) $order['order_sn'];
                    }
                }

                $cursor = (bool) ($data['more'] ?? false) ? (string) ($data['next_cursor'] ?? '') : '';
            } while ($cursor !== '');
        }

        return array_values(array_unique($ids));
    }

    public function orders(array $ids): array
    {
        $orders = [];

        foreach (array_chunk($ids, 50) as $chunk) {
            $data = $this->call('get', '/api/v2/order/get_order_detail', [
                'order_sn_list' => implode(',', $chunk),
                'response_optional_fields' => 'buyer_user_id,buyer_username,recipient_address,item_list,total_amount,shipping_carrier,note,pay_time,package_list',
            ]);

            foreach ((array) ($data['order_list'] ?? []) as $order) {
                if (is_array($order)) {
                    $orders[] = $this->order($order);
                }
            }
        }

        return $orders;
    }

    public function listings(): array
    {
        $ids = [];
        $offset = 0;

        do {
            $data = $this->call('get', '/api/v2/product/get_item_list', ['offset' => $offset, 'page_size' => 100, 'item_status' => 'NORMAL']);

            foreach ((array) ($data['item'] ?? []) as $item) {
                if (is_array($item) && is_scalar($item['item_id'] ?? null)) {
                    $ids[] = (string) $item['item_id'];
                }
            }

            $more = (bool) ($data['has_next_page'] ?? false);
            $offset = (int) ($data['next_offset'] ?? 0);
        } while ($more && $offset > 0);

        $listings = [];

        foreach (array_chunk($ids, 50) as $chunk) {
            $data = $this->call('get', '/api/v2/product/get_item_base_info', ['item_id_list' => implode(',', $chunk)]);

            foreach ((array) ($data['item_list'] ?? []) as $item) {
                if (is_array($item)) {
                    array_push($listings, ...$this->itemListings($item));
                }
            }
        }

        return $listings;
    }

    public function updateStock(array $stocks): array
    {
        $errors = [];
        $byItem = [];

        foreach ($stocks as $stock) {
            $byItem[$stock['item_id']][] = $stock;
        }

        foreach ($byItem as $itemId => $rows) {
            $data = $this->call('post', '/api/v2/product/update_stock', [
                'item_id' => (int) $itemId,
                'stock_list' => array_map(fn (array $row): array => [
                    'model_id' => (int) $row['model_id'],
                    'seller_stock' => [['stock' => max(0, $row['stock'])]],
                ], $rows),
            ]);

            foreach ((array) ($data['failure_list'] ?? []) as $failure) {
                if (is_array($failure)) {
                    $errors[$itemId.':'.($failure['model_id'] ?? 0)] = (string) ($failure['failed_reason'] ?? 'Shopee từ chối cập nhật tồn.');
                }
            }
        }

        return $errors;
    }

    public function webhookOrders(Request $request): ?array
    {
        $key = $this->shop->credential('partner_key');
        $signature = (string) $request->header('Authorization', '');
        $body = $request->getContent();

        if ($key === null || $signature === '') {
            return null;
        }

        // Shopee signs the push URL registered in its console; accept it with
        // or without the proxy's scheme/host differences.
        $valid = false;

        foreach (array_unique([$request->fullUrl(), route('marketplaces.webhook', (string) $this->shop->webhook_token)]) as $url) {
            $valid = $valid || hash_equals(hash_hmac('sha256', $url.'|'.$body, $key), strtolower($signature));
        }

        if (! $valid) {
            return null;
        }

        $payload = json_decode($body, true);

        if (! is_array($payload) || (string) ($payload['shop_id'] ?? '') !== (string) $this->shop->external_id) {
            return null;
        }

        $order = $payload['data']['ordersn'] ?? $payload['data']['order_sn'] ?? null;

        // 3 = order status, 4 = tracking number; other pushes need no action.
        return in_array((int) ($payload['code'] ?? 0), [3, 4], true) && is_scalar($order) ? [(string) $order] : [];
    }

    /**
     * @param  array<string, mixed>  $order
     */
    protected function order(array $order): PlatformOrder
    {
        $status = (string) ($order['order_status'] ?? '');
        $items = [];

        foreach ((array) ($order['item_list'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $model = (string) ($item['model_id'] ?? '0');
            $modelName = trim((string) ($item['model_name'] ?? ''));
            $items[] = [
                'item_id' => (string) ($item['item_id'] ?? ''),
                'model_id' => $model === '' ? '0' : $model,
                'sku' => $this->text($item['model_sku'] ?? null) ?? $this->text($item['item_sku'] ?? null),
                'name' => trim((string) ($item['item_name'] ?? '')).($modelName !== '' ? " - {$modelName}" : ''),
                'quantity' => max(1, (int) ($item['model_quantity_purchased'] ?? 1)),
                'price' => (int) round((float) ($item['model_discounted_price'] ?? $item['model_original_price'] ?? 0)),
                'original_price' => (int) round((float) ($item['model_original_price'] ?? 0)),
            ];
        }

        $address = is_array($order['recipient_address'] ?? null) ? $order['recipient_address'] : [];
        $package = is_array($order['package_list'][0] ?? null) ? $order['package_list'][0] : [];

        return new PlatformOrder(
            id: (string) ($order['order_sn'] ?? ''),
            status: $status,
            state: self::STATUSES[$status][1] ?? 'to_ship',
            items: $items,
            total: (int) round((float) ($order['total_amount'] ?? 0)),
            buyerId: isset($order['buyer_user_id']) ? (string) $order['buyer_user_id'] : null,
            buyerUsername: $this->text($order['buyer_username'] ?? null),
            recipientName: $this->text($address['name'] ?? null),
            recipientPhone: $this->text($address['phone'] ?? null),
            address: $this->text($address['full_address'] ?? null),
            carrier: $this->text($order['shipping_carrier'] ?? $package['shipping_carrier'] ?? null),
            trackingCode: $this->text($package['tracking_number'] ?? null),
            note: $this->text($order['note'] ?? $order['message_to_seller'] ?? null),
            placedAt: isset($order['create_time']) ? CarbonImmutable::createFromTimestamp((int) $order['create_time']) : null,
            updatedAt: isset($order['update_time']) ? CarbonImmutable::createFromTimestamp((int) $order['update_time']) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<PlatformListing>
     */
    protected function itemListings(array $item): array
    {
        $itemId = (string) ($item['item_id'] ?? '');
        $name = trim((string) ($item['item_name'] ?? ''));
        $image = $item['image']['image_url_list'][0] ?? null;
        $image = is_string($image) ? $image : null;
        $status = $this->text($item['item_status'] ?? null);

        if (! (bool) ($item['has_model'] ?? false)) {
            return [new PlatformListing(
                itemId: $itemId,
                modelId: '0',
                name: $name,
                modelName: null,
                sku: $this->text($item['item_sku'] ?? null),
                price: $this->price($item['price_info'] ?? null),
                stock: $this->stock($item['stock_info_v2'] ?? null),
                imageUrl: $image,
                status: $status,
            )];
        }

        $data = $this->call('get', '/api/v2/product/get_model_list', ['item_id' => (int) $itemId]);
        $tiers = (array) ($data['tier_variation'] ?? []);
        $listings = [];

        foreach ((array) ($data['model'] ?? []) as $model) {
            if (! is_array($model)) {
                continue;
            }

            // The variation name is made of the tier options ("Đỏ, XL").
            $parts = [];

            foreach ((array) ($model['tier_index'] ?? []) as $tier => $index) {
                $option = $tiers[$tier]['option_list'][$index]['option'] ?? $tiers[$tier]['options'][$index] ?? null;

                if (is_string($option) && $option !== '') {
                    $parts[] = $option;
                }
            }

            $listings[] = new PlatformListing(
                itemId: $itemId,
                modelId: (string) ($model['model_id'] ?? '0'),
                name: $name,
                modelName: $parts === [] ? $this->text($model['model_name'] ?? null) : implode(', ', $parts),
                sku: $this->text($model['model_sku'] ?? null),
                price: $this->price($model['price_info'] ?? null),
                stock: $this->stock($model['stock_info_v2'] ?? null),
                imageUrl: $image,
                status: $status,
            );
        }

        return $listings;
    }

    protected function price(mixed $info): int
    {
        $first = is_array($info) ? ($info[0] ?? null) : null;

        return is_array($first) ? (int) round((float) ($first['current_price'] ?? 0)) : 0;
    }

    protected function stock(mixed $info): int
    {
        return is_array($info) ? (int) ($info['summary_info']['total_available_stock'] ?? 0) : 0;
    }

    protected function text(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? mb_substr(trim((string) $value), 0, 255) : null;
    }

    /**
     * A shop-level call, refreshing the access token when it is about to end.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function call(string $method, string $path, array $params = [], bool $retry = true): array
    {
        if ($this->shop->token_expires_at === null || $this->shop->token_expires_at->subMinutes(5)->isPast()) {
            $this->refresh();
        }

        $token = (string) $this->shop->credential('access_token');
        $shopId = (int) $this->shop->external_id;
        $timestamp = time();
        $common = [
            'partner_id' => $this->partnerId(),
            'timestamp' => $timestamp,
            'access_token' => $token,
            'shop_id' => $shopId,
            'sign' => $this->sign($path, $timestamp, $token, $shopId),
        ];

        try {
            $response = $method === 'get'
                ? $this->http()->get($this->baseUrl().$path, [...$common, ...$params])
                : $this->http()->post($this->baseUrl().$path.'?'.http_build_query($common), $params);
        } catch (ConnectionException) {
            throw new MarketplaceException('Không kết nối được Shopee, hãy thử lại sau.');
        }

        $json = $response->json();
        $error = is_array($json) ? (string) ($json['error'] ?? '') : 'invalid_response';

        if ($retry && in_array($error, self::AUTH_ERRORS, true)) {
            $this->refresh(true);

            return $this->call($method, $path, $params, false);
        }

        if ($error !== '' || $response->failed()) {
            $this->fail($json, $response->status());
        }

        return is_array($json['response'] ?? null) ? $json['response'] : [];
    }

    /**
     * An app-level call (authorisation, tokens).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function public(string $path, array $body): array
    {
        $timestamp = time();

        try {
            $response = $this->http()->post($this->baseUrl().$path.'?'.http_build_query([
                'partner_id' => $this->partnerId(),
                'timestamp' => $timestamp,
                'sign' => $this->sign($path, $timestamp),
            ]), $body);
        } catch (ConnectionException) {
            throw new MarketplaceException('Không kết nối được Shopee, hãy thử lại sau.');
        }

        $json = $response->json();

        if (! is_array($json) || (string) ($json['error'] ?? '') !== '' || $response->failed()) {
            $this->fail($json, $response->status());
        }

        return $json;
    }

    /**
     * Get a new access token with the refresh token. The shop row is locked
     * and read again, so a token another worker just refreshed is reused.
     */
    protected function refresh(bool $force = false): void
    {
        DB::transaction(function () use ($force): void {
            /** @var MarketplaceShop $locked */
            $locked = MarketplaceShop::query()->lockForUpdate()->findOrFail($this->shop->id);

            if (! $force && $locked->token_expires_at !== null && $locked->token_expires_at->subMinutes(5)->isFuture()) {
                $this->shop->setRawAttributes($locked->getAttributes(), true);

                return;
            }

            $refresh = $locked->credential('refresh_token');

            if ($refresh === null || ($locked->refresh_expires_at !== null && $locked->refresh_expires_at->isPast())) {
                throw MarketplaceException::expired('Gian hàng đã hết hạn đăng nhập. Hãy cấp quyền lại.');
            }

            try {
                $data = $this->public('/api/v2/auth/access_token/get', [
                    'refresh_token' => $refresh,
                    'shop_id' => (int) $locked->external_id,
                    'partner_id' => $this->partnerId(),
                ]);
            } catch (MarketplaceException $exception) {
                throw MarketplaceException::expired('Gian hàng đã hết hạn đăng nhập ('.$exception->getMessage().'). Hãy cấp quyền lại.');
            }

            $this->shop = $locked;
            $this->storeTokens($data);
            $locked->save();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function storeTokens(array $data): void
    {
        $access = $data['access_token'] ?? null;
        $refresh = $data['refresh_token'] ?? null;

        if (! is_string($access) || $access === '' || ! is_string($refresh) || $refresh === '') {
            throw new MarketplaceException('Shopee không trả về token truy cập.');
        }

        $this->shop->credentials = [...($this->shop->credentials ?? []), 'access_token' => $access, 'refresh_token' => $refresh];
        $this->shop->token_expires_at = now()->toImmutable()->addSeconds(max(60, (int) ($data['expire_in'] ?? 14400)));
        $this->shop->refresh_expires_at = now()->toImmutable()->addDays(30);
    }

    protected function fail(mixed $json, int $status): never
    {
        $message = is_array($json) ? ($json['message'] ?? $json['error'] ?? null) : null;

        throw new MarketplaceException('Shopee: '.(is_string($message) && $message !== '' ? $message : "lỗi {$status}."));
    }

    protected function sign(string $path, int $timestamp, string $token = '', ?int $shopId = null): string
    {
        $key = $this->shop->credential('partner_key');

        if ($key === null) {
            throw new MarketplaceException('Chưa nhập Partner Key của ứng dụng Shopee.');
        }

        return hash_hmac('sha256', $this->partnerId().$path.$timestamp.$token.($shopId ?? ''), $key);
    }

    protected function partnerId(): int
    {
        $id = $this->shop->credential('partner_id');

        if ($id === null || ! ctype_digit($id)) {
            throw new MarketplaceException('Chưa nhập Partner ID của ứng dụng Shopee.');
        }

        return (int) $id;
    }

    protected function http(): PendingRequest
    {
        return Http::acceptJson()->asJson()->timeout(20)->connectTimeout(5);
    }

    protected function baseUrl(): string
    {
        // A local test server can stand in for Shopee (development only).
        $override = config('marketplace.shopee_url');

        if (is_string($override) && $override !== '') {
            return rtrim($override, '/');
        }

        return $this->shop->is_sandbox ? self::SANDBOX : self::PRODUCTION;
    }
}
