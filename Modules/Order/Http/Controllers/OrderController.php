<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\OrderReason;
use App\Models\PaymentMethod;
use App\Models\SalesSource;
use App\Models\User;
use App\Support\Branches\CurrentBranch;
use App\Support\DataTable\DataTable;
use App\Support\Hooks\Hook;
use App\Support\Settings\Banks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Customer\Http\Controllers\CustomerController;
use Modules\Customer\Models\Customer;
use Modules\Inventory\Services\InventoryService;
use Modules\Order\Contracts\OrderShipping;
use Modules\Order\Http\Concerns\HandlesOrderInput;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderHistory;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\OrderPayment;
use Modules\Order\Models\OrderReturn;
use Modules\Order\Services\OrderQueues;
use Modules\Order\Services\OrderService;
use Modules\Order\Services\PriceResolver;
use Modules\Order\Support\VietQr;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Đơn hàng": create and deliver, the list with its work queues, the
 * detail page with the next step of each block, payments and printing.
 * Every step is also checked against the order (branch, who may see it).
 */
class OrderController extends Controller
{
    use HandlesOrderInput;

    public function __construct(protected OrderService $orders) {}

    public function index(Request $request, OrderQueues $queues): Response|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $base = fn (): Builder => Order::query()->visibleTo($user);

        $table = DataTable::for($base()->with(['customer:id,name,code', 'branch:id,name', 'source:id,name', 'assignee:id,name', 'items:id,order_id,name,sku,quantity,price,discount,total,variant_id,product_id']), $request, 'orders')
            ->search(['code', 'customer_name', 'customer_phone', 'receiver_phone', 'tracking_code', 'reference'])
            ->filterExact('status')
            ->filterExact('branch_id')
            ->filterExact('source_id')
            ->filterExact('assigned_to')
            ->filterExact('channel')
            ->filterExact('delivery_method')
            ->filter('state', fn (Builder $query, mixed $value) => match ($value) {
                'active' => $query->whereIn('status', [Order::APPROVED, Order::PACKED, Order::SHIPPED]),
                default => null,
            })
            ->filter('queue', fn (Builder $query, mixed $value) => $queues->apply($query, (string) $value))
            ->filter('payment', fn (Builder $query, mixed $value) => match ($value) {
                'unpaid' => $query->where('status', '!=', Order::CANCELLED)->where('paid', '<=', 0),
                'partial' => $query->where('status', '!=', Order::CANCELLED)->where('paid', '>', 0)->whereRaw('paid < total - returned_total'),
                'paid' => $query->whereRaw('paid >= total - returned_total'),
                default => null,
            })
            ->filterDateRange('sale_at')
            ->sortable(['code', 'sale_at', 'total', 'created_at'], '-id');

        if ($request->query('export') === 'xlsx') {
            abort_unless($user->can('orders.export'), 403);

            return $table->export('don-hang-'.now()->format('Ymd-His'), [
                'Mã đơn hàng' => fn (Order $order) => $order->code,
                'Ngày bán' => fn (Order $order) => $order->sale_at?->setTimezone((string) config('app.timezone')),
                'Khách hàng' => fn (Order $order) => $order->customer_name ?? 'Khách lẻ',
                'Số điện thoại' => fn (Order $order) => $order->customer_phone,
                'Chi nhánh' => fn (Order $order) => $order->branch->name,
                'Nguồn' => fn (Order $order) => $order->source?->name,
                'Trạng thái' => fn (Order $order) => Order::STATUSES[$order->status],
                'Thanh toán' => fn (Order $order) => $order->status === Order::CANCELLED ? '' : Order::PAYMENT_STATUSES[$order->paymentStatus()],
                'Tổng tiền hàng' => fn (Order $order) => $order->subtotal,
                'Chiết khấu' => fn (Order $order) => $order->discount,
                'Phí giao hàng' => fn (Order $order) => $order->shipping_fee,
                'Khách phải trả' => fn (Order $order) => $order->total,
                'Đã thanh toán' => fn (Order $order) => $order->paid,
                'Trả hàng' => fn (Order $order) => $order->returned_total,
                'Nhân viên bán' => fn (Order $order) => $order->assignee?->name,
                'Sản phẩm' => fn (Order $order) => $order->items->map(fn (OrderItem $item): string => "{$item->name} x {$item->quantity}")->implode('; '),
            ]);
        }

        return Inertia::render('orders/index', [
            'orders' => $table->paginate(fn (Order $order): array => $this->row($order)),
            'queues' => $queues->strip($base()->where('created_at', '>=', now()->subDays(90))),
            'branches' => Branch::query()->whereIn('id', $user->branchIds())->orderBy('name')->get(['id', 'name']),
            'sources' => SalesSource::query()->orderBy('name')->get(['id', 'name']),
            'staff' => User::query()->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => $this->paymentMethods(),
            'cancelReasons' => OrderReason::query()->where('type', 'cancel')->where('is_active', true)->orderBy('sort')->get(['id', 'name']),
            'requireCancelReason' => (bool) settings('reasons.require_cancel'),
        ]);
    }

    public function create(Request $request): Response
    {
        $copy = null;

        if ($request->filled('copy')) {
            /** @var Order|null $source */
            $source = Order::query()->with(['items.variant.product', 'customer'])->find($request->integer('copy'));

            if ($source !== null && $source->isVisibleTo($request->user())) {
                $copy = $this->draft($source, false);
            }
        }

        // "Tạo đơn hàng" from a customer page starts with that customer.
        $preset = null;

        if ($copy === null && $request->filled('customer')) {
            /** @var Customer|null $customer */
            $customer = Customer::query()->find($request->integer('customer'));

            if ($customer !== null && $customer->isVisibleTo($request->user())) {
                $preset = CustomerController::option($customer);
            }
        }

        return Inertia::render('orders/form', [
            'order' => $copy,
            'presetCustomer' => $preset,
            ...$this->orderOptions($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $then = $request->input('next_step');
        $then = in_array($then, ['approve', 'export'], true) ? $then : null;
        $data = $this->orderInput($request);
        // "Đẩy qua hãng vận chuyển" / a partner of "Đẩy vận chuyển ngoài": the
        // order is approved, then handed to the partner (G6).
        $shipment = in_array($data['delivery_method'] ?? 'later', ['carrier', 'external'], true) && is_array($request->input('shipment')) && $request->filled('shipment.carrier_id')
            ? (array) $request->input('shipment')
            : null;

        if ($shipment !== null && $then === null) {
            $then = 'approve';
        }

        if ($then !== null) {
            Gate::authorize('orders.approve');
        }

        if ($then === 'export') {
            Gate::authorize('orders.ship');
        }

        if ($shipment !== null) {
            Gate::authorize('shipments.create');
        }

        $order = $this->orders->create($data, $user, $shipment !== null ? 'approve' : $then);
        $error = $shipment === null ? null : app(OrderShipping::class)->dispatch($order, $shipment, $user);

        if ($error !== null) {
            $this->toast("Đã tạo đơn hàng {$order->code} nhưng chưa tạo được phiếu giao hàng: {$error}", 'warning');
        } else {
            $this->toast("Đã tạo đơn hàng {$order->code}.");
        }

        return to_route('orders.show', $order);
    }

    public function show(Request $request, Order $order): Response
    {
        $this->authorizeOrder($request, $order);
        $order->load(['items.variant.product', 'customer.group', 'branch:id,name', 'source:id,name', 'assignee:id,name', 'creator:id,name', 'cancelReason:id,name', 'payments.creator:id,name', 'histories.creator:id,name', 'returns.items', 'returns.creator:id,name']);
        $inventory = app(InventoryService::class);

        return Inertia::render('orders/show', [
            'order' => [
                ...$this->row($order),
                'customer' => $order->customer === null ? null : CustomerController::option($order->customer),
                'receiver_name' => $order->receiver_name,
                'receiver_phone' => $order->receiver_phone,
                'shipping_address' => $order->shipping_address,
                'price_label' => $order->price_label,
                'delivery_method' => $order->delivery_method,
                'delivery_label' => Order::DELIVERY_METHODS[$order->delivery_method] ?? $order->delivery_method,
                'delivery_status' => $order->delivery_status,
                'carrier_name' => $order->carrier_name,
                'tracking_code' => $order->tracking_code,
                'subtotal' => $order->subtotal,
                'discount' => $order->discount,
                'discount_bp' => $order->discount_bp,
                'promotion_discount' => $order->promotion_discount,
                'promotions' => $order->promotions['applied'] ?? [],
                'shipping_fee' => $order->shipping_fee,
                'returned_total' => $order->returned_total,
                'remaining' => $order->remaining(),
                'note' => $order->note,
                'tags' => $order->tags,
                'reference' => $order->reference,
                'expected_delivery_at' => $order->expected_delivery_at?->format('d/m/Y'),
                'creator' => $order->creator?->name,
                'cancel_reason' => $order->cancelReason?->name,
                'cancel_note' => $order->cancel_note,
                'steps' => [
                    'pending' => $order->created_at?->toIso8601String(),
                    'approved' => $order->approved_at?->toIso8601String(),
                    'packed' => $order->packed_at?->toIso8601String(),
                    'shipped' => $order->shipped_at?->toIso8601String(),
                    'completed' => $order->completed_at?->toIso8601String(),
                    'cancelled' => $order->cancelled_at?->toIso8601String(),
                ],
                'items' => $order->items->map(fn (OrderItem $item): array => [
                    'id' => $item->id,
                    'variant_id' => $item->variant_id,
                    'product_id' => $item->product_id,
                    'name' => $item->name,
                    'sku' => $item->sku,
                    'unit' => $item->unit,
                    'image_url' => $item->variant?->product->image_url,
                    'product_type' => $item->product_type,
                    'quantity' => $item->quantity,
                    'returned_quantity' => $item->returned_quantity,
                    'price' => $item->price,
                    'discount' => $item->discount,
                    'promotion_discount' => $item->promotion_discount,
                    'is_gift' => $item->is_gift,
                    'total' => $item->total,
                    'note' => $item->note,
                    'available' => $item->tracksStock() && $item->variant !== null && $order->isEditable()
                        ? $inventory->stockOf($item->variant, [$order->branch_id])['available']
                        : null,
                ])->all(),
                'payments' => $order->payments->map(fn (OrderPayment $payment): array => [
                    'id' => $payment->id,
                    'method' => $payment->method_name,
                    'kind' => $payment->kind,
                    'amount' => $payment->amount,
                    'note' => $payment->note,
                    'creator' => $payment->creator?->name,
                    'paid_at' => $payment->paid_at->toIso8601String(),
                ])->all(),
                'histories' => $order->histories->map(fn (OrderHistory $history): array => [
                    'id' => $history->id,
                    'action' => OrderHistory::ACTIONS[$history->action] ?? $history->action,
                    'note' => $history->note,
                    'creator' => $history->creator?->name,
                    'created_at' => $history->created_at?->toIso8601String(),
                ])->all(),
                'returns' => $order->returns->map(fn (OrderReturn $return): array => [
                    'id' => $return->id,
                    'code' => $return->code,
                    'total' => $return->total,
                    'refunded' => $return->refunded,
                    'quantity' => $return->quantity,
                    'creator' => $return->creator?->name,
                    'created_at' => $return->created_at?->toIso8601String(),
                ])->all(),
                'qr' => $this->qr($order),
            ],
            'paymentMethods' => $this->paymentMethods(),
            'defaultMethod' => settings('payment.default_online'),
            'cancelReasons' => OrderReason::query()->where('type', 'cancel')->where('is_active', true)->orderBy('sort')->get(['id', 'name']),
            'requireCancelReason' => (bool) settings('reasons.require_cancel'),
            'allowCancelAfterExport' => (bool) settings('sales.allow_cancel_after_export'),
            // Đóng gói và giao hàng (G6): shipments, partners and the "Giao hàng" dialog.
            'shipping' => [
                ...app(OrderShipping::class)->options($request->user()),
                ...app(OrderShipping::class)->panel($order, $request->user()),
            ],
            // Cards of other modules (G7: e-invoice, marketplace order).
            'panels' => Hook::filter('order.panels', [], $order, $request->user()),
            'locked' => $this->orders->lockedReason($order),
        ]);
    }

    public function edit(Request $request, Order $order): Response|RedirectResponse
    {
        $this->authorizeOrder($request, $order);

        if (! $order->isEditable()) {
            $this->toast('Đơn đã xuất kho hoặc đã hủy, không sửa được. Hàng đã xuất muốn đổi thì tạo đơn trả hàng.', 'warning');

            return to_route('orders.show', $order);
        }

        if (($reason = $this->orders->lockedReason($order)) !== null) {
            $this->toast($reason, 'warning');

            return to_route('orders.show', $order);
        }

        $order->load(['items.variant.product', 'customer']);

        return Inertia::render('orders/form', [
            'order' => $this->draft($order, true),
            ...$this->orderOptions($request),
        ]);
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrder($request, $order);
        /** @var User $user */
        $user = $request->user();
        $data = $this->orderInput($request);
        unset($data['payments']);

        $this->orders->update($order, $data, $user);
        $this->toast("Đã lưu đơn hàng {$order->code}.");

        return to_route('orders.show', $order);
    }

    /**
     * One step of the order: approve, pack, export, deliver...
     */
    public function step(Request $request, Order $order, string $action): RedirectResponse
    {
        $this->authorizeOrder($request, $order);
        Gate::authorize(match ($action) {
            'approve' => 'orders.approve',
            'pack', 'export', 'deliver', 'delivery-failed', 'receive-back' => 'orders.ship',
            default => abort(404),
        });

        match ($action) {
            'approve' => $this->orders->approve($order),
            'pack' => $this->orders->pack($order),
            'export' => $this->orders->export($order),
            'deliver' => $this->orders->markDelivered($order),
            'delivery-failed' => $this->orders->markDeliveryFailed($order, $request->string('note')->limit(255)->toString() ?: null),
            'receive-back' => $this->orders->receiveBack($order),
        };

        $this->toast(match ($action) {
            'approve' => "Đã duyệt đơn {$order->code}.",
            'pack' => "Đã đóng gói đơn {$order->code}.",
            'export' => "Đã xuất kho đơn {$order->code}.",
            'deliver' => "Đơn {$order->code} đã giao thành công.",
            'delivery-failed' => "Đã ghi nhận giao hàng thất bại đơn {$order->code}.",
            default => "Đã nhận lại hàng đơn {$order->code}.",
        });

        return back();
    }

    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrder($request, $order);
        $data = $request->validate([
            'reason_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->orders->cancel($order, isset($data['reason_id']) ? (int) $data['reason_id'] : null, $data['note'] ?? null);
        $this->toast("Đã hủy đơn hàng {$order->code}.");

        return back();
    }

    public function pay(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrder($request, $order);
        $data = $request->validate([
            'payment_method_id' => ['required', 'integer'],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['amount' => 'số tiền', 'payment_method_id' => 'hình thức thanh toán']);

        $this->orders->pay($order, (int) $data['payment_method_id'], (int) $data['amount'], $data['note'] ?? null);
        $this->toast("Đã thanh toán đơn hàng {$order->code}.");

        return back();
    }

    /**
     * Bulk actions on the list: approve, pay the rest with one method, cancel.
     */
    public function bulk(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'pay', 'cancel'])],
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['integer'],
            'payment_method_id' => ['required_if:action,pay', 'nullable', 'integer'],
            'reason_id' => ['nullable', 'integer'],
        ]);

        Gate::authorize(match ($data['action']) {
            'approve' => 'orders.approve',
            'pay' => 'orders.pay',
            default => 'orders.cancel',
        });

        $done = 0;
        $failed = [];

        foreach (Order::query()->visibleTo($user)->whereKey($data['ids'])->orderBy('id')->get() as $order) {
            try {
                match ($data['action']) {
                    'approve' => $this->orders->approve($order),
                    'pay' => $order->remaining() > 0 && $order->status !== Order::CANCELLED
                        ? $this->orders->pay($order, (int) $data['payment_method_id'], $order->remaining())
                        : throw ValidationException::withMessages(['amount' => 'Đơn đã thanh toán đủ.']),
                    default => $this->orders->cancel($order, isset($data['reason_id']) ? (int) $data['reason_id'] : null),
                };
                $done++;
            } catch (ValidationException) {
                $failed[] = $order->code;
            }
        }

        $message = "Đã xử lý {$done} đơn hàng.";

        if ($failed !== []) {
            $message .= ' Không xử lý được: '.implode(', ', array_slice($failed, 0, 10)).(count($failed) > 10 ? '...' : '').'.';
        }

        $this->toast($message, $failed === [] ? 'success' : 'warning');

        return back();
    }

    /**
     * Printable invoice: A4/A5 or a thermal receipt (58/80 mm).
     */
    public function print(Request $request, Order $order): Response
    {
        $this->authorizeOrder($request, $order);
        $order->load(['items', 'branch', 'assignee:id,name', 'customer']);
        $this->orders->history($order, 'printed');

        return Inertia::render('orders/print', [
            'orders' => [$this->printable($order)],
            'size' => in_array($request->query('size'), ['a4', 'a5', '80', '58'], true) ? $request->query('size') : 'a4',
            'storeInfo' => $this->storeInfo(),
        ]);
    }

    /**
     * Products for the order form and POS: price by the order's policy and
     * "Có thể bán" in the branch. An exact barcode or SKU comes first, so a
     * scanner adds the right product with Enter.
     */
    public function searchVariants(Request $request, PriceResolver $prices): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canAny(['orders.create', 'orders.update', 'pos.create']), 403);

        $raw = mb_substr($request->string('q')->trim()->toString(), 0, 100);
        $term = addcslashes($raw, '%_\\');
        $branchId = $request->integer('branch_id');
        $branchId = in_array($branchId, $user->branchIds(), true) ? $branchId : null;
        $policy = $prices->policyFromKey($request->string('price_policy')->toString()) ?? $prices->policyFor(null);

        $query = ProductVariant::query()
            ->with('product:id,name,type,image_url,is_sellable')
            ->whereHas('product', fn (Builder $query) => $query->where('is_sellable', true));

        $exact = $raw === '' ? null : (clone $query)->where(fn (Builder $query) => $query->where('barcode', $raw)->orWhere('sku', $raw))->first();

        $variants = $query
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', 'like', "{$term}%")))
            ->when($exact !== null, fn (Builder $query) => $query->whereKeyNot($exact->id))
            // POS grid: best sellers of the last 30 days first.
            ->when($term === '' && $request->boolean('top'), fn (Builder $query) => $query->orderByDesc(
                OrderItem::query()
                    ->selectRaw('COALESCE(SUM(order_items.quantity), 0)')
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->whereColumn('order_items.variant_id', 'product_variants.id')
                    ->where('orders.status', '!=', Order::CANCELLED)
                    ->where('orders.created_at', '>=', now()->subDays(30))
                    ->when($branchId !== null, fn (Builder $query) => $query->where('orders.branch_id', $branchId)),
            ))
            ->orderBy('name')
            ->limit($request->integer('limit', 20) > 0 ? min(48, $request->integer('limit', 20)) : 20)
            ->get();

        if ($exact !== null) {
            $variants->prepend($exact);
        }

        $priceOf = $prices->prices($variants, $policy);
        $inventory = app(InventoryService::class);

        return response()->json($variants->map(fn (ProductVariant $variant): array => [
            'id' => $variant->id,
            'product_id' => $variant->product_id,
            'name' => $variant->name,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'unit' => $variant->unit,
            'image_url' => $variant->product->image_url,
            'type' => $variant->product->type,
            'price' => $priceOf[$variant->id] ?? $variant->retail_price,
            'retail_price' => $variant->retail_price,
            'exact' => $exact !== null && $variant->id === $exact->id,
            ...($branchId === null || $variant->product->type === Product::TYPE_SERVICE
                ? ['on_hand' => null, 'available' => null]
                : $inventory->stockOf($variant, [$branchId])),
        ])->values());
    }

    /**
     * Programs the order qualifies for and the order priced with the chosen
     * ones and the coupon. The order form and POS both call this, and
     * saving recomputes the same way.
     */
    public function promotions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canAny(['orders.create', 'orders.update', 'pos.create']), 403);

        $order = null;

        if ($request->filled('order_id')) {
            /** @var Order|null $order */
            $order = Order::query()->find($request->integer('order_id'));
            abort_if($order === null || ! $order->isVisibleTo($user), 404);
        }

        if (! is_array($request->input('items')) || $request->input('items') === []) {
            return response()->json(['available' => [], 'applied' => [], 'errors' => [], 'lines' => [], 'gifts' => [], 'promotion_discount' => 0, 'totals' => null]);
        }

        $pos = $request->boolean('pos');
        $data = $this->orderInput($request, $pos);

        if ($pos && $data['branch_id'] === 0) {
            $current = app(CurrentBranch::class)->id($user);
            $data['branch_id'] = $current !== null && in_array($current, $user->branchIds(), true) ? $current : ($user->branchIds()[0] ?? 0);
            $data['shipping_fee'] = 0;
        }

        return response()->json($this->orders->preview($data, $user, $order));
    }

    /**
     * Prices of given variants under another policy (when the customer or
     * the price policy of the form changes).
     */
    public function reprice(Request $request, PriceResolver $prices): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canAny(['orders.create', 'orders.update', 'pos.create']), 403);
        $ids = array_slice(array_map('intval', (array) $request->query('ids', [])), 0, 300);
        $policy = $prices->policyFromKey($request->string('price_policy')->toString()) ?? $prices->policyFor(null);

        return response()->json($prices->prices(ProductVariant::query()->whereKey($ids)->get(), $policy));
    }

    /**
     * Purchase history tab of a customer (filter customer.orders).
     *
     * @param  list<array<string, mixed>>  $value
     * @return list<array<string, mixed>>
     */
    public static function customerOrders(array $value, Customer $customer, ?User $user): array
    {
        if ($user === null || ! $user->can('orders.view')) {
            return $value;
        }

        return array_values(Order::query()->visibleTo($user)->with(['branch:id,name', 'source:id,name', 'assignee:id,name'])
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'code' => $order->code,
                'status' => $order->status,
                'status_label' => Order::STATUSES[$order->status],
                'payment_status' => $order->status === Order::CANCELLED ? null : $order->paymentStatus(),
                'exported' => $order->isExported(),
                'total' => $order->total,
                'branch' => $order->branch->name,
                'source' => $order->source?->name,
                'assignee' => $order->assignee?->name,
                'sale_at' => $order->sale_at?->toIso8601String(),
            ])->all());
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Order $order): array
    {
        return [
            'id' => $order->id,
            'code' => $order->code,
            'channel' => $order->channel,
            'status' => $order->status,
            'status_label' => Order::STATUSES[$order->status],
            'payment_status' => $order->status === Order::CANCELLED ? null : $order->paymentStatus(),
            'delivery_status' => $order->delivery_status,
            'customer_id' => $order->customer_id,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'branch' => $order->branch->name,
            'source' => $order->source?->name,
            'assignee' => $order->assignee?->name,
            'total' => $order->total,
            'paid' => $order->paid,
            'sale_at' => $order->sale_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
            'note' => $order->note,
            'tags' => $order->tags,
            'lines' => $order->relationLoaded('items') ? $order->items->map(fn (OrderItem $item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'discount' => $item->discount,
                'total' => $item->total,
            ])->all() : [],
        ];
    }

    /**
     * The order in the shape of the form (edit, or a copy for a new order).
     *
     * @return array<string, mixed>
     */
    protected function draft(Order $order, bool $editing): array
    {
        $inventory = app(InventoryService::class);

        return [
            'id' => $editing ? $order->id : null,
            'code' => $editing ? $order->code : null,
            'status' => $editing ? $order->status : null,
            'paid' => $editing ? $order->paid : 0,
            'branch_id' => $order->branch_id,
            'customer' => $order->customer === null || $order->customer->trashed() ? null : CustomerController::option($order->customer),
            'source_id' => $order->source_id,
            'assigned_to' => $order->assigned_to,
            'price_policy' => $order->price_list_id !== null ? 'list:'.$order->price_list_id : $order->price_column,
            'delivery_method' => $order->delivery_method,
            'carrier_name' => $order->carrier_name,
            'tracking_code' => $order->tracking_code,
            'receiver_name' => $order->receiver_name,
            'receiver_phone' => $order->receiver_phone,
            'shipping_address' => $order->shipping_address,
            'shipping_fee' => $order->shipping_fee,
            // The typed part: promotions are applied again by the form.
            'discount' => $order->discount - $order->promotion_discount,
            'discount_bp' => $order->discount_bp,
            'promotion_ids' => $order->promotions['ids'] ?? [],
            'coupon_code' => $editing ? ($order->promotions['coupon'] ?? null) : null,
            'gifts' => (object) ($order->promotions['gifts'] ?? []),
            'note' => $order->note,
            'tags' => $order->tags,
            'reference' => $editing ? $order->reference : null,
            'expected_delivery_at' => $order->expected_delivery_at?->format('d/m/Y'),
            'items' => $order->items->reject(fn (OrderItem $item): bool => $item->is_gift)->values()->map(fn (OrderItem $item): array => [
                'variant_id' => $item->variant_id,
                'product_id' => $item->product_id,
                'name' => $item->name,
                'sku' => $item->sku ?? '',
                'unit' => $item->unit,
                'image_url' => $item->variant?->product->image_url,
                'type' => $item->product_type,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'discount' => $item->discount - $item->promotion_discount,
                'note' => $item->note,
                'available' => $item->tracksStock() && $item->variant !== null ? $inventory->stockOf($item->variant, [$order->branch_id])['available'] : null,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function printable(Order $order): array
    {
        return [
            'code' => $order->code,
            'sale_at' => $order->sale_at?->toIso8601String(),
            'branch' => $order->branch->only('name', 'address', 'phone'),
            'assignee' => $order->assignee?->name,
            'customer_name' => $order->customer_name ?? 'Khách lẻ',
            'customer_phone' => $order->customer_phone,
            'receiver_name' => $order->receiver_name,
            'receiver_phone' => $order->receiver_phone,
            'shipping_address' => $order->shipping_address,
            'note' => $order->note,
            'items' => $order->items->map(fn (OrderItem $item): array => $item->only('name', 'sku', 'unit', 'quantity', 'price', 'discount', 'total'))->all(),
            'subtotal' => $order->subtotal,
            'discount' => $order->discount,
            'shipping_fee' => $order->shipping_fee,
            'total' => $order->total,
            'paid' => $order->paid,
            'returned_total' => $order->returned_total,
            'remaining' => $order->remaining(),
            'debt' => $order->customer?->debt,
            'qr' => $this->qr($order),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function storeInfo(): array
    {
        return [
            'name' => (string) settings('store.name'),
            'phone' => settings('store.phone'),
            'address' => implode(', ', array_filter([settings('store.address'), settings('store.ward'), settings('store.district'), settings('store.province')])),
            'print_copies' => (int) settings('sales.print_copies', 1),
        ];
    }

    /**
     * VietQR for what is left to pay, with the order code as transfer note,
     * using the store's default bank method.
     *
     * @return array{svg: string, bank: ?string, account_number: string, account_name: ?string, amount: int}|null
     */
    protected function qr(Order $order): ?array
    {
        $amount = $order->remaining();

        if ($order->status === Order::CANCELLED || $amount <= 0) {
            return null;
        }

        $default = settings('payment.default_online');
        $method = PaymentMethod::query()->where('type', 'bank')->where('is_active', true)->whereNotNull('bank_bin')->whereNotNull('account_number')
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $default])
            ->orderBy('sort')
            ->first();

        if ($method === null || $method->bank_bin === null || $method->account_number === null) {
            return null;
        }

        return [
            'svg' => VietQr::svg(VietQr::payload($method->bank_bin, $method->account_number, $amount, $order->code)),
            'bank' => Banks::LIST[$method->bank_bin] ?? null,
            'account_number' => $method->account_number,
            'account_name' => $method->account_name,
            'amount' => $amount,
        ];
    }
}
