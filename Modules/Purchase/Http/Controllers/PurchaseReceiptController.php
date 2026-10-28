<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Support\DataTable\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Purchase\Http\Concerns\HandlesPurchaseDocuments;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseReceipt;
use Modules\Purchase\Models\PurchaseReceiptItem;
use Modules\Purchase\Models\PurchaseReturn;
use Modules\Purchase\Models\Supplier;
use Modules\Purchase\Models\SupplierDebt;
use Modules\Purchase\Services\PurchaseService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Nhập hàng": receipts from suppliers, receiving into stock, payments and returns.
 */
class PurchaseReceiptController extends Controller
{
    use HandlesPurchaseDocuments;

    public const PAYMENT_STATUSES = ['unpaid' => 'Chưa thanh toán', 'partial' => 'Thanh toán một phần', 'paid' => 'Đã thanh toán'];

    public function __construct(protected PurchaseService $purchases) {}

    public function index(Request $request): Response|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $branchIds = $user->branchIds();

        $table = DataTable::for(
            PurchaseReceipt::query()->with(['supplier:id,name,code', 'branch:id,name', 'creator:id,name'])->whereIn('branch_id', $branchIds),
            $request,
            'purchase-receipts',
        )
            ->search(['code', 'reference'])
            ->filterExact('status')
            ->filterExact('branch_id')
            ->filterExact('supplier_id')
            ->filter('payment', fn (Builder $query, mixed $value) => match ($value) {
                'unpaid' => $query->where('status', '!=', PurchaseReceipt::CANCELLED)->where('paid', 0),
                'partial' => $query->where('status', '!=', PurchaseReceipt::CANCELLED)->where('paid', '>', 0)->whereRaw('paid < total - returned_total'),
                'paid' => $query->where('status', '!=', PurchaseReceipt::CANCELLED)->whereRaw('paid >= total - returned_total'),
                default => null,
            })
            ->filterDateRange('created_at')
            ->sortable(['code', 'total', 'created_at'], '-id');

        if ($request->query('export') === 'xlsx') {
            abort_unless($user->can('purchases.export'), 403);

            return $table->export('nhap-hang-'.now()->format('Ymd-His'), [
                'Mã phiếu' => fn (PurchaseReceipt $receipt) => $receipt->code,
                'Ngày tạo' => fn (PurchaseReceipt $receipt) => $receipt->created_at?->setTimezone((string) config('app.timezone')),
                'Nhà cung cấp' => fn (PurchaseReceipt $receipt) => $receipt->supplier->name,
                'Chi nhánh' => fn (PurchaseReceipt $receipt) => $receipt->branch->name,
                'Trạng thái' => fn (PurchaseReceipt $receipt) => PurchaseReceipt::STATUSES[$receipt->status] ?? $receipt->status,
                'Thanh toán' => fn (PurchaseReceipt $receipt) => self::PAYMENT_STATUSES[$receipt->paymentStatus()],
                'Tổng tiền' => fn (PurchaseReceipt $receipt) => $receipt->total,
                'Đã trả' => fn (PurchaseReceipt $receipt) => $receipt->paid,
                'Người tạo' => fn (PurchaseReceipt $receipt) => $receipt->creator?->name,
            ]);
        }

        return Inertia::render('purchase-receipts/index', [
            'receipts' => $table->paginate(fn (PurchaseReceipt $receipt): array => [
                'id' => $receipt->id,
                'code' => $receipt->code,
                'supplier' => $receipt->supplier->name,
                'branch' => $receipt->branch->name,
                'status' => $receipt->status,
                'payment_status' => $receipt->status === PurchaseReceipt::CANCELLED ? null : $receipt->paymentStatus(),
                'total' => $receipt->total,
                'creator' => $receipt->creator?->name,
                'created_at' => $receipt->created_at?->toIso8601String(),
            ]),
            'branches' => Branch::query()->whereIn('id', $branchIds)->orderBy('name')->get(['id', 'name']),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        $order = $request->filled('order')
            ? PurchaseOrder::query()->with(['items.variant.product', 'supplier'])->whereKey($request->integer('order'))->first()
            : null;

        if ($order !== null) {
            $this->authorizeBranch($request, $order->branch_id);
        }

        $draft = $order === null ? null : [
            'supplier' => $this->supplierOption($order->supplier),
            'branch_id' => $order->branch_id,
            'purchase_order_id' => $order->id,
            'purchase_order_code' => $order->code,
            'discount' => 0,
            'costs' => [],
            'note' => null,
            'reference' => null,
            'items' => $order->items
                ->filter(fn ($item) => $item->quantity > $item->received_quantity)
                ->map(fn ($item): array => [
                    ...$this->lineRow($item->variant, $order->branch_id),
                    'quantity' => $item->quantity - $item->received_quantity,
                    'price' => $item->price,
                    'discount' => $item->quantity > 0 ? intdiv($item->discount * ($item->quantity - $item->received_quantity), $item->quantity) : 0,
                ])->values()->all(),
        ];

        return Inertia::render('purchase-receipts/form', [
            'receipt' => $draft,
            ...$this->formOptions($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->documentInput($request, true);
        $data['purchase_order_id'] = $request->filled('purchase_order_id') ? $request->integer('purchase_order_id') : null;
        $receive = $request->boolean('receive');

        abort_if($receive && ! $request->user()?->can('purchases.approve'), 403);

        $receipt = $this->purchases->saveReceipt(null, $data, $receive);

        $this->toast($receive ? "Đã tạo và nhập kho phiếu {$receipt->code}." : "Đã tạo phiếu nhập {$receipt->code}.");

        return to_route('purchase-receipts.show', $receipt);
    }

    public function show(Request $request, PurchaseReceipt $receipt): Response
    {
        $this->authorizeBranch($request, $receipt->branch_id);
        $receipt->load(['items.variant.product', 'supplier', 'branch:id,name', 'purchaseOrder:id,code', 'creator:id,name', 'receiver:id,name', 'returns.items.variant', 'returns.creator:id,name']);
        $showCost = (bool) $request->user()?->can('products.view_cost');

        return Inertia::render('purchase-receipts/show', [
            'receipt' => [
                'id' => $receipt->id,
                'code' => $receipt->code,
                'status' => $receipt->status,
                'payment_status' => $receipt->status === PurchaseReceipt::CANCELLED ? null : $receipt->paymentStatus(),
                'supplier' => $this->supplierOption($receipt->supplier),
                'branch' => $receipt->branch->name,
                'purchase_order' => $receipt->purchaseOrder?->only('id', 'code'),
                'reference' => $receipt->reference,
                'note' => $receipt->note,
                'subtotal' => $receipt->subtotal,
                'discount' => $receipt->discount,
                'costs' => $receipt->costs ?? [],
                'cost_total' => $receipt->cost_total,
                'total' => $receipt->total,
                'paid' => $receipt->paid,
                'returned_total' => $receipt->returned_total,
                'remaining' => $receipt->remaining(),
                'creator' => $receipt->creator?->name,
                'receiver' => $receipt->receiver?->name,
                'created_at' => $receipt->created_at?->toIso8601String(),
                'received_at' => $receipt->received_at?->toIso8601String(),
                'items' => $receipt->items->map(fn (PurchaseReceiptItem $item): array => [
                    'id' => $item->id,
                    'variant_id' => $item->variant_id,
                    'product_id' => $item->variant->product_id,
                    'name' => $item->variant->name,
                    'sku' => $item->variant->sku,
                    'unit' => $item->variant->unit,
                    'image_url' => $item->variant->product->image_url,
                    'quantity' => $item->quantity,
                    'returned_quantity' => $item->returned_quantity,
                    'price' => $item->price,
                    'discount' => $item->discount,
                    'total' => $item->total,
                    'unit_cost' => $showCost ? $item->unit_cost : null,
                    'return_price' => $this->purchases->returnPrice($receipt, $item),
                ])->all(),
                'payments' => SupplierDebt::query()
                    ->with('creator:id,name')
                    ->where('reference_type', $receipt->getMorphClass())
                    ->where('reference_id', $receipt->id)
                    ->where('type', SupplierDebt::PAYMENT)
                    ->latest('id')
                    ->get()
                    ->map(fn (SupplierDebt $line): array => [
                        'id' => $line->id,
                        'amount' => -$line->amount,
                        'method' => SupplierDebt::PAYMENT_METHODS[$line->payment_method ?? ''] ?? $line->payment_method,
                        'note' => $line->note,
                        'creator' => $line->creator?->name,
                        'created_at' => $line->created_at?->toIso8601String(),
                    ]),
                'returns' => $receipt->returns->map(fn (PurchaseReturn $return): array => [
                    'id' => $return->id,
                    'code' => $return->code,
                    'total' => $return->total,
                    'note' => $return->note,
                    'creator' => $return->creator?->name,
                    'created_at' => $return->created_at?->toIso8601String(),
                    'items' => $return->items->map(fn ($item): string => "{$item->variant->name} × {$item->quantity}")->all(),
                ]),
            ],
            'paymentMethods' => $this->formOptions($request)['paymentMethods'],
        ]);
    }

    public function edit(Request $request, PurchaseReceipt $receipt): Response|RedirectResponse
    {
        $this->authorizeBranch($request, $receipt->branch_id);

        if ($receipt->status !== PurchaseReceipt::DRAFT) {
            return to_route('purchase-receipts.show', $receipt);
        }

        $receipt->load(['items.variant.product', 'supplier', 'purchaseOrder:id,code']);

        return Inertia::render('purchase-receipts/form', [
            'receipt' => [
                'id' => $receipt->id,
                'code' => $receipt->code,
                'supplier' => $this->supplierOption($receipt->supplier),
                'branch_id' => $receipt->branch_id,
                'purchase_order_id' => $receipt->purchase_order_id,
                'purchase_order_code' => $receipt->purchaseOrder?->code,
                'discount' => $receipt->discount,
                'costs' => $receipt->costs ?? [],
                'note' => $receipt->note,
                'reference' => $receipt->reference,
                'items' => $receipt->items->map(fn (PurchaseReceiptItem $item): array => [
                    ...$this->lineRow($item->variant, $receipt->branch_id),
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'discount' => $item->discount,
                ])->all(),
            ],
            ...$this->formOptions($request),
        ]);
    }

    public function update(Request $request, PurchaseReceipt $receipt): RedirectResponse
    {
        $this->authorizeBranch($request, $receipt->branch_id);
        $data = $this->documentInput($request, true);
        $data['purchase_order_id'] = $receipt->purchase_order_id;
        $receive = $request->boolean('receive');

        abort_if($receive && ! $request->user()?->can('purchases.approve'), 403);

        $this->purchases->saveReceipt($receipt, $data, $receive);
        $this->toast($receive ? "Đã nhập kho phiếu {$receipt->code}." : "Đã lưu phiếu nhập {$receipt->code}.");

        return to_route('purchase-receipts.show', $receipt);
    }

    public function receive(Request $request, PurchaseReceipt $receipt): RedirectResponse
    {
        $this->authorizeBranch($request, $receipt->branch_id);
        $this->purchases->receive($receipt);
        $this->toast("Đã nhập kho phiếu {$receipt->code}.");

        return back();
    }

    public function pay(Request $request, PurchaseReceipt $receipt): RedirectResponse
    {
        $this->authorizeBranch($request, $receipt->branch_id);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'method' => ['required', Rule::in(array_keys(SupplierDebt::PAYMENT_METHODS))],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['amount' => 'số tiền', 'method' => 'hình thức thanh toán']);

        $this->purchases->pay($receipt, (int) $data['amount'], $data['method'], $data['note'] ?? null);
        $this->toast("Đã ghi nhận thanh toán cho phiếu {$receipt->code}.");

        return back();
    }

    public function cancel(Request $request, PurchaseReceipt $receipt): RedirectResponse
    {
        $this->authorizeBranch($request, $receipt->branch_id);
        $this->purchases->cancel($receipt);
        $this->toast("Đã hủy phiếu nhập {$receipt->code}.");

        return back();
    }

    public function returnGoods(Request $request, PurchaseReceipt $receipt): RedirectResponse
    {
        $this->authorizeBranch($request, $receipt->branch_id);

        $data = $request->validate([
            'items' => ['required', 'array', 'max:500'],
            'items.*' => ['integer', 'min:0', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $quantities = [];

        foreach ($data['items'] as $itemId => $quantity) {
            $quantities[(int) $itemId] = (int) $quantity;
        }

        $return = $this->purchases->returnGoods($receipt, $quantities, $data['note'] ?? null);
        $this->toast("Đã tạo phiếu trả hàng {$return->code}.");

        return back();
    }
}
