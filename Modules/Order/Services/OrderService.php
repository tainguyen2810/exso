<?php

namespace Modules\Order\Services;

use App\Models\OrderReason;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Hooks\Hook;
use App\Support\Sequences\Sequence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerDebt;
use Modules\Customer\Services\CustomerLedger;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\InventoryService;
use Modules\Order\Contracts\OrderPromotions;
use Modules\Order\Contracts\PromotionContext;
use Modules\Order\Contracts\PromotionOutcome;
use Modules\Order\Events\OrderPaymentRecorded;
use Modules\Order\Events\OrderStatusChanged;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderHistory;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\OrderPayment;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;

/**
 * Everything that changes an order. Totals are computed here from the
 * lines (never taken from the browser), every step locks the order row,
 * stock moves only through InventoryService and the customer's debt only
 * through CustomerLedger:
 *
 *  - approve: stock is reserved (Có thể bán goes down)
 *  - export ("Xuất kho"): reservation released, stock taken, debt recorded
 *  - cancel: reservation released, or goods put back if already exported;
 *    money received is refunded
 *  - returns: see ReturnService
 *
 * Promotions (G5) come from OrderPromotions: line discounts, gift lines
 * and an order discount, recomputed here on every save.
 *
 * @phpstan-import-type Policy from PriceResolver
 *
 * @phpstan-type LineInput array{variant_id: int|null, name?: string|null, quantity: int, price?: int|null, discount?: int, note?: string|null}
 * @phpstan-type PaymentInput array{payment_method_id: int, amount: int}
 * @phpstan-type OrderInput array{branch_id: int, customer_id?: int|null, source_id?: int|null, assigned_to?: int|null, price_policy?: string|null, delivery_method?: string, carrier_name?: string|null, tracking_code?: string|null, receiver_name?: string|null, receiver_phone?: string|null, shipping_address?: string|null, shipping_fee?: int, discount?: int, discount_bp?: int|null, note?: string|null, tags?: string|null, reference?: string|null, expected_delivery_at?: string|null, sale_at?: string|null, items: list<LineInput>, payments?: list<PaymentInput>, channel?: string, pos_shift_id?: int|null, promotion_ids?: list<int>, coupon_code?: string|null, gifts?: array<string, int>}
 * @phpstan-type Line array{variant_id: int|null, product_id: int|null, product_type: string, name: string, sku: string|null, unit: string|null, quantity: int, price: int, discount: int, total: int, note: string|null, promotion_id?: int|null, promotion_discount?: int, is_gift?: bool}
 */
class OrderService
{
    public function __construct(
        protected InventoryService $inventory,
        protected CustomerLedger $ledger,
        protected PriceResolver $prices,
        protected OrderPromotions $promotions,
    ) {}

    /**
     * Create an order. $then moves it on right away: "approve" (Tạo và
     * duyệt), "export" (approve and take the goods out, e.g. the customer
     * takes them at the counter).
     *
     * Options, for orders that come from another system (marketplaces), never
     * from a browser: `trusted_prices` keeps the line prices given (the
     * buyer already paid them), `skip_stock_check` saves the order even when
     * stock is short (it was already sold).
     *
     * @param  OrderInput  $data
     * @param  array{trusted_prices?: bool, skip_stock_check?: bool}  $options
     */
    public function create(array $data, User $user, ?string $then = null, array $options = []): Order
    {
        $customer = $this->customer($data['customer_id'] ?? null);
        $policy = $this->policy($data, $customer);
        $lines = $this->lines($data['items'], $policy, $user, null, (bool) ($options['trusted_prices'] ?? false));
        [$lines, $outcome] = $this->promote($data, $lines, $customer, true);
        $totals = $this->totals($lines, (int) ($data['discount'] ?? 0), $data['discount_bp'] ?? null, (int) ($data['shipping_fee'] ?? 0), $outcome->orderDiscount);

        if (! settings('sales.allow_negative_order') && ! ($options['skip_stock_check'] ?? false)) {
            $this->checkAvailable($lines, (int) $data['branch_id']);
        }

        $order = DB::transaction(function () use ($data, $user, $customer, $policy, $lines, $totals, $outcome, $then): Order {
            $order = new Order([
                ...$this->header($data, $customer, $policy, $user),
                'code' => Sequence::next('SON', Order::class),
                'channel' => $data['channel'] ?? 'web',
                'created_by' => $user->id,
                'pos_shift_id' => $data['pos_shift_id'] ?? null,
            ]);
            $order->forceFill([...$totals, 'promotions' => $this->chosen($data, $outcome), 'status' => Order::PENDING])->save();
            $this->saveLines($order, $lines);
            $this->promotions->record($order, $outcome);
            $this->history($order, 'created', $order->channel === 'pos' ? 'Bán tại quầy' : null);

            // Money given beyond the total is change handed back, not recorded.
            $left = $order->total;

            foreach ($data['payments'] ?? [] as $payment) {
                $amount = min((int) $payment['amount'], $left);

                if ($amount > 0) {
                    $this->pay($order, (int) $payment['payment_method_id'], $amount);
                    $left -= $amount;
                }
            }

            if ($then !== null) {
                $this->approve($order);
            }

            if ($then === 'export') {
                $this->export($order);
            }

            $this->refreshCustomer($order->customer_id);

            return $order;
        });

        return $order->refresh();
    }

    /**
     * Change an order before export. An approved order gives back its
     * reservation and reserves the new lines.
     *
     * @param  OrderInput  $data
     */
    public function update(Order $order, array $data, User $user): Order
    {
        $customer = $this->customer($data['customer_id'] ?? null);
        $policy = $this->policy($data, $customer);
        $lines = $this->lines($data['items'], $policy, $user, $order);
        [$lines, $outcome] = $this->promote($data, $lines, $customer, true, $order);
        $totals = $this->totals($lines, (int) ($data['discount'] ?? 0), $data['discount_bp'] ?? null, (int) ($data['shipping_fee'] ?? 0), $outcome->orderDiscount);

        DB::transaction(function () use ($order, $data, $user, $customer, $policy, $lines, $totals, $outcome): void {
            $locked = $this->lock($order);

            if (! $locked->isEditable()) {
                throw ValidationException::withMessages(['status' => 'Đơn đã xuất kho hoặc đã hủy, không sửa được. Hàng đã xuất muốn đổi thì tạo đơn trả hàng.']);
            }

            $this->assertNotLocked($locked);

            if ($totals['total'] < $locked->paid) {
                throw ValidationException::withMessages(['items' => 'Tổng tiền mới nhỏ hơn số khách đã trả ('.number_format($locked->paid, 0, ',', '.').'). Hãy hoàn tiền trước.']);
            }

            if ((int) $data['branch_id'] !== $locked->branch_id && $locked->holdsStock()) {
                throw ValidationException::withMessages(['branch_id' => 'Đơn đã duyệt, không đổi chi nhánh được.']);
            }

            if ($locked->holdsStock()) {
                $this->changeReservation($locked, -1);
            }

            $before = $locked->customer_id;
            $header = $this->header($data, $customer, $policy, $user, $locked);
            unset($header['sale_at']);
            $locked->fill($header)->forceFill([...$totals, 'promotions' => $this->chosen($data, $outcome)])->save();
            $this->saveLines($locked, $lines);
            // Usage is counted again for what the order has now.
            $this->promotions->release($locked);
            $this->promotions->record($locked, $outcome);

            if ($locked->holdsStock()) {
                if (! settings('sales.approve_negative_order')) {
                    $this->checkAvailable($lines, $locked->branch_id);
                }

                $this->changeReservation($locked->load('items'), 1);
            }

            $this->history($locked, 'updated');
            $this->refreshCustomer($locked->customer_id);

            if ($before !== $locked->customer_id) {
                $this->refreshCustomer($before);
            }

            $order->setRawAttributes($locked->getAttributes(), true);
        });

        return $order->refresh();
    }

    /** Duyệt: the goods are held for the order. */
    public function approve(Order $order): void
    {
        $this->step($order, 'approved', function (Order $locked): void {
            if ($locked->status !== Order::PENDING) {
                throw ValidationException::withMessages(['status' => 'Đơn hàng đã được duyệt.']);
            }

            if (! settings('sales.approve_negative_order')) {
                $this->checkAvailable($this->linesOf($locked), $locked->branch_id);
            }

            $this->changeReservation($locked, 1);
            $locked->forceFill(['status' => Order::APPROVED, 'approved_at' => now()])->save();
        });
    }

    /** Đóng gói. */
    public function pack(Order $order): void
    {
        $this->step($order, 'packed', function (Order $locked): void {
            if ($locked->status === Order::PENDING) {
                throw ValidationException::withMessages(['status' => 'Đơn hàng chưa được duyệt.']);
            }

            if ($locked->status !== Order::APPROVED) {
                throw ValidationException::withMessages(['status' => 'Đơn hàng đã đóng gói.']);
            }

            $locked->forceFill(['status' => Order::PACKED, 'packed_at' => now()])->save();
        });
    }

    /**
     * Xuất kho: the reservation becomes a real stock movement, each line
     * keeps its unit cost and the customer owes the order total. An order
     * the customer takes at the counter is completed at the same time.
     */
    public function export(Order $order): void
    {
        $this->step($order, 'shipped', function (Order $locked): void {
            if ($locked->status === Order::PENDING) {
                throw ValidationException::withMessages(['status' => 'Đơn hàng chưa được duyệt.']);
            }

            if (! $locked->holdsStock()) {
                throw ValidationException::withMessages(['status' => 'Đơn hàng đã xuất kho hoặc đã hủy.']);
            }

            $this->changeReservation($locked, -1);
            $costTotal = 0;

            foreach ($this->stockItems($locked) as $item) {
                /** @var ProductVariant $variant */
                $variant = $item->variant;
                $cost = $this->unitCost($variant);
                $this->inventory->move($locked->branch_id, $variant, -$item->quantity, StockMovement::SALE, [
                    'reference' => $locked,
                    'reference_code' => $locked->code,
                    'allow_negative' => (bool) settings('sales.allow_negative_export'),
                ]);
                $item->forceFill(['cost' => $cost])->save();
                $costTotal += $cost * $item->quantity;
            }

            $now = now();
            $pickup = $locked->delivery_method === 'pickup';
            $locked->forceFill([
                'status' => $pickup ? Order::COMPLETED : Order::SHIPPED,
                'packed_at' => $locked->packed_at ?? $now,
                'shipped_at' => $now,
                'completed_at' => $pickup ? $now : null,
                'delivery_status' => $pickup ? 'delivered' : 'shipping',
                'cost_total' => $costTotal,
            ])->save();

            $this->debt($locked, CustomerDebt::SALE, $locked->total - $locked->returned_total);
        });
    }

    /** Giao hàng thành công: the order is completed. */
    public function markDelivered(Order $order): void
    {
        $this->step($order, 'delivered', function (Order $locked): void {
            if ($locked->status !== Order::SHIPPED) {
                throw ValidationException::withMessages(['status' => 'Đơn hàng không ở trạng thái đang giao.']);
            }

            $locked->forceFill(['status' => Order::COMPLETED, 'delivery_status' => 'delivered', 'completed_at' => now()])->save();
        });
    }

    /** Giao hàng thất bại: the parcel is on its way back ("Hủy giao - chờ nhận"). */
    public function markDeliveryFailed(Order $order, ?string $note = null): void
    {
        $this->step($order, 'delivery_failed', function (Order $locked): void {
            if ($locked->status !== Order::SHIPPED || $locked->delivery_status !== 'shipping') {
                throw ValidationException::withMessages(['status' => 'Đơn hàng không ở trạng thái đang giao.']);
            }

            $locked->forceFill(['delivery_status' => 'returning'])->save();
        }, $note);
    }

    /**
     * The failed parcel is back: goods return to stock and are held again,
     * the debt of the export is reversed; the order can be shipped again or cancelled.
     */
    public function receiveBack(Order $order): void
    {
        $this->step($order, 'received_back', function (Order $locked): void {
            if ($locked->status !== Order::SHIPPED || $locked->delivery_status !== 'returning') {
                throw ValidationException::withMessages(['status' => 'Đơn hàng không chờ nhận lại.']);
            }

            $this->restock($locked, 'Nhận lại hàng giao thất bại');
            $this->debt($locked, CustomerDebt::CANCEL, -($locked->total - $locked->returned_total), 'Giao hàng thất bại');
            $locked->forceFill(['status' => Order::PACKED, 'delivery_status' => null, 'shipped_at' => null, 'cost_total' => 0])->save();
            $this->changeReservation($locked, 1);
        });
    }

    /**
     * A shipment was handed to a partner (G6): the order shows the partner
     * and tracking code, and an approved order is packed ("Đóng gói").
     */
    public function assignShipment(Order $order, string $method, string $carrierName, ?string $trackingCode, string $shipmentCode): void
    {
        $this->step($order, 'shipment_created', function (Order $locked) use ($method, $carrierName, $trackingCode): void {
            if ($locked->status === Order::PENDING) {
                throw ValidationException::withMessages(['status' => 'Đơn hàng chưa được duyệt.']);
            }

            if (! in_array($locked->status, [Order::APPROVED, Order::PACKED], true)) {
                throw ValidationException::withMessages(['status' => 'Chỉ tạo phiếu giao hàng cho đơn đã duyệt, chưa xuất kho.']);
            }

            $locked->forceFill([
                'delivery_method' => array_key_exists($method, Order::DELIVERY_METHODS) ? $method : 'external',
                'carrier_name' => mb_substr($carrierName, 0, 100),
                'tracking_code' => $trackingCode === null ? null : mb_substr($trackingCode, 0, 100),
                'status' => Order::PACKED,
                'packed_at' => $locked->packed_at ?? now(),
            ])->save();
        }, implode(' - ', array_filter([$shipmentCode, $carrierName, $trackingCode])));
    }

    /**
     * The shipment was cancelled before the goods left ("Hủy đóng gói"):
     * the order goes back to approved, its goods still held.
     */
    public function cancelShipment(Order $order, string $shipmentCode): void
    {
        $this->step($order, 'shipment_cancelled', function (Order $locked): void {
            $changes = ['tracking_code' => null];

            if ($locked->status === Order::PACKED) {
                $changes += ['status' => Order::APPROVED, 'packed_at' => null];
            }

            $locked->forceFill($changes)->save();
        }, $shipmentCode);
    }

    /**
     * Hủy đơn, at any step before completed. Exported goods come back into
     * stock (when the store allows cancelling after export) and the money
     * received is refunded with the methods it came in.
     */
    public function cancel(Order $order, ?int $reasonId = null, ?string $note = null): void
    {
        if ($reasonId === null && settings('reasons.require_cancel')) {
            throw ValidationException::withMessages(['reason_id' => 'Hãy chọn lý do hủy đơn.']);
        }

        $reason = $reasonId === null ? null : OrderReason::query()->where('type', 'cancel')->find($reasonId);

        $this->step($order, 'cancelled', function (Order $locked) use ($reason, $note): void {
            if ($locked->status === Order::COMPLETED) {
                throw ValidationException::withMessages(['status' => 'Đơn đã hoàn thành, không hủy được. Hãy tạo đơn trả hàng.']);
            }

            if ($locked->status === Order::CANCELLED) {
                throw ValidationException::withMessages(['status' => 'Đơn hàng đã hủy.']);
            }

            $this->assertNotLocked($locked);

            if ($locked->holdsStock()) {
                $this->changeReservation($locked, -1);
            }

            if ($locked->isExported()) {
                if (! settings('sales.allow_cancel_after_export')) {
                    throw ValidationException::withMessages(['status' => 'Cửa hàng không cho hủy đơn đã xuất kho (Cấu hình bán hàng).']);
                }

                $this->restock($locked, 'Hủy đơn hàng');
                $this->debt($locked, CustomerDebt::CANCEL, -($locked->total - $locked->returned_total));
            }

            $this->refundAll($locked);
            $this->promotions->release($locked);

            $locked->forceFill([
                'status' => Order::CANCELLED,
                'cancelled_at' => now(),
                'cancel_reason_id' => $reason?->id,
                'cancel_note' => $note,
                'delivery_status' => null,
            ])->save();
        }, implode(' - ', array_filter([$reason?->name, $note])) ?: null);
    }

    /**
     * Money received for the order. Paying more than what is left is refused.
     */
    public function pay(Order $order, int $methodId, int $amount, ?string $note = null): OrderPayment
    {
        $method = PaymentMethod::query()->where('is_active', true)->find($methodId);

        if ($method === null) {
            throw ValidationException::withMessages(['payment_method_id' => 'Hình thức thanh toán không hợp lệ hoặc đã ngừng dùng.']);
        }

        return DB::transaction(function () use ($order, $method, $amount, $note): OrderPayment {
            $locked = $this->lock($order);

            if ($locked->status === Order::CANCELLED) {
                throw ValidationException::withMessages(['amount' => 'Đơn hàng đã hủy.']);
            }

            if ($amount <= 0 || $amount > $locked->remaining()) {
                throw ValidationException::withMessages(['amount' => 'Số tiền phải lớn hơn 0 và không quá số còn phải trả ('.number_format(max(0, $locked->remaining()), 0, ',', '.').').']);
            }

            $payment = $this->recordPayment($locked, $method, $amount, OrderPayment::PAYMENT, $note);
            $this->history($locked, 'paid', $method->name.': '.number_format($amount, 0, ',', '.'));
            $order->setRawAttributes($locked->getAttributes(), true);

            return $payment;
        });
    }

    /**
     * Pay money back to the customer (e.g. after a return), at most what
     * was paid above what they owe.
     *
     * @param  array{reference?: Model|null}  $options
     */
    public function refund(Order $order, int $methodId, int $amount, ?string $note = null, array $options = []): OrderPayment
    {
        $method = PaymentMethod::query()->find((int) $methodId);

        if ($method === null) {
            throw ValidationException::withMessages(['payment_method_id' => 'Hình thức hoàn tiền không hợp lệ.']);
        }

        return DB::transaction(function () use ($order, $method, $amount, $note, $options): OrderPayment {
            $locked = $this->lock($order);

            if ($amount <= 0 || $amount > -$locked->remaining()) {
                throw ValidationException::withMessages(['refund_amount' => 'Số tiền hoàn phải lớn hơn 0 và không quá số khách đã trả dư ('.number_format(max(0, -$locked->remaining()), 0, ',', '.').').']);
            }

            $payment = $this->recordPayment($locked, $method, -$amount, OrderPayment::REFUND, $note, $options['reference'] ?? null);
            $this->history($locked, 'refunded', $method->name.': '.number_format($amount, 0, ',', '.'));
            $order->setRawAttributes($locked->getAttributes(), true);

            return $payment;
        });
    }

    /**
     * Lock the order row; callers run inside a transaction.
     */
    public function lock(Order $order): Order
    {
        /** @var Order $locked */
        $locked = Order::query()->with(['items.variant.product', 'customer'])->lockForUpdate()->findOrFail($order->id);

        return $locked;
    }

    public function history(Order $order, string $action, ?string $note = null): void
    {
        OrderHistory::query()->create([
            'order_id' => $order->id,
            'action' => $action,
            'note' => $note === null ? null : mb_substr($note, 0, 500),
            'created_by' => auth()->id(),
            'created_at' => now(),
        ]);
    }

    /**
     * Keep the purchase totals shown on the customer up to date.
     */
    public function refreshCustomer(?int $customerId): void
    {
        if ($customerId === null) {
            return;
        }

        $orders = Order::query()->where('customer_id', $customerId)->where('status', '!=', Order::CANCELLED);

        Customer::query()->withTrashed()->whereKey($customerId)->update([
            'orders_count' => (clone $orders)->count(),
            'total_spent' => (int) (clone $orders)->whereIn('status', [Order::SHIPPED, Order::COMPLETED])->sum(DB::raw('total - returned_total')),
            'last_order_at' => (clone $orders)->max('sale_at'),
            'returned_quantity' => (int) DB::table('order_returns')->where('tenant_id', tenancy()->id())->where('customer_id', $customerId)->sum('quantity'),
        ]);
    }

    /**
     * Totals of the lines: subtotal, order discount (amount, or a
     * percentage of the subtotal), shipping fee and what the customer pays.
     * The promotions' order discount comes first; the typed discount is
     * added on top, never beyond the subtotal. `discount` is the sum.
     *
     * @param  list<array{total: int}>  $lines
     * @return array{subtotal: int, discount: int, discount_bp: int|null, promotion_discount: int, shipping_fee: int, total: int}
     */
    public function totals(array $lines, int $discount, ?int $discountBp, int $shippingFee, int $promotionDiscount = 0): array
    {
        $subtotal = array_sum(array_column($lines, 'total'));
        $discountBp = $discountBp === null ? null : min(10000, max(0, $discountBp));

        if ($discountBp !== null) {
            $discount = intdiv($subtotal * $discountBp + 5000, 10000);
        }

        $promotionDiscount = min(max(0, $promotionDiscount), $subtotal);
        $discount = min(max(0, $discount), $subtotal - $promotionDiscount) + $promotionDiscount;
        $shippingFee = max(0, $shippingFee);

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'discount_bp' => $discountBp,
            'promotion_discount' => $promotionDiscount,
            'shipping_fee' => $shippingFee,
            'total' => $subtotal - $discount + $shippingFee,
        ];
    }

    /**
     * What "Áp dụng chương trình khuyến mại" shows, for the order form and
     * POS alike: the programs the order qualifies for and the order priced
     * with the chosen ones, computed exactly as saving would.
     *
     * @param  OrderInput  $data
     * @return array<string, mixed>
     */
    public function preview(array $data, User $user, ?Order $order = null): array
    {
        $customer = $this->customer($data['customer_id'] ?? null);
        $policy = $this->policy($data, $customer);
        $lines = $data['items'] === [] ? [] : $this->lines($data['items'], $policy, $user, $order);
        [$priced, $outcome] = $this->promote($data, $lines, $customer, false, $order);
        $totals = $this->totals($priced, (int) ($data['discount'] ?? 0), $data['discount_bp'] ?? null, (int) ($data['shipping_fee'] ?? 0), $outcome->orderDiscount);
        $count = count($lines);

        return [
            'available' => $outcome->available,
            'applied' => $outcome->applied,
            'errors' => $outcome->errors,
            'lines' => array_map(fn (array $line): array => [
                'price' => $line['price'],
                'promotion_id' => $line['promotion_id'] ?? null,
                'promotion_discount' => $line['promotion_discount'] ?? 0,
            ], array_slice($priced, 0, $count)),
            'gifts' => array_map(fn (array $line): array => [
                'variant_id' => $line['variant_id'],
                'promotion_id' => $line['promotion_id'] ?? null,
                'name' => $line['name'],
                'sku' => $line['sku'],
                'unit' => $line['unit'],
                'quantity' => $line['quantity'],
            ], array_slice($priced, $count)),
            'promotion_discount' => $totals['promotion_discount'],
            'totals' => $totals,
        ];
    }

    /**
     * Run the promotions on priced lines: line discounts are added to the
     * typed ones, gift lines (price 0) are appended.
     *
     * @param  OrderInput  $data
     * @param  list<Line>  $lines
     * @return array{0: list<Line>, 1: PromotionOutcome}
     */
    protected function promote(array $data, array $lines, ?Customer $customer, bool $strict, ?Order $order = null): array
    {
        $kept = $order->promotions ?? [];
        $outcome = $this->promotions->apply(new PromotionContext(
            lines: array_map(fn (array $line): array => [
                'variant_id' => $line['variant_id'],
                'product_id' => $line['product_id'],
                'product_type' => $line['product_type'],
                'name' => $line['name'],
                'quantity' => $line['quantity'],
                'price' => $line['price'],
                'discount' => $line['discount'],
                'total' => $line['total'],
            ], $lines),
            customer: $customer,
            branchId: (int) $data['branch_id'],
            sourceId: $data['source_id'] ?? null,
            promotionIds: array_values(array_unique(array_map('intval', $data['promotion_ids'] ?? []))),
            couponCode: isset($data['coupon_code']) && trim($data['coupon_code']) !== '' ? trim($data['coupon_code']) : null,
            gifts: $data['gifts'] ?? [],
            keepIds: $kept['ids'] ?? [],
            keepCoupon: $kept['coupon'] ?? null,
        ), $strict);

        foreach ($lines as $index => $line) {
            $amount = min(max(0, $outcome->lineDiscounts[$index]['amount'] ?? 0), $line['total']);
            $lines[$index] = [
                ...$line,
                'discount' => $line['discount'] + $amount,
                'total' => $line['total'] - $amount,
                'promotion_id' => $amount > 0 ? $outcome->lineDiscounts[$index]['promotion_id'] : null,
                'promotion_discount' => $amount,
                'is_gift' => false,
            ];
        }

        $variants = ProductVariant::query()->with('product')->whereIn('id', array_column($outcome->gifts, 'variant_id'))->get()->keyBy('id');

        foreach ($outcome->gifts as $gift) {
            /** @var ProductVariant|null $variant */
            $variant = $variants->get($gift['variant_id']);

            if ($variant === null || $gift['quantity'] <= 0) {
                continue;
            }

            $lines[] = [
                'variant_id' => $variant->id,
                'product_id' => $variant->product_id,
                'product_type' => $variant->product->type,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'unit' => $variant->unit,
                'quantity' => $gift['quantity'],
                'price' => 0,
                'discount' => 0,
                'total' => 0,
                'note' => 'Quà tặng',
                'promotion_id' => $gift['promotion_id'],
                'promotion_discount' => 0,
                'is_gift' => true,
            ];
        }

        return [$lines, $outcome];
    }

    /**
     * What the order keeps of its promotions, to edit it later and show them.
     *
     * @param  OrderInput  $data
     * @return array<string, mixed>|null
     */
    protected function chosen(array $data, PromotionOutcome $outcome): ?array
    {
        if ($outcome->applied === []) {
            return null;
        }

        $coupon = null;

        foreach ($outcome->applied as $applied) {
            $coupon = $applied['coupon_id'] !== null ? $applied['code'] : $coupon;
        }

        return [
            'ids' => array_values(array_filter(array_column($outcome->applied, 'promotion_id'))),
            'coupon' => $coupon,
            'gifts' => $data['gifts'] ?? [],
            'applied' => $outcome->applied,
        ];
    }

    /**
     * Price each line on the server: the policy price, unless the user may
     * change prices when selling. Lines must be sellable products, or a
     * named service line without a product.
     *
     * @param  list<LineInput>  $items
     * @param  Policy  $policy
     * @return list<Line>
     */
    public function lines(array $items, array $policy, User $user, ?Order $order = null, bool $trustedPrices = false): array
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Thêm ít nhất một sản phẩm.']);
        }

        $ids = array_values(array_unique(array_filter(array_map(fn (array $item): ?int => $item['variant_id'] ?? null, $items))));
        $variants = ProductVariant::query()->with('product')->whereIn('id', $ids)->get()->keyBy('id');
        $prices = $this->prices->prices($variants->values(), $policy);
        // Lines already on the order keep their product even if it was stopped since.
        $kept = $order === null ? [] : $order->items()->pluck('variant_id')->filter()->all();
        $canEditPrice = $trustedPrices || $this->canEditPrice($user);
        $lines = [];

        foreach ($items as $index => $item) {
            $quantity = (int) $item['quantity'];

            if ($quantity <= 0) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => 'Số lượng phải lớn hơn 0.']);
            }

            $variantId = $item['variant_id'] ?? null;

            if ($variantId === null) {
                $name = trim((string) ($item['name'] ?? ''));

                if ($name === '') {
                    throw ValidationException::withMessages(["items.{$index}.name" => 'Nhập tên dịch vụ.']);
                }

                $price = max(0, (int) ($item['price'] ?? 0));
                $line = ['variant_id' => null, 'product_id' => null, 'product_type' => Product::TYPE_SERVICE, 'name' => mb_substr($name, 0, 255), 'sku' => null, 'unit' => null];
            } else {
                /** @var ProductVariant|null $variant */
                $variant = $variants->get($variantId);

                if ($variant === null || $variant->product->trashed()) {
                    throw ValidationException::withMessages(["items.{$index}.variant_id" => 'Có sản phẩm không tồn tại hoặc đã bị xóa.']);
                }

                if (! $variant->product->is_sellable && ! in_array($variant->id, $kept, true)) {
                    throw ValidationException::withMessages(["items.{$index}.variant_id" => "{$variant->name} đang ngừng giao dịch."]);
                }

                $price = $canEditPrice && isset($item['price']) ? max(0, (int) $item['price']) : $prices[$variant->id];
                $line = ['variant_id' => $variant->id, 'product_id' => $variant->product_id, 'product_type' => $variant->product->type, 'name' => $variant->name, 'sku' => $variant->sku, 'unit' => $variant->unit];
            }

            $gross = $quantity * $price;
            $discount = min(max(0, (int) ($item['discount'] ?? 0)), $gross);
            $lines[] = [...$line, 'quantity' => $quantity, 'price' => $price, 'discount' => $discount, 'total' => $gross - $discount, 'note' => $item['note'] ?? null];
        }

        return $lines;
    }

    /**
     * Other modules may lock an order's money, e.g. once an e-invoice is
     * issued for it (G7): Hook::addFilter('order.locked', fn (?string $reason, Order $order) => ...).
     */
    public function lockedReason(Order $order): ?string
    {
        $reason = Hook::filter('order.locked', null, $order);

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    protected function assertNotLocked(Order $order): void
    {
        $reason = $this->lockedReason($order);

        if ($reason !== null) {
            throw ValidationException::withMessages(['status' => $reason]);
        }
    }

    public function canEditPrice(User $user): bool
    {
        return $user->is_owner || (bool) settings('sales.allow_edit_price');
    }

    /**
     * Refuse when a branch lacks the goods ("Có thể bán" = stock - held),
     * counted in base units across lines, units and combos.
     *
     * @param  list<array{variant_id: int|null, quantity: int, name: string}>  $lines
     */
    public function checkAvailable(array $lines, int $branchId): void
    {
        $need = [];
        $names = [];

        foreach ($lines as $line) {
            if ($line['variant_id'] === null) {
                continue;
            }

            $variant = ProductVariant::query()->with('product')->find($line['variant_id']);

            if ($variant === null) {
                continue;
            }

            foreach ($this->inventory->components($variant, $line['quantity']) as [$base, $quantity]) {
                $base->loadMissing('product');

                if ($base->product->allow_negative_stock) {
                    continue;
                }

                $need[$base->id] = ($need[$base->id] ?? 0) + $quantity;
                $names[$base->id] = $base->name;
            }
        }

        if ($need === []) {
            return;
        }

        $stock = Inventory::query()->where('branch_id', $branchId)->whereIn('variant_id', array_keys($need))->get()->keyBy('variant_id');
        $short = [];

        foreach ($need as $variantId => $quantity) {
            $row = $stock->get($variantId);
            $available = $row === null ? 0 : $row->on_hand - $row->reserved;

            if ($quantity > $available) {
                $short[] = "{$names[$variantId]} (cần {$quantity}, có thể bán {$available})";
            }
        }

        if ($short !== []) {
            throw ValidationException::withMessages(['stock' => 'Không đủ hàng: '.implode('; ', $short).'.']);
        }
    }

    /**
     * Cost of one unit of a variant now: base cost × unit ratio, or the sum
     * of a combo's components.
     */
    public function unitCost(ProductVariant $variant): int
    {
        $cost = 0;

        foreach ($this->inventory->components($variant, 1) as [$base, $quantity]) {
            $cost += (int) ProductVariant::query()->withTrashed()->whereKey($base->id)->value('cost_price') * $quantity;
        }

        return $cost;
    }

    /**
     * @param  OrderInput  $data
     * @param  Policy  $policy
     * @return array<string, mixed>
     */
    protected function header(array $data, ?Customer $customer, array $policy, User $user, ?Order $order = null): array
    {
        $delivery = $data['delivery_method'] ?? 'later';
        $saleAt = isset($data['sale_at']) && settings('sales.allow_change_sale_time')
            ? CarbonImmutable::parse($data['sale_at'])
            : now();

        return [
            'branch_id' => (int) $data['branch_id'],
            'customer_id' => $customer?->id,
            'customer_name' => $customer?->name,
            'customer_phone' => $customer?->phone,
            'receiver_name' => $data['receiver_name'] ?? $customer?->name,
            'receiver_phone' => $data['receiver_phone'] ?? $customer?->phone,
            // Delivered orders default to the customer's saved address.
            'shipping_address' => $data['shipping_address'] ?? ($delivery === 'pickup' ? null : $customer?->addresses()->first()?->fullAddress()),
            'source_id' => $data['source_id'] ?? null,
            'price_column' => $policy['column'],
            'price_list_id' => $policy['price_list_id'],
            'price_label' => $policy['label'],
            'delivery_method' => array_key_exists($delivery, Order::DELIVERY_METHODS) ? $delivery : 'later',
            // A carrier shipment (G6) fills these in; editing the order keeps them.
            'carrier_name' => match ($delivery) {
                'external' => $data['carrier_name'] ?? null,
                'carrier' => $order?->carrier_name,
                default => null,
            },
            'tracking_code' => match ($delivery) {
                'external' => $data['tracking_code'] ?? null,
                'carrier' => $order?->tracking_code,
                default => null,
            },
            'note' => $data['note'] ?? null,
            'tags' => $data['tags'] ?? null,
            'reference' => $data['reference'] ?? null,
            'expected_delivery_at' => $data['expected_delivery_at'] ?? null,
            'sale_at' => $saleAt,
            'assigned_to' => $data['assigned_to'] ?? $order->assigned_to ?? $user->id,
        ];
    }

    /**
     * @param  OrderInput  $data
     * @return Policy
     */
    protected function policy(array $data, ?Customer $customer): array
    {
        return $this->prices->policyFromKey($data['price_policy'] ?? null) ?? $this->prices->policyFor($customer);
    }

    protected function customer(?int $id): ?Customer
    {
        if ($id === null) {
            return null;
        }

        $customer = Customer::query()->with('group')->find($id);

        if ($customer === null) {
            throw ValidationException::withMessages(['customer_id' => 'Khách hàng không tồn tại hoặc đã bị xóa.']);
        }

        return $customer;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    protected function saveLines(Order $order, array $lines): void
    {
        $order->items()->delete();

        foreach ($lines as $line) {
            $order->items()->create($line);
        }

        $order->load('items.variant.product');
    }

    /**
     * Lines of a saved order in the shape checkAvailable() takes.
     *
     * @return list<array{variant_id: int|null, quantity: int, name: string}>
     */
    protected function linesOf(Order $order): array
    {
        return array_values($order->items->map(fn (OrderItem $item): array => [
            'variant_id' => $item->variant_id,
            'quantity' => $item->quantity,
            'name' => $item->name,
        ])->all());
    }

    /**
     * Lines that move stock, in variant order so locks are always taken in
     * the same order.
     *
     * @return list<OrderItem>
     */
    protected function stockItems(Order $order): array
    {
        return array_values($order->items->filter(fn (OrderItem $item): bool => $item->tracksStock() && $item->variant !== null)->sortBy('variant_id')->all());
    }

    /**
     * Reserve (+1) or release (-1) the order's goods.
     */
    protected function changeReservation(Order $order, int $sign): void
    {
        foreach ($this->stockItems($order) as $item) {
            /** @var ProductVariant $variant */
            $variant = $item->variant;
            $sign > 0
                ? $this->inventory->reserve($order->branch_id, $variant, $item->quantity)
                : $this->inventory->release($order->branch_id, $variant, $item->quantity);
        }
    }

    /**
     * Put exported goods (not already returned) back into stock at the cost they left with.
     */
    protected function restock(Order $order, string $note): void
    {
        foreach ($this->stockItems($order) as $item) {
            $quantity = $item->quantity - $item->returned_quantity;

            if ($quantity <= 0) {
                continue;
            }

            /** @var ProductVariant $variant */
            $variant = $item->variant;
            $this->inventory->move($order->branch_id, $variant, $quantity, StockMovement::SALE_RETURN, [
                'unit_cost' => $item->cost,
                'update_cost' => $item->cost > 0,
                'reference' => $order,
                'reference_code' => $order->code,
                'note' => $note,
            ]);
        }
    }

    /**
     * Refund every payment of a cancelled order with the method it came in.
     */
    protected function refundAll(Order $order): void
    {
        $byMethod = OrderPayment::query()
            ->where('order_id', $order->id)
            ->selectRaw('payment_method_id, SUM(amount) as net')
            ->groupBy('payment_method_id')
            ->get();

        foreach ($byMethod as $row) {
            $net = (int) $row->getAttribute('net');
            $methodId = $row->getAttribute('payment_method_id');
            $method = $methodId === null ? null : PaymentMethod::query()->find((int) $methodId);

            if ($net > 0 && $method !== null) {
                $this->recordPayment($order, $method, -$net, OrderPayment::REFUND, 'Hoàn tiền khi hủy đơn');
            }
        }
    }

    protected function recordPayment(Order $order, PaymentMethod $method, int $amount, string $kind, ?string $note = null, ?Model $reference = null): OrderPayment
    {
        // E.g. paying with loyalty points takes the points first.
        $this->promotions->beforePayment($order, $method, $amount);

        /** @var OrderPayment $payment */
        $payment = OrderPayment::query()->create([
            'order_id' => $order->id,
            'branch_id' => $order->branch_id,
            'payment_method_id' => $method->id,
            'method_name' => $method->name,
            'method_type' => $method->type,
            'kind' => $kind,
            'amount' => $amount,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'pos_shift_id' => $order->pos_shift_id,
            'note' => $note,
            'paid_at' => now(),
            'created_by' => auth()->id(),
        ]);

        $order->forceFill(['paid' => $order->paid + $amount])->save();
        $this->debt($order, $amount > 0 ? CustomerDebt::PAYMENT : CustomerDebt::REFUND, -$amount, $note);

        DB::afterCommit(fn () => event(new OrderPaymentRecorded($payment)));

        return $payment;
    }

    protected function debt(Order $order, string $type, int $amount, ?string $note = null): void
    {
        if ($order->customer_id === null || $amount === 0) {
            return;
        }

        /** @var Customer|null $customer */
        $customer = Customer::query()->withTrashed()->find($order->customer_id);

        if ($customer !== null) {
            $this->ledger->record($customer, $type, $amount, ['reference' => $order, 'reference_code' => $order->code, 'note' => $note]);
        }
    }

    /**
     * Run one step on the locked order, write its history and tell listeners.
     *
     * @param  callable(Order): void  $change
     */
    protected function step(Order $order, string $action, callable $change, ?string $note = null): void
    {
        DB::transaction(function () use ($order, $action, $change, $note): void {
            $locked = $this->lock($order);
            $change($locked);
            $this->history($locked, $action, $note);

            // A step that finishes the order also logs "Hoàn thành đơn hàng".
            if ($locked->status === Order::COMPLETED && $locked->wasChanged('status')) {
                $this->history($locked, 'completed');
            }
            $this->refreshCustomer($locked->customer_id);
            $order->setRawAttributes($locked->getAttributes(), true);

            DB::afterCommit(fn () => event(new OrderStatusChanged($locked, $action)));
        });
    }
}
