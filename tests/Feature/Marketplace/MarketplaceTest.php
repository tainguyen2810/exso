<?php

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Support\Settings\StoreDefaults;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockMovement;
use Modules\Marketplace\Jobs\PullMarketplaceOrders;
use Modules\Marketplace\Models\MarketplaceListing;
use Modules\Marketplace\Models\MarketplaceOrder;
use Modules\Marketplace\Models\MarketplaceShop;
use Modules\Marketplace\Services\MarketplaceOrderService;
use Modules\Order\Models\Order;
use Modules\Product\Models\ProductVariant;

const SHOPEE_KEY = 'partner-secret-key';

beforeEach(function () {
    StoreDefaults::install();
    $this->shopee = ['orders' => [], 'items' => [], 'calls' => [], 'stock' => [], 'refresh' => true];
    $shopee = &$this->shopee;

    Http::fake(function (HttpRequest $request) use (&$shopee) {
        $url = parse_url($request->url());
        $path = $url['path'];
        parse_str($url['query'] ?? '', $query);
        $shopee['calls'][] = ['path' => $path, 'query' => $query, 'data' => $request->data()];
        $ok = fn (array $response) => Http::response(['error' => '', 'message' => '', 'request_id' => 'r1', 'response' => $response]);

        // Every call must carry a valid signature.
        $base = $query['partner_id'].$path.$query['timestamp'].($query['access_token'] ?? '').($query['shop_id'] ?? '');

        if (! hash_equals(hash_hmac('sha256', $base, SHOPEE_KEY), (string) ($query['sign'] ?? ''))) {
            return Http::response(['error' => 'error_sign', 'message' => 'Wrong sign.'], 403);
        }

        return match ($path) {
            '/api/v2/auth/token/get' => Http::response(['error' => '', 'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expire_in' => 14400]),
            '/api/v2/auth/access_token/get' => $shopee['refresh']
                ? Http::response(['error' => '', 'access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expire_in' => 14400])
                : Http::response(['error' => 'error_auth', 'message' => 'Invalid refresh token.'], 403),
            '/api/v2/shop/get_shop_info' => $ok(['shop_name' => 'Tin học Nguyễn Tài', 'region' => 'VN']),
            '/api/v2/order/get_order_list' => $ok(['order_list' => array_map(fn ($sn) => ['order_sn' => $sn], array_keys($shopee['orders'])), 'more' => false]),
            '/api/v2/order/get_order_detail' => $ok(['order_list' => array_values(array_intersect_key($shopee['orders'], array_flip(explode(',', $query['order_sn_list']))))]),
            '/api/v2/product/get_item_list' => $ok(['item' => array_map(fn ($item) => ['item_id' => $item['item_id'], 'item_status' => 'NORMAL'], $shopee['items']), 'has_next_page' => false]),
            '/api/v2/product/get_item_base_info' => $ok(['item_list' => array_values($shopee['items'])]),
            '/api/v2/product/get_model_list' => $ok($shopee['models'][$query['item_id']] ?? ['model' => []]),
            '/api/v2/product/update_stock' => (function () use (&$shopee, $request, $ok) {
                $shopee['stock'][] = $request->data();

                return $ok(['success_list' => [], 'failure_list' => []]);
            })(),
            default => Http::response(['error' => 'not_found', 'message' => 'Not found'], 404),
        };
    });
});

function shopeeShop(array $attributes = []): MarketplaceShop
{
    $shop = new MarketplaceShop(['platform' => 'shopee', 'external_id' => '880001', 'name' => 'Tin học Nguyễn Tài', 'status' => MarketplaceShop::ACTIVE, 'branch_id' => mainBranch()->id, 'settings' => [
        'payment_method_id' => PaymentMethod::query()->where('name', 'Ví Shopee')->value('id'),
        'sync_orders' => true,
        'sync_stock' => true,
    ], ...$attributes]);
    $shop->credentials = ['partner_id' => '2001234', 'partner_key' => SHOPEE_KEY, 'access_token' => 'access-1', 'refresh_token' => 'refresh-1'];
    $shop->token_expires_at = now()->addHours(3);
    $shop->refresh_expires_at = now()->addDays(20);
    $shop->webhook_token = Str::random(48);
    $shop->save();

    return $shop;
}

function shopeeOrder(string $sn, string $status, array $items, array $overrides = []): array
{
    return [
        'order_sn' => $sn,
        'order_status' => $status,
        'buyer_user_id' => 55501,
        'buyer_username' => 'l******h',
        'total_amount' => array_sum(array_map(fn ($item) => $item['model_discounted_price'] * $item['model_quantity_purchased'], $items)),
        'shipping_carrier' => 'SPX Express',
        'create_time' => now()->subMinutes(5)->getTimestamp(),
        'update_time' => now()->getTimestamp(),
        'recipient_address' => ['name' => 'L****h', 'phone' => '******89', 'full_address' => '12 Lê Lợi, Quận 1, TP. Hồ Chí Minh'],
        'item_list' => $items,
        ...$overrides,
    ];
}

function shopeeItem(string $itemId, string $modelId, int $price, int $quantity, ?string $sku = null): array
{
    return ['item_id' => (int) $itemId, 'item_name' => 'Chuột không dây', 'item_sku' => '', 'model_id' => (int) $modelId, 'model_name' => '', 'model_sku' => $sku ?? '', 'model_quantity_purchased' => $quantity, 'model_original_price' => $price + 10000, 'model_discounted_price' => $price];
}

function linkedListing(MarketplaceShop $shop, ProductVariant $variant, string $itemId = '1001', string $modelId = '0'): MarketplaceListing
{
    $listing = MarketplaceListing::query()->create(['shop_id' => $shop->id, 'item_id' => $itemId, 'model_id' => $modelId, 'name' => $variant->name, 'sku' => $variant->sku]);
    $listing->forceFill(['variant_id' => $variant->id, 'linked_at' => now()])->save();

    return $listing;
}

function pushOrder(MarketplaceShop $shop, string $sn, ?string $key = SHOPEE_KEY)
{
    $url = route('marketplaces.webhook', $shop->webhook_token);
    $body = json_encode(['shop_id' => (int) $shop->external_id, 'code' => 3, 'timestamp' => time(), 'data' => ['ordersn' => $sn, 'status' => 'READY_TO_SHIP']]);

    return test()->call('POST', $url, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => hash_hmac('sha256', $url.'|'.$body, (string) $key),
    ], $body);
}

test('connecting a Shopee shop: the seller authorises, tokens are kept encrypted, products and orders come in', function () {
    $this->actingAs(owner());
    $item = stocked(10, 100000, [], ['sku' => 'CHUOT-01', 'retail_price' => 250000]);
    $this->shopee['items'] = [['item_id' => 1001, 'item_name' => 'Chuột không dây', 'item_sku' => 'CHUOT-01', 'has_model' => false, 'price_info' => [['current_price' => 239000]], 'stock_info_v2' => ['summary_info' => ['total_available_stock' => 7]]]];

    $response = $this->post('/marketplaces/shops', ['platform' => 'shopee', 'partner_id' => '2001234', 'partner_key' => SHOPEE_KEY, 'branch_id' => mainBranch()->id], ['X-Inertia' => 'true']);
    $response->assertStatus(409);
    $location = $response->headers->get('X-Inertia-Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $auth);
    expect($location)->toStartWith('https://partner.shopeemobile.com/api/v2/shop/auth_partner')
        ->and($auth['sign'])->toBe(hash_hmac('sha256', '2001234/api/v2/shop/auth_partner'.$auth['timestamp'], SHOPEE_KEY));

    $shop = MarketplaceShop::query()->firstOrFail();
    expect($shop->status)->toBe(MarketplaceShop::PENDING);

    // Without the signature the way back is refused.
    $this->get("/marketplaces/shops/{$shop->id}/callback?code=abc&shop_id=880001")->assertForbidden();

    $this->get($auth['redirect'].'&code=abc&shop_id=880001')->assertRedirect("/marketplaces/shops/{$shop->id}");

    $shop->refresh();
    $raw = DB::table('marketplace_shops')->value('credentials');
    expect($shop->status)->toBe(MarketplaceShop::ACTIVE)
        ->and($shop->name)->toBe('Tin học Nguyễn Tài')
        ->and($shop->external_id)->toBe('880001')
        ->and($shop->credential('access_token'))->toBe('access-1')
        ->and($raw)->not->toContain('access-1')->not->toContain(SHOPEE_KEY)
        ->and($shop->setting('payment_method_id'))->toBe(PaymentMethod::query()->where('name', 'Ví Shopee')->value('id'));

    // Products were read and linked by SKU; the stock was pushed.
    $listing = MarketplaceListing::query()->firstOrFail();
    expect($listing->variant_id)->toBe($item->id)
        ->and($this->shopee['stock'][0]['stock_list'][0]['seller_stock'][0]['stock'])->toBe(10);

    // The page never shows the keys.
    $this->get("/marketplaces/shops/{$shop->id}")->assertOk()
        ->assertDontSee(SHOPEE_KEY)->assertDontSee('access-1')->assertDontSee('refresh-1');
});

test('a new Shopee order pushed by the platform becomes an approved EXSO order and lowers the stock pushed back', function () {
    $this->actingAs(owner());
    $shop = shopeeShop();
    $item = stocked(10, 100000, [], ['retail_price' => 250000]);
    linkedListing($shop, $item);
    $this->shopee['orders']['2610290001'] = shopeeOrder('2610290001', 'READY_TO_SHIP', [shopeeItem('1001', '0', 239000, 2)]);

    pushOrder($shop, '2610290001')->assertOk();

    $row = MarketplaceOrder::query()->firstOrFail();
    $order = Order::query()->with('items', 'source', 'customer')->findOrFail($row->order_id);
    expect($row->sync_status)->toBe(MarketplaceOrder::SYNCED)
        ->and($order->status)->toBe(Order::APPROVED)
        ->and($order->reference)->toBe('2610290001')
        ->and($order->channel)->toBe('shopee')
        ->and($order->source->name)->toBe('Shopee')
        ->and($order->items[0]->price)->toBe(239000)
        ->and($order->total)->toBe(478000)
        ->and($order->carrier_name)->toBe('SPX Express')
        ->and($order->customer->marketplace_id)->toBe('55501')
        ->and($order->customer->phone)->toBeNull()
        ->and(stockRow($item)->reserved)->toBe(2);

    // Có thể bán = 10 - 2 went back to Shopee.
    expect(end($this->shopee['stock'])['stock_list'][0]['seller_stock'][0]['stock'])->toBe(8);

    // The same push again (or the scheduled pull) does not make a second order.
    pushOrder($shop, '2610290001')->assertOk();
    app(MarketplaceOrderService::class)->pull($shop);
    expect(Order::query()->count())->toBe(1)->and(MarketplaceOrder::query()->count())->toBe(1);
});

test('a push without the right signature is refused and nothing is fetched', function () {
    $shop = shopeeShop();
    $this->shopee['orders']['2610290002'] = shopeeOrder('2610290002', 'READY_TO_SHIP', [shopeeItem('1001', '0', 239000, 1)]);

    pushOrder($shop, '2610290002', 'wrong-key')->assertStatus(401);
    $this->postJson('/webhooks/marketplace/'.str_repeat('c', 48), [])->assertNotFound();

    expect(MarketplaceOrder::query()->count())->toBe(0)
        ->and(collect($this->shopee['calls'])->where('path', '/api/v2/order/get_order_detail'))->toBeEmpty();
});

test('the order follows Shopee: shipped takes the goods out, completed records the Shopee wallet payment', function () {
    $this->actingAs(owner());
    $shop = shopeeShop();
    $item = stocked(10, 100000);
    linkedListing($shop, $item);
    $service = app(MarketplaceOrderService::class);

    $this->shopee['orders']['A1'] = shopeeOrder('A1', 'READY_TO_SHIP', [shopeeItem('1001', '0', 239000, 3)]);
    $service->syncIds($shop, ['A1']);

    $this->shopee['orders']['A1'] = shopeeOrder('A1', 'SHIPPED', [shopeeItem('1001', '0', 239000, 3)], ['package_list' => [['tracking_number' => 'SPXVN0123']]]);
    $service->syncIds($shop, ['A1']);

    $order = Order::query()->firstOrFail();
    expect($order->status)->toBe(Order::SHIPPED)
        ->and($order->tracking_code)->toBe('SPXVN0123')
        ->and(stockRow($item)->on_hand)->toBe(7)
        ->and(stockRow($item)->reserved)->toBe(0)
        ->and((int) StockMovement::query()->where('type', StockMovement::SALE)->where('reference_code', $order->code)->sum('quantity'))->toBe(-3);

    $this->shopee['orders']['A1'] = shopeeOrder('A1', 'COMPLETED', [shopeeItem('1001', '0', 239000, 3)]);
    $service->syncIds($shop, ['A1']);
    $service->syncIds($shop, ['A1']);

    $order->refresh();
    expect($order->status)->toBe(Order::COMPLETED)
        ->and($order->paid)->toBe(717000)
        ->and($order->payments()->count())->toBe(1)
        ->and($order->payments()->first()->method_name)->toBe('Ví Shopee');
});

test('an order with an unlinked product waits, then comes in once the product is linked', function () {
    $this->actingAs(owner());
    $shop = shopeeShop();
    $item = stocked(5, 100000);
    $this->shopee['orders']['B1'] = shopeeOrder('B1', 'READY_TO_SHIP', [shopeeItem('2002', '0', 150000, 1, 'KHONG-CO-SKU')]);

    app(MarketplaceOrderService::class)->syncIds($shop, ['B1']);
    $row = MarketplaceOrder::query()->firstOrFail();
    expect($row->sync_status)->toBe(MarketplaceOrder::UNLINKED)
        ->and($row->error)->toContain('Chuột không dây')
        ->and($row->order_id)->toBeNull();

    $listing = MarketplaceListing::query()->where('item_id', '2002')->firstOrFail();
    $this->put("/marketplaces/products/{$listing->id}/link", ['variant_id' => $item->id])->assertRedirect();

    $row->refresh();
    expect($row->sync_status)->toBe(MarketplaceOrder::SYNCED)
        ->and($row->order->status)->toBe(Order::APPROVED);
});

test('an order cancelled on Shopee before shipping is cancelled and its goods released', function () {
    $this->actingAs(owner());
    $shop = shopeeShop();
    $item = stocked(5, 100000);
    linkedListing($shop, $item);
    $service = app(MarketplaceOrderService::class);

    $this->shopee['orders']['C1'] = shopeeOrder('C1', 'READY_TO_SHIP', [shopeeItem('1001', '0', 239000, 2)]);
    $service->syncIds($shop, ['C1']);
    expect(stockRow($item)->reserved)->toBe(2);

    $this->shopee['orders']['C1']['order_status'] = 'CANCELLED';
    $service->syncIds($shop, ['C1']);

    expect(Order::query()->firstOrFail()->status)->toBe(Order::CANCELLED)
        ->and(stockRow($item)->reserved)->toBe(0);

    // A cancelled order never seen before is not brought in.
    $this->shopee['orders']['C2'] = shopeeOrder('C2', 'CANCELLED', [shopeeItem('1001', '0', 239000, 1)]);
    $service->syncIds($shop, ['C2']);
    expect(Order::query()->count())->toBe(1);
});

test('short stock does not lose a Shopee order: it is kept pending with the reason', function () {
    $this->actingAs(owner());
    $shop = shopeeShop();
    $item = stocked(1, 100000);
    linkedListing($shop, $item);
    $this->shopee['orders']['D1'] = shopeeOrder('D1', 'READY_TO_SHIP', [shopeeItem('1001', '0', 239000, 3)]);

    app(MarketplaceOrderService::class)->syncIds($shop, ['D1']);

    $row = MarketplaceOrder::query()->firstOrFail();
    expect($row->sync_status)->toBe(MarketplaceOrder::ERROR)
        ->and($row->error)->toContain('Không đủ hàng')
        ->and($row->order->status)->toBe(Order::PENDING);
});

test('the access token is refreshed when it expires; a refused refresh marks the shop expired', function () {
    $shop = shopeeShop();
    $shop->token_expires_at = now()->subMinute();
    $shop->save();
    $this->shopee['orders']['E1'] = shopeeOrder('E1', 'UNPAID', []);

    app(MarketplaceOrderService::class)->pull($shop);
    $shop->refresh();
    expect($shop->credential('access_token'))->toBe('access-2')
        ->and($shop->credential('refresh_token'))->toBe('refresh-2')
        ->and($shop->token_expires_at->isFuture())->toBeTrue();

    $shop->token_expires_at = now()->subMinute();
    $shop->save();
    $this->shopee['refresh'] = false;
    PullMarketplaceOrders::dispatch((int) $shop->tenant_id, $shop->id);

    expect($shop->refresh()->status)->toBe(MarketplaceShop::EXPIRED)
        ->and($shop->last_error)->toContain('cấp quyền lại');
});

test('marketplace pages need the permission and only show shops of the user\'s branches', function () {
    $this->actingAs($owner = owner());
    $shop = shopeeShop();

    foreach (['/marketplaces', '/marketplaces/orders', '/marketplaces/products', '/marketplaces/settings', "/marketplaces/shops/{$shop->id}"] as $page) {
        $this->get($page)->assertOk();
    }

    $other = Branch::query()->create(['code' => 'CN02', 'name' => 'Chi nhánh 2']);
    $hidden = shopeeShop(['external_id' => '990002', 'name' => 'Gian hàng chi nhánh 2', 'branch_id' => $other->id]);
    $cashier = staffWithRole('Thu ngân');
    $this->actingAs($cashier)->get('/marketplaces')->assertForbidden();

    $manager = staffWithRole('Quản trị');
    $this->actingAs($manager)->get("/marketplaces/shops/{$hidden->id}")->assertForbidden();
    $this->actingAs($manager)->get('/marketplaces/settings')->assertOk()->assertDontSee('Gian hàng chi nhánh 2');
});
