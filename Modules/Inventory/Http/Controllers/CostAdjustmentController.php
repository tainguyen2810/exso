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
use Modules\Inventory\Models\CostAdjustment;
use Modules\Inventory\Models\CostAdjustmentItem;
use Modules\Inventory\Services\StockDocumentService;

/**
 * "Điều chỉnh giá vốn". Needs the cost permission on top of inventory ones.
 */
class CostAdjustmentController extends Controller
{
    use AuthorizesBranch;

    public function __construct(protected StockDocumentService $documents) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeCost($user);
        $branchIds = $user->branchIds();

        $table = DataTable::for(
            CostAdjustment::query()->with(['branch:id,name', 'creator:id,name'])->withCount('items')->whereIn('branch_id', $branchIds),
            $request,
            'cost-adjustments',
        )
            ->search(['code', 'note'])
            ->filterExact('status')
            ->filterDateRange('created_at')
            ->sortable(['code', 'created_at', 'applied_at'], '-id');

        return Inertia::render('cost-adjustments/index', [
            'adjustments' => $table->paginate(fn (CostAdjustment $adjustment): array => [
                'id' => $adjustment->id,
                'code' => $adjustment->code,
                'branch' => $adjustment->branch->name,
                'status' => $adjustment->status,
                'items_count' => (int) $adjustment->getAttribute('items_count'),
                'note' => $adjustment->note,
                'creator' => $adjustment->creator?->name,
                'created_at' => $adjustment->created_at?->toIso8601String(),
                'applied_at' => $adjustment->applied_at?->toIso8601String(),
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeCost($user);

        return Inertia::render('cost-adjustments/form', ['adjustment' => null, ...$this->options($user)]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeCost($user);
        $adjustment = $this->documents->saveCostAdjustment(null, $this->validated($request, $user));
        $this->finish($request, $adjustment);

        return to_route('cost-adjustments.show', $adjustment);
    }

    public function show(Request $request, CostAdjustment $adjustment): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeCost($user);
        $this->authorizeBranch($request, $adjustment->branch_id);
        $adjustment->load(['items.variant.product', 'branch:id,name', 'creator:id,name', 'applier:id,name']);

        return Inertia::render('cost-adjustments/form', [
            'adjustment' => [
                'id' => $adjustment->id,
                'code' => $adjustment->code,
                'status' => $adjustment->status,
                'branch_id' => $adjustment->branch_id,
                'note' => $adjustment->note,
                'creator' => $adjustment->creator?->name,
                'applier' => $adjustment->applier?->name,
                'created_at' => $adjustment->created_at?->toIso8601String(),
                'applied_at' => $adjustment->applied_at?->toIso8601String(),
                'items' => $adjustment->items->map(fn (CostAdjustmentItem $item): array => [
                    'variant_id' => $item->variant_id,
                    'product_id' => $item->variant->product_id,
                    'name' => $item->variant->name,
                    'sku' => $item->variant->sku,
                    'unit' => $item->variant->unit,
                    'image_url' => $item->variant->product->image_url,
                    'old_cost' => $adjustment->status === CostAdjustment::DRAFT ? $item->variant->cost_price : $item->old_cost,
                    'new_cost' => $item->new_cost,
                ])->all(),
            ],
            ...$this->options($user),
        ]);
    }

    public function update(Request $request, CostAdjustment $adjustment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeCost($user);
        $this->authorizeBranch($request, $adjustment->branch_id);
        $this->documents->saveCostAdjustment($adjustment, $this->validated($request, $user));
        $this->finish($request, $adjustment);

        return to_route('cost-adjustments.show', $adjustment);
    }

    public function cancel(Request $request, CostAdjustment $adjustment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeCost($user);
        $this->authorizeBranch($request, $adjustment->branch_id);
        $this->documents->cancelCostAdjustment($adjustment);
        $this->toast("Đã hủy phiếu {$adjustment->code}.");

        return back();
    }

    protected function finish(Request $request, CostAdjustment $adjustment): void
    {
        if ($request->boolean('apply')) {
            abort_unless($request->user()?->can('inventory.approve'), 403);
            $this->documents->applyCostAdjustment($adjustment);
            $this->toast("Đã điều chỉnh giá vốn theo phiếu {$adjustment->code}.");

            return;
        }

        $this->toast("Đã lưu phiếu {$adjustment->code}.");
    }

    protected function authorizeCost(User $user): void
    {
        abort_unless($user->can('products.view_cost'), 403, 'Bạn không có quyền xem giá vốn.');
    }

    /**
     * @return array{branch_id: int, note: string|null, items: list<array{variant_id: int, new_cost: int}>}
     */
    protected function validated(Request $request, User $user): array
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::in($user->branchIds())],
            'note' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.new_cost' => ['required', 'integer', 'min:0', 'max:999999999999'],
        ], [], ['items' => 'sản phẩm', 'items.*.new_cost' => 'giá vốn mới']);

        return [
            'branch_id' => (int) $data['branch_id'],
            'note' => $data['note'] ?? null,
            'items' => array_values(array_map(fn (array $item): array => [
                'variant_id' => (int) $item['variant_id'],
                'new_cost' => (int) $item['new_cost'],
            ], $data['items'])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function options(User $user): array
    {
        return [
            'branches' => Branch::query()->whereIn('id', $user->branchIds())->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'currentBranchId' => app(CurrentBranch::class)->id($user),
        ];
    }
}
