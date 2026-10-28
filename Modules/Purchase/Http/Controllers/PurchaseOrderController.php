<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Support\DataTable\DataTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Purchase\Http\Concerns\HandlesPurchaseDocuments;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\PurchaseReceipt;
use Modules\Purchase\Models\Supplier;
use Modules\Purchase\Services\PurchaseService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Đặt hàng nhập": orders to suppliers, received through purchase receipts.
 */
class PurchaseOrderController extends Controller
{
    use HandlesPurchaseDocuments;

    public const RECEIVE_STATUSES = ['none' => 'Chưa nhập', 'partial' => 'Nhập một phần', 'full' => 'Đã nhập'];

    public function __construct(protected PurchaseService $purchases) {}

    public function index(Request $request): Response|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $branchIds = $user->branchIds();

        $table = DataTable::for(
            PurchaseOrder::query()->with(['supplier:id,name', 'branch:id,name', 'creator:id,name', 'items'])->whereIn('branch_id', $branchIds),
            $request,
            'purchase-orders',
        )
            ->search(['code'])
            ->filterExact('status')
            ->filterExact('branch_id')
            ->filterExact('supplier_id')
            ->filterDateRange('created_at')
            ->filterDateRange('expected_at')
            ->sortable(['code', 'total', 'expected_at', 'created_at'], '-id');

        if ($request->query('export') === 'xlsx') {
            abort_unless($user->can('purchases.export'), 403);

            return $table->export('dat-hang-nhap-'.now()->format('Ymd-His'), [
                'Mã đơn' => fn (PurchaseOrder $order) => $order->code,
                'Ngày tạo' => fn (PurchaseOrder $order) => $order->created_at?->setTimezone((string) config('app.timezone')),
                'Nhà cung cấp' => fn (PurchaseOrder $order) => $order->supplier->name,
                'Chi nhánh' => fn (PurchaseOrder $order) => $order->branch->name,
                'Trạng thái' => fn (PurchaseOrder $order) => PurchaseOrder::STATUSES[$order->status] ?? $order->status,
                'Nhập hàng' => fn (PurchaseOrder $order) => self::RECEIVE_STATUSES[$order->receiveStatus()],
                'Ngày nhận dự kiến' => fn (PurchaseOrder $order) => $order->expected_at?->format('d/m/Y'),
                'Tổng tiền' => fn (PurchaseOrder $order) => $order->total,
            ]);
        }

        return Inertia::render('purchase-orders/index', [
            'orders' => $table->paginate(fn (PurchaseOrder $order): array => [
                'id' => $order->id,
                'code' => $order->code,
                'supplier' => $order->supplier->name,
                'branch' => $order->branch->name,
                'status' => $order->status,
                'receive_status' => $order->receiveStatus(),
                'expected_at' => $order->expected_at?->toDateString(),
                'total' => $order->total,
                'creator' => $order->creator?->name,
                'created_at' => $order->created_at?->toIso8601String(),
            ]),
            'branches' => Branch::query()->whereIn('id', $branchIds)->orderBy('name')->get(['id', 'name']),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('purchase-orders/form', ['order' => null, ...$this->formOptions($request)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $order = $this->purchases->saveOrder(null, $this->documentInput($request, false));
        $this->toast("Đã tạo đơn đặt hàng nhập {$order->code}.");

        return to_route('purchase-orders.show', $order);
    }

    public function show(Request $request, PurchaseOrder $order): Response
    {
        $this->authorizeBranch($request, $order->branch_id);
        $order->load(['items.variant.product', 'supplier', 'branch:id,name', 'creator:id,name', 'receipts']);

        return Inertia::render('purchase-orders/show', [
            'order' => [
                'id' => $order->id,
                'code' => $order->code,
                'status' => $order->status,
                'receive_status' => $order->receiveStatus(),
                'supplier' => $this->supplierOption($order->supplier),
                'branch' => $order->branch->name,
                'expected_at' => $order->expected_at?->toDateString(),
                'note' => $order->note,
                'subtotal' => $order->subtotal,
                'discount' => $order->discount,
                'total' => $order->total,
                'creator' => $order->creator?->name,
                'created_at' => $order->created_at?->toIso8601String(),
                'items' => $order->items->map(fn (PurchaseOrderItem $item): array => [
                    'id' => $item->id,
                    'variant_id' => $item->variant_id,
                    'product_id' => $item->variant->product_id,
                    'name' => $item->variant->name,
                    'sku' => $item->variant->sku,
                    'unit' => $item->variant->unit,
                    'image_url' => $item->variant->product->image_url,
                    'quantity' => $item->quantity,
                    'received_quantity' => $item->received_quantity,
                    'price' => $item->price,
                    'discount' => $item->discount,
                    'total' => $item->total,
                ])->all(),
                'receipts' => $order->receipts->map(fn (PurchaseReceipt $receipt): array => [
                    'id' => $receipt->id,
                    'code' => $receipt->code,
                    'status' => $receipt->status,
                    'total' => $receipt->total,
                    'created_at' => $receipt->created_at?->toIso8601String(),
                ])->all(),
            ],
        ]);
    }

    public function edit(Request $request, PurchaseOrder $order): Response|RedirectResponse
    {
        $this->authorizeBranch($request, $order->branch_id);

        if ($order->status !== PurchaseOrder::PENDING || $order->items()->where('received_quantity', '>', 0)->exists()) {
            return to_route('purchase-orders.show', $order);
        }

        $order->load(['items.variant.product', 'supplier']);

        return Inertia::render('purchase-orders/form', [
            'order' => [
                'id' => $order->id,
                'code' => $order->code,
                'supplier' => $this->supplierOption($order->supplier),
                'branch_id' => $order->branch_id,
                'expected_at' => $order->expected_at?->toDateString(),
                'discount' => $order->discount,
                'note' => $order->note,
                'items' => $order->items->map(fn (PurchaseOrderItem $item): array => [
                    ...$this->lineRow($item->variant, $order->branch_id),
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'discount' => $item->discount,
                ])->all(),
            ],
            ...$this->formOptions($request),
        ]);
    }

    public function update(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $this->authorizeBranch($request, $order->branch_id);
        $this->purchases->saveOrder($order, $this->documentInput($request, false));
        $this->toast("Đã lưu đơn đặt hàng nhập {$order->code}.");

        return to_route('purchase-orders.show', $order);
    }

    public function close(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $this->authorizeBranch($request, $order->branch_id);
        $this->purchases->closeOrder($order);
        $order->refresh();
        $this->toast($order->status === PurchaseOrder::CANCELLED ? "Đã hủy đơn {$order->code}." : "Đã kết thúc đơn {$order->code}.");

        return back();
    }
}
