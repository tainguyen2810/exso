<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\OrderReason;
use App\Models\User;
use App\Support\DataTable\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Order\Http\Concerns\HandlesOrderInput;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\OrderPayment;
use Modules\Order\Models\OrderReturn;
use Modules\Order\Models\OrderReturnItem;
use Modules\Order\Services\ReturnService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Khách trả hàng": returns of exported orders, with refund.
 */
class ReturnController extends Controller
{
    use HandlesOrderInput;

    public const REFUND_STATUSES = ['none' => 'Chưa hoàn tiền', 'partial' => 'Hoàn một phần', 'refunded' => 'Đã hoàn tiền'];

    public function __construct(protected ReturnService $returns) {}

    public function index(Request $request): Response|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = OrderReturn::query()
            ->with(['order:id,code', 'customer:id,name,code', 'branch:id,name', 'reason:id,name', 'creator:id,name'])
            ->whereIn('branch_id', $user->branchIds())
            ->whereHas('order', fn (Builder $query) => $query->visibleTo($user));

        $table = DataTable::for($query, $request, 'returns')
            ->search(['code', 'order.code', 'customer.name', 'customer.phone'])
            ->filterExact('branch_id')
            ->filterExact('reason_id')
            ->filterExact('created_by')
            ->filter('refund', fn (Builder $query, mixed $value) => match ($value) {
                'none' => $query->where('refunded', 0)->where('total', '>', 0),
                'partial' => $query->where('refunded', '>', 0)->whereColumn('refunded', '<', 'total'),
                'refunded' => $query->whereColumn('refunded', '>=', 'total'),
                default => null,
            })
            ->filterDateRange('created_at')
            ->sortable(['code', 'total', 'created_at'], '-id');

        if ($request->query('export') === 'xlsx') {
            abort_unless($user->can('returns.export'), 403);

            return $table->export('tra-hang-'.now()->format('Ymd-His'), [
                'Mã đơn trả hàng' => fn (OrderReturn $return) => $return->code,
                'Mã đơn hàng' => fn (OrderReturn $return) => $return->order->code,
                'Khách hàng' => fn (OrderReturn $return) => $return->customer->name ?? 'Khách lẻ',
                'Chi nhánh' => fn (OrderReturn $return) => $return->branch->name,
                'Số lượng' => fn (OrderReturn $return) => $return->quantity,
                'Tổng tiền' => fn (OrderReturn $return) => $return->total,
                'Đã hoàn' => fn (OrderReturn $return) => $return->refunded,
                'Lý do' => fn (OrderReturn $return) => $return->reason?->name,
                'Ngày nhận hàng' => fn (OrderReturn $return) => $return->received_at?->setTimezone((string) config('app.timezone')),
                'Nhân viên' => fn (OrderReturn $return) => $return->creator?->name,
            ]);
        }

        return Inertia::render('returns/index', [
            'returns' => $table->paginate(fn (OrderReturn $return): array => [
                'id' => $return->id,
                'code' => $return->code,
                'order' => $return->order->only('id', 'code'),
                'customer' => $return->customer?->only('id', 'name'),
                'branch' => $return->branch->name,
                'status' => $return->status,
                'refund_status' => $return->refundStatus(),
                'quantity' => $return->quantity,
                'total' => $return->total,
                'refunded' => $return->refunded,
                'reason' => $return->reason?->name,
                'creator' => $return->creator?->name,
                'received_at' => $return->received_at?->toIso8601String(),
                'created_at' => $return->created_at?->toIso8601String(),
            ]),
            'branches' => Branch::query()->whereIn('id', $user->branchIds())->orderBy('name')->get(['id', 'name']),
            'reasons' => OrderReason::query()->where('type', 'return')->orderBy('sort')->get(['id', 'name']),
            'staff' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Return form for an order (picked from the list or the order page).
     */
    public function create(Request $request): Response
    {
        $order = null;

        if ($request->filled('order') || $request->filled('code')) {
            /** @var Order|null $found */
            $found = Order::query()->with(['items.variant.product', 'customer'])
                ->when($request->filled('order'), fn (Builder $query) => $query->whereKey($request->integer('order')))
                ->when(! $request->filled('order'), fn (Builder $query) => $query->where('code', mb_substr($request->string('code')->trim()->toString(), 0, 50)))
                ->first();

            if ($found !== null) {
                $this->authorizeOrder($request, $found);
                $order = $found;
            }
        }

        return Inertia::render('returns/create', [
            'order' => $order === null ? null : [
                'id' => $order->id,
                'code' => $order->code,
                'status' => $order->status,
                'exported' => $order->isExported(),
                'customer' => $order->customer?->only('id', 'name', 'phone', 'code'),
                'subtotal' => $order->subtotal,
                'discount' => $order->discount,
                'total' => $order->total,
                'paid' => $order->paid,
                'returned_total' => $order->returned_total,
                'items' => $order->items->map(fn (OrderItem $item): array => [
                    'id' => $item->id,
                    'variant_id' => $item->variant_id,
                    'product_id' => $item->product_id,
                    'name' => $item->name,
                    'sku' => $item->sku ?? '',
                    'unit' => $item->unit,
                    'image_url' => $item->variant?->product->image_url,
                    'quantity' => $item->quantity,
                    'returned_quantity' => $item->returned_quantity,
                    'price' => $item->price,
                    'refund_price' => $this->returns->unitRefund($order, $item),
                ])->all(),
            ],
            'searched' => $request->string('code')->trim()->toString() ?: null,
            'reasons' => OrderReason::query()->where('type', 'return')->where('is_active', true)->orderBy('sort')->get(['id', 'name']),
            'requireReason' => (bool) settings('reasons.require_return'),
            'paymentMethods' => $this->paymentMethods(),
            'defaultMethod' => settings('payment.default_pos'),
            'canRefund' => (bool) $request->user()?->can('returns.pay'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'max:300'],
            'items.*' => ['integer', 'min:0', 'max:1000000'],
            'reason_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:2000'],
            'refund_method_id' => ['nullable', 'integer'],
            'refund_amount' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ]);

        /** @var Order $order */
        $order = Order::query()->findOrFail((int) $data['order_id']);
        $this->authorizeOrder($request, $order);

        $refund = null;

        if ((int) ($data['refund_amount'] ?? 0) > 0) {
            abort_unless($request->user()?->can('returns.pay'), 403, 'Bạn không có quyền hoàn tiền.');
            $refund = ['payment_method_id' => (int) ($data['refund_method_id'] ?? 0), 'amount' => (int) $data['refund_amount']];
        }

        $quantities = [];

        foreach ($data['items'] as $itemId => $quantity) {
            $quantities[(int) $itemId] = (int) $quantity;
        }

        $return = $this->returns->create($order, $quantities, isset($data['reason_id']) ? (int) $data['reason_id'] : null, $data['note'] ?? null, $refund);
        $this->toast("Đã tạo đơn trả hàng {$return->code}.");

        return to_route('returns.show', $return);
    }

    public function show(Request $request, OrderReturn $return): Response
    {
        $return->load(['order', 'customer:id,name,code,phone', 'branch:id,name', 'reason:id,name', 'creator:id,name', 'items.orderItem']);
        $this->authorizeOrder($request, $return->order);

        return Inertia::render('returns/show', [
            'orderReturn' => [
                'id' => $return->id,
                'code' => $return->code,
                'order' => $return->order->only('id', 'code'),
                'customer' => $return->customer?->only('id', 'name', 'code', 'phone'),
                'branch' => $return->branch->name,
                'status' => $return->status,
                'refund_status' => $return->refundStatus(),
                'reason' => $return->reason?->name,
                'note' => $return->note,
                'quantity' => $return->quantity,
                'total' => $return->total,
                'refunded' => $return->refunded,
                'can_refund' => min($return->total - $return->refunded, max(0, -$return->order->remaining())),
                'creator' => $return->creator?->name,
                'received_at' => $return->received_at?->toIso8601String(),
                'created_at' => $return->created_at?->toIso8601String(),
                'items' => $return->items->map(fn (OrderReturnItem $item): array => [
                    'id' => $item->id,
                    'variant_id' => $item->variant_id,
                    'product_id' => $item->orderItem->product_id,
                    'name' => $item->orderItem->name,
                    'sku' => $item->orderItem->sku ?? '',
                    'unit' => $item->orderItem->unit,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'total' => $item->total,
                ])->all(),
                'refunds' => OrderPayment::query()
                    ->where('reference_type', $return->getMorphClass())
                    ->where('reference_id', $return->id)
                    ->get()
                    ->map(fn (OrderPayment $payment): array => ['id' => $payment->id, 'method' => $payment->method_name, 'amount' => -$payment->amount, 'paid_at' => $payment->paid_at->toIso8601String()])
                    ->all(),
            ],
            'paymentMethods' => $this->paymentMethods(),
            'defaultMethod' => settings('payment.default_pos'),
        ]);
    }

    public function refund(Request $request, OrderReturn $return): RedirectResponse
    {
        $this->authorizeOrder($request, $return->order);
        $data = $request->validate([
            'payment_method_id' => ['required', 'integer'],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
        ], [], ['amount' => 'số tiền hoàn']);

        $this->returns->refund($return, (int) $data['payment_method_id'], (int) $data['amount']);
        $this->toast("Đã hoàn tiền cho đơn trả {$return->code}.");

        return back();
    }
}
