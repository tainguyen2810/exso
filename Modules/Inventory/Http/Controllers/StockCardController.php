<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Support\DataTable\DataTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\StockMovement;
use Modules\Product\Models\ProductVariant;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Thẻ kho": every stock movement of one variant, newest first.
 */
class StockCardController extends Controller
{
    public function show(Request $request, ProductVariant $variant): Response|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $branchIds = $user->branchIds();
        $showCost = $user->can('products.view_cost');
        $variant->load('product');

        $table = DataTable::for(
            StockMovement::query()->with(['branch:id,name', 'creator:id,name'])->where('variant_id', $variant->id)->whereIn('branch_id', $branchIds),
            $request,
            'stock-card',
        )
            ->search(['reference_code', 'note'])
            ->filterExact('branch_id')
            ->filterExact('type')
            ->filterDateRange('created_at')
            ->sortable(['created_at'], '-id');

        if ($request->query('export') === 'xlsx') {
            abort_unless($user->can('inventory.export'), 403);

            return $table->export("the-kho-{$variant->sku}", array_filter([
                'Thời gian' => fn (StockMovement $movement) => $movement->created_at?->setTimezone((string) config('app.timezone')),
                'Chi nhánh' => fn (StockMovement $movement) => $movement->branch->name,
                'Loại' => fn (StockMovement $movement) => $movement->typeLabel(),
                'Chứng từ' => fn (StockMovement $movement) => $movement->reference_code,
                'Thay đổi' => fn (StockMovement $movement) => $movement->quantity,
                'Tồn sau' => fn (StockMovement $movement) => $movement->on_hand_after,
                'Giá vốn' => $showCost ? fn (StockMovement $movement) => $movement->cost_after : null,
                'Người thực hiện' => fn (StockMovement $movement) => $movement->creator?->name,
                'Ghi chú' => fn (StockMovement $movement) => $movement->note,
            ]));
        }

        return Inertia::render('inventory/stock-card', [
            'variant' => [
                'id' => $variant->id,
                'product_id' => $variant->product_id,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'unit' => $variant->unit,
                'cost_price' => $showCost ? $variant->cost_price : null,
                'retail_price' => $variant->retail_price,
                'tracks_inventory' => $variant->product->tracksInventory() && $variant->base_variant_id === null,
            ],
            'stock' => Inventory::query()
                ->with('branch:id,name')
                ->where('variant_id', $variant->id)
                ->whereIn('branch_id', $branchIds)
                ->get()
                ->map(fn (Inventory $inventory): array => [
                    'branch_id' => $inventory->branch_id,
                    'branch' => $inventory->branch->name,
                    'on_hand' => $inventory->on_hand,
                    'reserved' => $inventory->reserved,
                    'available' => $inventory->available(),
                    'min_quantity' => $inventory->min_quantity,
                    'max_quantity' => $inventory->max_quantity,
                ])
                ->values(),
            'movements' => $table->paginate(fn (StockMovement $movement): array => [
                'id' => $movement->id,
                'created_at' => $movement->created_at?->toIso8601String(),
                'branch' => $movement->branch->name,
                'type' => $movement->type,
                'type_label' => $movement->typeLabel(),
                'reference_code' => $movement->reference_code,
                'quantity' => $movement->quantity,
                'on_hand_after' => $movement->on_hand_after,
                'unit_cost' => $showCost ? $movement->unit_cost : null,
                'cost_after' => $showCost ? $movement->cost_after : null,
                'creator' => $movement->creator?->name,
                'note' => $movement->note,
            ]),
            'branches' => Branch::query()->whereIn('id', $branchIds)->orderBy('name')->get(['id', 'name']),
            'types' => array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys(StockMovement::TYPES), StockMovement::TYPES),
        ]);
    }

    /**
     * Minimum / maximum stock of the variant in one branch ("định mức tồn").
     */
    public function levels(Request $request, ProductVariant $variant): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::in($user->branchIds())],
            'min_quantity' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'max_quantity' => ['nullable', 'integer', 'min:0', 'max:100000000', 'gte:min_quantity'],
        ], [], ['min_quantity' => 'tồn tối thiểu', 'max_quantity' => 'tồn tối đa']);

        abort_if($variant->base_variant_id !== null, 422);

        Inventory::query()->updateOrCreate(
            ['branch_id' => (int) $data['branch_id'], 'variant_id' => $variant->id],
            ['min_quantity' => $data['min_quantity'] ?? null, 'max_quantity' => $data['max_quantity'] ?? null],
        );

        $this->toast('Đã lưu định mức tồn.');

        return back();
    }
}
