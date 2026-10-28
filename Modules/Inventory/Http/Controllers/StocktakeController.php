<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Support\Branches\CurrentBranch;
use App\Support\DataTable\DataTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Inventory\Http\Concerns\AuthorizesBranch;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\Stocktake;
use Modules\Inventory\Models\StocktakeItem;
use Modules\Inventory\Services\StockDocumentService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Kiểm hàng": count stock, then balance the branch to the counted numbers.
 */
class StocktakeController extends Controller
{
    use AuthorizesBranch;

    public function __construct(protected StockDocumentService $documents) {}

    public function index(Request $request): Response|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $branchIds = $user->branchIds();

        $table = DataTable::for(
            Stocktake::query()->with(['branch:id,name', 'creator:id,name', 'balancer:id,name'])->withCount('items')->whereIn('branch_id', $branchIds),
            $request,
            'stocktakes',
        )
            ->search(['code', 'note'])
            ->filterExact('status')
            ->filterExact('branch_id')
            ->filterDateRange('created_at')
            ->sortable(['code', 'created_at', 'balanced_at'], '-id');

        if ($request->query('export') === 'xlsx') {
            abort_unless($user->can('inventory.export'), 403);

            return $table->export('kiem-hang-'.now()->format('Ymd-His'), [
                'Mã phiếu' => fn (Stocktake $stocktake) => $stocktake->code,
                'Chi nhánh' => fn (Stocktake $stocktake) => $stocktake->branch->name,
                'Trạng thái' => fn (Stocktake $stocktake) => Stocktake::STATUSES[$stocktake->status] ?? $stocktake->status,
                'Số sản phẩm' => fn (Stocktake $stocktake) => (int) $stocktake->getAttribute('items_count'),
                'Ngày tạo' => fn (Stocktake $stocktake) => $stocktake->created_at?->setTimezone((string) config('app.timezone')),
                'Ngày cân bằng' => fn (Stocktake $stocktake) => $stocktake->balanced_at?->setTimezone((string) config('app.timezone')),
                'Người tạo' => fn (Stocktake $stocktake) => $stocktake->creator?->name,
                'Ghi chú' => fn (Stocktake $stocktake) => $stocktake->note,
            ]);
        }

        return Inertia::render('stocktakes/index', [
            'stocktakes' => $table->paginate(fn (Stocktake $stocktake): array => [
                'id' => $stocktake->id,
                'code' => $stocktake->code,
                'branch' => $stocktake->branch->name,
                'status' => $stocktake->status,
                'items_count' => (int) $stocktake->getAttribute('items_count'),
                'note' => $stocktake->note,
                'creator' => $stocktake->creator?->name,
                'balancer' => $stocktake->balancer?->name,
                'created_at' => $stocktake->created_at?->toIso8601String(),
                'balanced_at' => $stocktake->balanced_at?->toIso8601String(),
            ]),
            'branches' => Branch::query()->whereIn('id', $branchIds)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('stocktakes/form', ['stocktake' => null, ...$this->options($request)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $stocktake = $this->documents->saveStocktake(null, $this->validated($request));

        if ($request->boolean('balance')) {
            abort_unless($request->user()?->can('inventory.approve'), 403);
            $this->documents->balance($stocktake);
            $this->toast("Đã cân bằng kho theo phiếu {$stocktake->code}.");
        } else {
            $this->toast("Đã lưu phiếu kiểm hàng {$stocktake->code}.");
        }

        return to_route('stocktakes.show', $stocktake);
    }

    public function show(Request $request, Stocktake $stocktake): Response
    {
        $this->authorizeBranch($request, $stocktake->branch_id);
        $stocktake->load(['items.variant.product', 'branch:id,name', 'creator:id,name', 'balancer:id,name']);
        $live = $stocktake->status === Stocktake::DRAFT
            ? Inventory::query()->where('branch_id', $stocktake->branch_id)->whereIn('variant_id', $stocktake->items->pluck('variant_id'))->pluck('on_hand', 'variant_id')
            : collect();

        return Inertia::render('stocktakes/form', [
            'stocktake' => [
                'id' => $stocktake->id,
                'code' => $stocktake->code,
                'status' => $stocktake->status,
                'branch_id' => $stocktake->branch_id,
                'branch' => $stocktake->branch->name,
                'note' => $stocktake->note,
                'creator' => $stocktake->creator?->name,
                'balancer' => $stocktake->balancer?->name,
                'created_at' => $stocktake->created_at?->toIso8601String(),
                'balanced_at' => $stocktake->balanced_at?->toIso8601String(),
                'items' => $stocktake->items->map(fn (StocktakeItem $item): array => [
                    'variant_id' => $item->variant_id,
                    'product_id' => $item->variant->product_id,
                    'name' => $item->variant->name,
                    'sku' => $item->variant->sku,
                    'unit' => $item->variant->unit,
                    'image_url' => $item->variant->product->image_url,
                    'system_quantity' => $stocktake->status === Stocktake::DRAFT ? (int) ($live[$item->variant_id] ?? 0) : $item->system_quantity,
                    'actual_quantity' => $item->actual_quantity,
                    'reason' => $item->reason,
                ])->all(),
            ],
            ...$this->options($request),
        ]);
    }

    public function update(Request $request, Stocktake $stocktake): RedirectResponse
    {
        $this->authorizeBranch($request, $stocktake->branch_id);
        $this->documents->saveStocktake($stocktake, $this->validated($request));

        if ($request->boolean('balance')) {
            abort_unless($request->user()?->can('inventory.approve'), 403);
            $this->documents->balance($stocktake);
            $this->toast("Đã cân bằng kho theo phiếu {$stocktake->code}.");
        } else {
            $this->toast("Đã lưu phiếu kiểm hàng {$stocktake->code}.");
        }

        return to_route('stocktakes.show', $stocktake);
    }

    public function cancel(Request $request, Stocktake $stocktake): RedirectResponse
    {
        $this->authorizeBranch($request, $stocktake->branch_id);
        $this->documents->cancelStocktake($stocktake);
        $this->toast("Đã hủy phiếu kiểm hàng {$stocktake->code}.");

        return back();
    }

    /**
     * @return array{branch_id: int, note: string|null, items: list<array{variant_id: int, actual_quantity: int, reason: string|null}>}
     */
    protected function validated(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::in($user->branchIds())],
            'note' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.actual_quantity' => ['required', 'integer', 'min:0', 'max:100000000'],
            'items.*.reason' => ['nullable', 'string', 'max:100'],
        ], [], ['branch_id' => 'chi nhánh', 'items' => 'sản phẩm', 'items.*.actual_quantity' => 'tồn thực tế']);

        return [
            'branch_id' => (int) $data['branch_id'],
            'note' => $data['note'] ?? null,
            'items' => array_values(array_map(fn (array $item): array => [
                'variant_id' => (int) $item['variant_id'],
                'actual_quantity' => (int) $item['actual_quantity'],
                'reason' => $item['reason'] ?? null,
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
            'branches' => Branch::query()->whereIn('id', $user->branchIds())->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'currentBranchId' => app(CurrentBranch::class)->id($user),
            'reasons' => ['Hư hỏng', 'Hao mòn', 'Thất lạc', 'Trả hàng', 'Khác'],
        ];
    }

}
