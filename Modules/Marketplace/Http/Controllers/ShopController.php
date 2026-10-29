<?php

namespace Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\SalesSource;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Customer\Http\Controllers\CustomerController;
use Modules\Marketplace\Drivers\MarketplaceException;
use Modules\Marketplace\Drivers\MarketplaceManager;
use Modules\Marketplace\Jobs\FetchMarketplaceListings;
use Modules\Marketplace\Jobs\PullMarketplaceOrders;
use Modules\Marketplace\Jobs\PushMarketplaceStock;
use Modules\Marketplace\Models\MarketplaceOrder;
use Modules\Marketplace\Models\MarketplaceShop;
use Modules\Marketplace\Services\MarketplaceStockService;
use Modules\Order\Services\PriceResolver;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * "Cấu hình" of Sàn TMĐT: connecting a shop (the seller authorises EXSO on
 * the platform's page, which sends the browser back with a code), the
 * settings of each shop like Sapo's "Cấu hình riêng từng gian hàng",
 * re-authorising and disconnecting. Shops are limited to the user's branches.
 */
class ShopController extends Controller
{
    public function __construct(protected MarketplaceManager $drivers) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('marketplaces/settings', [
            'shops' => MarketplaceShop::query()->visibleTo($user)->with('branch:id,name')->orderBy('id')->get()
                ->map(fn (MarketplaceShop $shop): array => $this->summary($shop))->all(),
            'platforms' => self::platforms(),
            'branches' => self::branches($user),
        ]);
    }

    public function show(Request $request, MarketplaceShop $shop): Response
    {
        $this->authorizeShop($request, $shop);
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('marketplaces/shop', [
            'shop' => [
                ...$this->summary($shop),
                'partner_id' => $shop->credential('partner_id'),
                'partner_key' => $shop->maskedCredential('partner_key'),
                'webhook_url' => $shop->webhook_token === null ? null : route('marketplaces.webhook', $shop->webhook_token),
                'token_expires_at' => $shop->token_expires_at?->toIso8601String(),
                'refresh_expires_at' => $shop->refresh_expires_at?->toIso8601String(),
                'settings' => [
                    'price_policy' => $shop->setting('price_policy'),
                    'assigned_to' => $shop->setting('assigned_to'),
                    'source_id' => $shop->setting('source_id'),
                    'payment_method_id' => $shop->setting('payment_method_id'),
                    'sync_orders' => (bool) $shop->setting('sync_orders', true),
                    'sync_stock' => (bool) $shop->setting('sync_stock', true),
                    'stock_branch_ids' => $shop->stockBranchIds(),
                    'auto_receive_return' => (bool) $shop->setting('auto_receive_return', false),
                ],
            ],
            'shops' => MarketplaceShop::query()->visibleTo($user)->orderBy('id')->get(['id', 'name', 'platform'])
                ->map(fn (MarketplaceShop $item): array => ['id' => $item->id, 'name' => $item->name, 'platform' => $item->platform])->all(),
            'branches' => self::branches($user),
            'pricePolicies' => CustomerController::pricePolicies(),
            'staff' => User::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'sources' => SalesSource::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => PaymentMethod::query()->where('is_active', true)->orderBy('sort')->get(['id', 'name']),
        ]);
    }

    /**
     * "Kết nối gian hàng mới": keep the app keys, then send the browser to
     * the platform to authorise. The way back is a signed URL valid 30 minutes.
     */
    public function store(Request $request): HttpResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'platform' => ['required', Rule::in(array_keys($this->drivers->drivers()))],
            'partner_id' => ['required', 'string', 'regex:/^\d{1,20}$/'],
            'partner_key' => ['required', 'string', 'max:255'],
            'branch_id' => ['required', 'integer', Rule::in($user->branchIds())],
            'is_sandbox' => ['boolean'],
        ], [], ['partner_id' => 'Partner ID', 'partner_key' => 'Partner Key', 'branch_id' => 'chi nhánh nhận đơn']);

        $shop = new MarketplaceShop([
            'platform' => $data['platform'],
            'name' => $this->drivers->label($data['platform']).' (chờ cấp quyền)',
            'status' => MarketplaceShop::PENDING,
            'branch_id' => (int) $data['branch_id'],
            'is_sandbox' => (bool) ($data['is_sandbox'] ?? false),
            'created_by' => $user->id,
        ]);
        $shop->credentials = ['partner_id' => trim($data['partner_id']), 'partner_key' => trim($data['partner_key'])];
        $shop->webhook_token = Str::random(48);
        $shop->save();

        return $this->authorizeRedirect($shop);
    }

    /**
     * "Cấp quyền lại": authorise again (after 30 days, or new app keys).
     */
    public function reauthorize(Request $request, MarketplaceShop $shop): HttpResponse
    {
        $this->authorizeShop($request, $shop);
        $data = $request->validate([
            'partner_id' => ['nullable', 'string', 'regex:/^\d{1,20}$/'],
            'partner_key' => ['nullable', 'string', 'max:255'],
            'is_sandbox' => ['boolean'],
        ]);

        $credentials = $shop->credentials ?? [];

        if (! empty($data['partner_id'])) {
            $credentials['partner_id'] = trim($data['partner_id']);
        }

        if (! empty($data['partner_key'])) {
            $credentials['partner_key'] = trim($data['partner_key']);
        }

        $shop->credentials = $credentials;

        if (array_key_exists('is_sandbox', $data)) {
            $shop->is_sandbox = (bool) $data['is_sandbox'];
        }

        $shop->save();

        return $this->authorizeRedirect($shop);
    }

    /**
     * The platform sends the browser back here with the authorisation code.
     */
    public function callback(Request $request, MarketplaceShop $shop): RedirectResponse
    {
        abort_unless($request->hasValidSignatureWhileIgnoring(['code', 'shop_id', 'main_account_id']), 403);
        $this->authorizeShop($request, $shop);

        try {
            $result = $this->drivers->for($shop)->connect($request->query());
        } catch (MarketplaceException $exception) {
            $shop->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 500)])->save();
            $this->toast($exception->getMessage(), 'error');

            return to_route('marketplaces.settings');
        }

        // The same shop connected before: keep that one (its links and orders).
        $existing = MarketplaceShop::query()->where('platform', $shop->platform)->where('external_id', $result['external_id'])->whereKeyNot($shop->id)->first();
        $target = $existing ?? $shop;

        DB::transaction(function () use ($shop, $target, $existing, $result): void {
            if ($existing !== null) {
                $existing->credentials = $shop->credentials;
                $existing->forceFill([
                    'token_expires_at' => $shop->token_expires_at,
                    'refresh_expires_at' => $shop->refresh_expires_at,
                    'is_sandbox' => $shop->is_sandbox,
                ]);
                $shop->delete();
            }

            $target->forceFill([
                'external_id' => $result['external_id'],
                'name' => $target->status === MarketplaceShop::PENDING ? mb_substr($result['name'], 0, 255) : $target->name,
                'status' => MarketplaceShop::ACTIVE,
                'last_error' => null,
                'connected_at' => $target->connected_at ?? now(),
                'settings' => $target->settings ?? $this->defaults($target),
            ]);
            $target->webhook_token ??= Str::random(48);
            $target->save();
        });

        FetchMarketplaceListings::dispatch((int) $target->tenant_id, $target->id);
        PullMarketplaceOrders::dispatch((int) $target->tenant_id, $target->id, 72);
        $this->toast("Đã kết nối gian hàng {$target->name}. Sản phẩm và đơn hàng 3 ngày gần nhất đang được tải về.");

        return to_route('marketplaces.shops.show', $target);
    }

    public function update(Request $request, MarketplaceShop $shop, MarketplaceStockService $stock): RedirectResponse
    {
        $this->authorizeShop($request, $shop);
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:32'],
            'branch_id' => ['required', 'integer', Rule::in($user->branchIds())],
            'price_policy' => ['nullable', 'string', 'max:30'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', tenancy()->id())],
            'source_id' => ['nullable', 'integer', Rule::exists('sales_sources', 'id')->where('tenant_id', tenancy()->id())],
            'payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')->where('tenant_id', tenancy()->id())],
            'sync_orders' => ['boolean'],
            'sync_stock' => ['boolean'],
            'stock_branch_ids' => ['array'],
            'stock_branch_ids.*' => ['integer', Rule::in($user->branchIds())],
            'auto_receive_return' => ['boolean'],
        ], [], ['name' => 'tên rút gọn', 'branch_id' => 'chi nhánh nhận đơn']);

        if (! empty($data['price_policy']) && app(PriceResolver::class)->policyFromKey($data['price_policy']) === null) {
            throw ValidationException::withMessages(['price_policy' => 'Chính sách giá không hợp lệ.']);
        }

        $before = $shop->stockBranchIds();
        $shop->fill([
            'name' => trim($data['name']),
            'branch_id' => (int) $data['branch_id'],
            'settings' => [
                ...($shop->settings ?? []),
                'price_policy' => $data['price_policy'] ?: null,
                'assigned_to' => $data['assigned_to'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'sync_orders' => (bool) ($data['sync_orders'] ?? true),
                'sync_stock' => (bool) ($data['sync_stock'] ?? true),
                'stock_branch_ids' => array_values(array_unique(array_map('intval', $data['stock_branch_ids'] ?? []))),
                'auto_receive_return' => (bool) ($data['auto_receive_return'] ?? false),
            ],
        ])->save();

        // Other branches, or stock sync turned on: push every linked product again.
        if ($shop->isActive() && $shop->setting('sync_stock') && $before !== $shop->stockBranchIds()) {
            $stock->markAll($shop);
            PushMarketplaceStock::dispatch((int) $shop->tenant_id, $shop->id);
        }

        $this->toast("Đã lưu cấu hình gian hàng {$shop->name}.");

        return back();
    }

    /**
     * Stop syncing; the tokens are forgotten, links and orders are kept.
     */
    public function disconnect(Request $request, MarketplaceShop $shop): RedirectResponse
    {
        $this->authorizeShop($request, $shop);
        $credentials = $shop->credentials ?? [];
        unset($credentials['access_token'], $credentials['refresh_token']);
        $shop->credentials = $credentials;
        $shop->forceFill(['status' => MarketplaceShop::DISCONNECTED, 'token_expires_at' => null, 'refresh_expires_at' => null])->save();

        $this->toast("Đã ngắt kết nối gian hàng {$shop->name}.");

        return back();
    }

    public function destroy(Request $request, MarketplaceShop $shop): RedirectResponse
    {
        $this->authorizeShop($request, $shop);

        if (MarketplaceOrder::query()->where('shop_id', $shop->id)->whereNotNull('order_id')->exists()) {
            throw ValidationException::withMessages(['shop' => 'Gian hàng đã có đơn hàng, chỉ ngắt kết nối được.']);
        }

        $shop->delete();
        $this->toast("Đã xóa gian hàng {$shop->name}.");

        return to_route('marketplaces.settings');
    }

    /**
     * A new push URL (the old one stops working).
     */
    public function rotateWebhook(Request $request, MarketplaceShop $shop): RedirectResponse
    {
        $this->authorizeShop($request, $shop);
        $shop->webhook_token = Str::random(48);
        $shop->save();
        $this->toast('Đã tạo địa chỉ nhận thông báo mới. Hãy cập nhật lại trong ứng dụng trên sàn.');

        return back();
    }

    protected function authorizeRedirect(MarketplaceShop $shop): HttpResponse
    {
        $back = URL::temporarySignedRoute('marketplaces.shops.callback', now()->addMinutes(30), ['shop' => $shop->id]);

        try {
            return Inertia::location($this->drivers->for($shop)->authorizeUrl($back));
        } catch (MarketplaceException $exception) {
            throw ValidationException::withMessages(['partner_id' => $exception->getMessage()]);
        }
    }

    /**
     * Settings of a new shop: orders with the platform's prices, stock of
     * the receiving branch, paid with the platform's wallet when completed.
     *
     * @return array<string, mixed>
     */
    protected function defaults(MarketplaceShop $shop): array
    {
        $label = $this->drivers->label($shop->platform);

        return [
            'price_policy' => null,
            'assigned_to' => $shop->created_by,
            'source_id' => SalesSource::query()->where('code', strtoupper($shop->platform))->value('id'),
            'payment_method_id' => PaymentMethod::query()->where('name', "Ví {$label}")->value('id'),
            'sync_orders' => true,
            'sync_stock' => true,
            'stock_branch_ids' => [$shop->branch_id],
            'auto_receive_return' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(MarketplaceShop $shop): array
    {
        return [
            'id' => $shop->id,
            'platform' => $shop->platform,
            'platform_label' => app(MarketplaceManager::class)->label($shop->platform),
            'external_id' => $shop->external_id,
            'name' => $shop->name,
            'status' => $shop->status,
            'branch_id' => $shop->branch_id,
            'branch' => $shop->branch->name,
            'is_sandbox' => $shop->is_sandbox,
            'last_error' => $shop->last_error,
            'connected_at' => $shop->connected_at?->toIso8601String(),
            'orders_synced_at' => $shop->orders_synced_at?->toIso8601String(),
            'products_synced_at' => $shop->products_synced_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function platforms(): array
    {
        $drivers = app(MarketplaceManager::class);

        return array_map(fn (string $key): array => ['value' => $key, 'label' => $drivers->label($key)], array_keys($drivers->drivers()));
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public static function branches(User $user): array
    {
        return array_values(Branch::query()->whereIn('id', $user->branchIds())->orderByDesc('is_default')->orderBy('name')->get(['id', 'name'])
            ->map(fn (Branch $branch): array => ['id' => $branch->id, 'name' => $branch->name])->all());
    }

    public static function authorizeShop(Request $request, MarketplaceShop $shop): void
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(in_array($shop->branch_id, $user->branchIds(), true), 403);
    }
}
