<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Support\Branches\CurrentBranch;
use App\Support\DataTable\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Inventory\Http\Concerns\AuthorizesBranch;
use Modules\Inventory\Models\StockTransfer;
use Modules\Inventory\Models\StockTransferItem;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\StockDocumentService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Chuyển hàng" between branches: created at the sending branch, shipped
 * (stock out), then received at the other branch (stock in).
 */
class StockTransferController extends Controller
{
    use AuthorizesBranch;

    public function __construct(protected StockDocumentService $documents) {}

    public function index(Request $request): Response|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $branchIds = $user->branchIds();
        $showValue = $user->can('inventory.view_transfer_price');

        $table = DataTable::for(
            StockTransfer::query()
                ->with(['fromBranch:id,name', 'toBranch:id,name', 'creator:id,name', 'items'])
                ->where(fn (Builder $query) => $query->whereIn('from_branch_id', $branchIds)->orWhereIn('to_branch_id', $branchIds)),
            $request,
            'stock-transfers',
        )
            ->search(['code', 'note'])
            ->filterExact('status')
            ->filterExact('from_branch_id')
            ->filterExact('to_branch_id')
            ->filterDateRange('created_at')
            ->sortable(['code', 'created_at', 'shipped_at', 'received_at'], '-id');

        $value = fn (StockTransfer $transfer): ?int => $showValue ? (int) $transfer->items->sum(fn (StockTransferItem $item): int => $item->unit_cost * $item->quantity) : null;

        if ($request->query('export') === 'xlsx') {
            abort_unless($user->can('inventory.export'), 403);

            return $table->export('chuyen-hang-'.now()->format('Ymd-His'), array_filter([
                'Mã phiếu' => fn (StockTransfer $transfer) => $transfer->code,
                'Chi nhánh chuyển' => fn (StockTransfer $transfer) => $transfer->fromBranch->name,
                'Chi nhánh nhận' => fn (StockTransfer $transfer) => $transfer->toBranch->name,
                'Trạng thái' => fn (StockTransfer $transfer) => StockTransfer::STATUSES[$transfer->status] ?? $transfer->status,
                'Số lượng' => fn (StockTransfer $transfer) => (int) $transfer->items->sum('quantity'),
                'Giá trị' => $showValue ? $value : null,
                'Ngày tạo' => fn (StockTransfer $transfer) => $transfer->created_at?->setTimezone((string) config('app.timezone')),
                'Ngày chuyển' => fn (StockTransfer $transfer) => $transfer->shipped_at?->setTimezone((string) config('app.timezone')),
                'Ngày nhận' => fn (StockTransfer $transfer) => $transfer->received_at?->setTimezone((string) config('app.timezone')),
            ]));
        }

        return Inertia::render('stock-transfers/index', [
            'transfers' => $table->paginate(fn (StockTransfer $transfer): array => [
                'id' => $transfer->id,
                'code' => $transfer->code,
                'from_branch' => $transfer->fromBranch->name,
                'to_branch' => $transfer->toBranch->name,
                'status' => $transfer->status,
                'quantity' => (int) $transfer->items->sum('quantity'),
                'value' => $value($transfer),
                'creator' => $transfer->creator?->name,
                'created_at' => $transfer->created_at?->toIso8601String(),
                'shipped_at' => $transfer->shipped_at?->toIso8601String(),
                'received_at' => $transfer->received_at?->toIso8601String(),
            ]),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('stock-transfers/form', ['transfer' => null, ...$this->options($request)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $transfer = $this->documents->saveTransfer(null, $this->validated($request));

        if ($request->boolean('ship')) {
            abort_unless($request->user()?->can('inventory.approve'), 403);
            $this->documents->ship($transfer);
            $this->toast("Đã xuất hàng theo phiếu chuyển {$transfer->code}.");
        } else {
            $this->toast("Đã tạo phiếu chuyển hàng {$transfer->code}.");
        }

        return to_route('stock-transfers.show', $transfer);
    }

    public function show(Request $request, StockTransfer $transfer): Response
    {
        $this->authorizeBranch($request, $transfer->from_branch_id, $transfer->to_branch_id);
        $transfer->load(['items.variant.product', 'fromBranch:id,name', 'toBranch:id,name', 'creator:id,name', 'shipper:id,name', 'receiver:id,name']);

        /** @var User $user */
        $user = $request->user();
        $showValue = $user->can('inventory.view_transfer_price');
        $inventory = app(InventoryService::class);

        return Inertia::render('stock-transfers/form', [
            'transfer' => [
                'id' => $transfer->id,
                'code' => $transfer->code,
                'status' => $transfer->status,
                'from_branch_id' => $transfer->from_branch_id,
                'to_branch_id' => $transfer->to_branch_id,
                'from_branch' => $transfer->fromBranch->name,
                'to_branch' => $transfer->toBranch->name,
                'note' => $transfer->note,
                'creator' => $transfer->creator?->name,
                'shipper' => $transfer->shipper?->name,
                'receiver' => $transfer->receiver?->name,
                'created_at' => $transfer->created_at?->toIso8601String(),
                'shipped_at' => $transfer->shipped_at?->toIso8601String(),
                'received_at' => $transfer->received_at?->toIso8601String(),
                'can_ship' => in_array($transfer->from_branch_id, $user->branchIds(), true),
                'can_receive' => in_array($transfer->to_branch_id, $user->branchIds(), true),
                'items' => $transfer->items->map(fn (StockTransferItem $item): array => [
                    'variant_id' => $item->variant_id,
                    'product_id' => $item->variant->product_id,
                    'name' => $item->variant->name,
                    'sku' => $item->variant->sku,
                    'unit' => $item->variant->unit,
                    'image_url' => $item->variant->product->image_url,
                    'on_hand' => $inventory->stockOf($item->variant, [$transfer->from_branch_id])['on_hand'],
                    'quantity' => $item->quantity,
                    'received_quantity' => $item->received_quantity,
                    'unit_cost' => $showValue ? $item->unit_cost : null,
                ])->all(),
            ],
            ...$this->options($request),
        ]);
    }

    public function update(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $this->authorizeBranch($request, $transfer->from_branch_id);
        $this->documents->saveTransfer($transfer, $this->validated($request));

        if ($request->boolean('ship')) {
            abort_unless($request->user()?->can('inventory.approve'), 403);
            $this->documents->ship($transfer);
            $this->toast("Đã xuất hàng theo phiếu chuyển {$transfer->code}.");
        } else {
            $this->toast("Đã lưu phiếu chuyển hàng {$transfer->code}.");
        }

        return to_route('stock-transfers.show', $transfer);
    }

    public function ship(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $this->authorizeBranch($request, $transfer->from_branch_id);
        $this->documents->ship($transfer);
        $this->toast("Đã xuất hàng theo phiếu chuyển {$transfer->code}.");

        return back();
    }

    public function receive(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $this->authorizeBranch($request, $transfer->to_branch_id);
        $this->documents->receiveTransfer($transfer);
        $this->toast("Đã nhận hàng theo phiếu chuyển {$transfer->code}.");

        return back();
    }

    public function cancel(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $this->authorizeBranch($request, $transfer->from_branch_id);
        $this->documents->cancelTransfer($transfer);
        $this->toast("Đã hủy phiếu chuyển hàng {$transfer->code}.");

        return back();
    }

    /**
     * @return array{from_branch_id: int, to_branch_id: int, note: string|null, items: list<array{variant_id: int, quantity: int}>}
     */
    protected function validated(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'from_branch_id' => ['required', 'integer', Rule::in($user->branchIds())],
            'to_branch_id' => ['required', 'integer', 'different:from_branch_id', Rule::exists('branches', 'id')->where('tenant_id', tenancy()->id())->where('status', 'active')],
            'note' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000000'],
        ], [], [
            'from_branch_id' => 'chi nhánh chuyển',
            'to_branch_id' => 'chi nhánh nhận',
            'items' => 'sản phẩm',
            'items.*.quantity' => 'số lượng',
        ]);

        return [
            'from_branch_id' => (int) $data['from_branch_id'],
            'to_branch_id' => (int) $data['to_branch_id'],
            'note' => $data['note'] ?? null,
            'items' => array_values(array_map(fn (array $item): array => [
                'variant_id' => (int) $item['variant_id'],
                'quantity' => (int) $item['quantity'],
            ], $data['items'])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function options(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        return [
            'fromBranches' => Branch::query()->whereIn('id', $user->branchIds())->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'toBranches' => Branch::query()->where('status', 'active')->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'currentBranchId' => app(CurrentBranch::class)->id($user),
        ];
    }
}
