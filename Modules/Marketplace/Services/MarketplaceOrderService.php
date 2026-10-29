<?php

namespace Modules\Marketplace\Services;

use App\Models\OrderReason;
use App\Models\SalesSource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Customer\Models\Customer;
use Modules\Customer\Services\CustomerService;
use Modules\Marketplace\Drivers\MarketplaceException;
use Modules\Marketplace\Drivers\MarketplaceManager;
use Modules\Marketplace\Drivers\PlatformOrder;
use Modules\Marketplace\Models\MarketplaceListing;
use Modules\Marketplace\Models\MarketplaceOrder;
use Modules\Marketplace\Models\MarketplaceShop;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;
use Modules\Product\Models\ProductVariant;
use Throwable;

/**
 * Brings marketplace orders into EXSO and keeps them following the
 * platform, always from what the platform's API says (never from a push
 * payload). Running it again with the same data changes nothing:
 *
 *  - to_ship (Chờ lấy hàng): the EXSO order is created (Nguồn = the
 *    platform, Mã tham chiếu = platform order number) and approved, so the
 *    goods are held and "Có thể bán" pushed to the platform goes down
 *  - shipped: the goods leave the stock ("Xuất kho")
 *  - completed: delivered; the money is recorded with the shop's payment method
 *  - cancelled: cancelled in EXSO; a parcel already sent waits to be
 *    received back ("Hủy giao - chờ nhận") unless the shop receives returns
 *    by itself
 *
 * Lines need the platform products to be linked to EXSO variants (or have
 * an EXSO SKU); otherwise the order waits as "Chưa liên kết sản phẩm" and
 * is brought in once linked.
 */
class MarketplaceOrderService
{
    public function __construct(
        protected OrderService $orders,
        protected MarketplaceManager $drivers,
        protected CustomerService $customers,
    ) {}

    /**
     * Fetch orders from the platform and follow them. Errors of one order
     * are kept on it and do not stop the others.
     *
     * @param  list<string>  $ids
     * @return list<MarketplaceOrder>
     */
    public function syncIds(MarketplaceShop $shop, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = [];

        foreach ($this->drivers->for($shop)->orders($ids) as $data) {
            $rows[] = $this->sync($shop, $data);
        }

        return $rows;
    }

    /**
     * Orders changed on the platform since the last run (with a margin),
     * plus those that failed or waited for a link.
     *
     * @return array{synced: int, failed: int}
     */
    public function pull(MarketplaceShop $shop, ?int $hours = null): array
    {
        $to = now()->toImmutable();
        $from = $hours !== null
            ? $to->subHours($hours)
            : ($shop->orders_synced_at?->subMinutes(10) ?? $to->subDays(3));

        $ids = $this->drivers->for($shop)->changedOrders($from->max($to->subDays(15)), $to);
        $waiting = MarketplaceOrder::query()
            ->where('shop_id', $shop->id)
            ->whereIn('sync_status', [MarketplaceOrder::ERROR, MarketplaceOrder::UNLINKED])
            ->where('attempts', '<', 50)
            ->where('updated_at', '>', $to->subDays(15))
            ->limit(100)
            ->pluck('external_id')
            ->all();

        $rows = $this->syncIds($shop, array_values(array_unique([...$ids, ...$waiting])));
        $shop->forceFill(['orders_synced_at' => $to, 'last_error' => null])->save();

        $failed = count(array_filter($rows, fn (MarketplaceOrder $row): bool => $row->sync_status !== MarketplaceOrder::SYNCED));

        return ['synced' => count($rows) - $failed, 'failed' => $failed];
    }

    /**
     * Follow one platform order. The marketplace order row is locked, so a
     * push and the scheduled pull never create the EXSO order twice.
     */
    public function sync(MarketplaceShop $shop, PlatformOrder $data): MarketplaceOrder
    {
        return DB::transaction(function () use ($shop, $data): MarketplaceOrder {
            $row = MarketplaceOrder::query()->where('shop_id', $shop->id)->where('external_id', $data->id)->lockForUpdate()->first();

            if ($row === null) {
                $row = MarketplaceOrder::query()->create(['shop_id' => $shop->id, 'external_id' => $data->id]);
                $row = MarketplaceOrder::query()->lockForUpdate()->findOrFail($row->id);
            }

            $row->forceFill([
                'platform_status' => $data->status,
                'state' => $data->state,
                'buyer_id' => $data->buyerId,
                'buyer_username' => $data->buyerUsername,
                'recipient_name' => $data->recipientName,
                'total' => $data->total,
                'carrier' => $data->carrier,
                'tracking_code' => $data->trackingCode,
                'items' => $data->items,
                'placed_at' => $data->placedAt,
                'platform_updated_at' => $data->updatedAt,
                'synced_at' => now(),
            ]);

            try {
                $this->advance($shop, $row, $data);
                $row->forceFill(['sync_status' => MarketplaceOrder::SYNCED, 'error' => null, 'attempts' => 0]);
            } catch (UnlinkedProducts $exception) {
                $row->forceFill(['sync_status' => MarketplaceOrder::UNLINKED, 'error' => mb_substr($exception->getMessage(), 0, 500)]);
            } catch (ValidationException $exception) {
                $this->failed($row, implode(' ', array_map(fn (array $messages): string => (string) ($messages[0] ?? ''), $exception->errors())));
            } catch (MarketplaceException $exception) {
                $this->failed($row, $exception->getMessage());
            }

            $row->save();

            return $row;
        });
    }

    /**
     * Move the EXSO order to where the platform order is.
     */
    protected function advance(MarketplaceShop $shop, MarketplaceOrder $row, PlatformOrder $data): void
    {
        $order = $row->order_id === null ? null : Order::query()->find($row->order_id);

        if ($order === null) {
            // Unpaid orders may never be paid; cancelled or returned ones were never ours.
            if (! in_array($data->state, ['to_ship', 'shipped', 'completed'], true)) {
                return;
            }

            $order = $this->create($shop, $data);
            $row->order_id = $order->id;
        }

        if ($order->status === Order::CANCELLED) {
            return;
        }

        $this->follow($order, $data);

        match ($data->state) {
            'to_ship' => $this->approve($order),
            'shipped' => $this->ship($order),
            'completed' => $this->complete($shop, $order),
            'cancelled' => $this->cancel($shop, $order),
            default => null,
        };
    }

    protected function create(MarketplaceShop $shop, PlatformOrder $data): Order
    {
        $user = $this->actor($shop);
        $platform = $this->drivers->label($shop->platform);
        $trusted = $shop->setting('price_policy') === null;

        return $this->orders->create([
            'branch_id' => $shop->branch_id,
            'customer_id' => $this->customer($shop, $data)?->id,
            'source_id' => $this->sourceId($shop),
            'assigned_to' => $user->id,
            'price_policy' => $shop->setting('price_policy'),
            'delivery_method' => 'external',
            'carrier_name' => $data->carrier ?? $platform,
            'tracking_code' => $data->trackingCode,
            'receiver_name' => $data->recipientName,
            'receiver_phone' => $data->recipientPhone === null ? null : mb_substr($data->recipientPhone, 0, 20),
            'shipping_address' => $data->address === null ? null : mb_substr($data->address, 0, 500),
            'note' => $data->note,
            'reference' => $data->id,
            'tags' => $platform,
            'channel' => mb_substr($shop->platform, 0, 20),
            'items' => $this->lines($shop, $data, $trusted),
        ], $user, null, ['trusted_prices' => $trusted, 'skip_stock_check' => true]);
    }

    /**
     * EXSO lines for the platform lines, through the linked listings. An
     * unlinked listing is linked when an EXSO variant has the same SKU.
     *
     * @return list<array{variant_id: int, quantity: int, price?: int}>
     */
    protected function lines(MarketplaceShop $shop, PlatformOrder $data, bool $trusted): array
    {
        $lines = [];
        $missing = [];

        foreach ($data->items as $item) {
            $listing = MarketplaceListing::query()->firstOrCreate(
                ['shop_id' => $shop->id, 'item_id' => $item['item_id'], 'model_id' => $item['model_id']],
                ['name' => mb_substr($item['name'], 0, 255), 'sku' => $item['sku'], 'price' => $item['price']],
            );

            if ($listing->variant_id === null && $listing->sku !== null) {
                app(MarketplaceListingService::class)->autoLink($listing);
            }

            if ($listing->variant_id === null) {
                $missing[] = $item['name'].($item['sku'] !== null ? " ({$item['sku']})" : '');

                continue;
            }

            $lines[] = [
                'variant_id' => $listing->variant_id,
                'quantity' => $item['quantity'],
                ...($trusted ? ['price' => $item['price']] : []),
            ];
        }

        if ($missing !== []) {
            throw new UnlinkedProducts('Sản phẩm chưa liên kết: '.implode('; ', $missing).'.');
        }

        if ($lines === []) {
            throw new UnlinkedProducts('Đơn sàn không có sản phẩm.');
        }

        return $lines;
    }

    /**
     * Carrier and tracking code follow the platform until the order is exported.
     */
    protected function follow(Order $order, PlatformOrder $data): void
    {
        if ($order->isEditable() && $data->trackingCode !== null && $order->tracking_code !== $data->trackingCode) {
            $order->forceFill([
                'tracking_code' => mb_substr($data->trackingCode, 0, 100),
                'carrier_name' => $data->carrier === null ? $order->carrier_name : mb_substr($data->carrier, 0, 100),
            ])->save();
        }
    }

    protected function approve(Order $order): void
    {
        if ($order->status === Order::PENDING) {
            $this->orders->approve($order);
            $order->refresh();
        }
    }

    protected function ship(Order $order): void
    {
        $this->approve($order);

        if ($order->holdsStock()) {
            $this->orders->export($order);
            $order->refresh();
        }
    }

    protected function complete(MarketplaceShop $shop, Order $order): void
    {
        $this->ship($order);

        if ($order->status === Order::SHIPPED && $order->delivery_status === 'shipping') {
            $this->orders->markDelivered($order);
            $order->refresh();
        }

        // The platform pays the store: record it once, with the shop's method.
        $method = $shop->setting('payment_method_id');

        if ($method !== null && $order->status === Order::COMPLETED && $order->remaining() > 0) {
            $this->orders->pay($order, (int) $method, $order->remaining(), 'Sàn '.$this->drivers->label($shop->platform).' thanh toán');
        }
    }

    protected function cancel(MarketplaceShop $shop, Order $order): void
    {
        if ($order->status === Order::COMPLETED) {
            return;
        }

        $note = 'Đơn bị hủy trên '.$this->drivers->label($shop->platform);

        if (! $order->isExported()) {
            $this->orders->cancel($order, $this->cancelReason(), $note);

            return;
        }

        // The parcel already left: it comes back first.
        if ($order->status === Order::SHIPPED && $order->delivery_status === 'shipping') {
            $this->orders->markDeliveryFailed($order, $note);
            $order->refresh();
        }

        if ((bool) $shop->setting('auto_receive_return', false) && $order->delivery_status === 'returning') {
            $this->orders->receiveBack($order);
            $this->orders->cancel($order->refresh(), $this->cancelReason(), $note);
        }
    }

    /**
     * The buyer as an EXSO customer, kept by their platform id (names and
     * phones come masked, e.g. "L******h").
     */
    protected function customer(MarketplaceShop $shop, PlatformOrder $data): ?Customer
    {
        if ($data->buyerId === null && $data->buyerUsername === null) {
            return null;
        }

        $key = $data->buyerId ?? $data->buyerUsername;
        $customer = Customer::query()->withTrashed()->where('source', $shop->platform)->where('marketplace_id', $key)->first();

        if ($customer !== null) {
            return $customer->trashed() ? null : $customer;
        }

        return $this->customers->save(null, [
            'name' => mb_substr($data->buyerUsername ?? $data->recipientName ?? "Khách {$this->drivers->label($shop->platform)}", 0, 255),
            'source' => $shop->platform,
            'marketplace_id' => mb_substr((string) $key, 0, 100),
            'description' => 'Khách mua trên '.$this->drivers->label($shop->platform),
        ]);
    }

    protected function sourceId(MarketplaceShop $shop): ?int
    {
        $id = $shop->setting('source_id');

        if ($id !== null && SalesSource::query()->whereKey($id)->exists()) {
            return (int) $id;
        }

        $id = SalesSource::query()->where('code', strtoupper($shop->platform))->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Who the order is made by: the staff in charge chosen for the shop,
     * else whoever connected it, else the store owner.
     */
    protected function actor(MarketplaceShop $shop): User
    {
        $user = User::query()->whereKey(array_filter([$shop->setting('assigned_to'), $shop->created_by]))->get()
            ->sortBy(fn (User $user): int => $user->id === (int) $shop->setting('assigned_to') ? 0 : 1)
            ->first();

        return $user ?? User::query()->where('is_owner', true)->firstOrFail();
    }

    protected function cancelReason(): ?int
    {
        if (! settings('reasons.require_cancel')) {
            return null;
        }

        $id = OrderReason::query()->where('type', 'cancel')->where('is_active', true)->orderByDesc('sort')->value('id');

        return $id === null ? null : (int) $id;
    }

    protected function failed(MarketplaceOrder $row, string $message): void
    {
        $row->forceFill([
            'sync_status' => MarketplaceOrder::ERROR,
            'error' => mb_substr(trim($message) ?: 'Không đồng bộ được đơn.', 0, 500),
            'attempts' => $row->attempts + 1,
        ]);
    }

    /**
     * Try again the orders of the shop that waited for a product link.
     */
    public function retryUnlinked(MarketplaceShop $shop): void
    {
        $ids = MarketplaceOrder::query()->where('shop_id', $shop->id)->where('sync_status', MarketplaceOrder::UNLINKED)->limit(200)->pluck('external_id')->all();

        if ($ids === []) {
            return;
        }

        try {
            $this->syncIds($shop, $ids);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
