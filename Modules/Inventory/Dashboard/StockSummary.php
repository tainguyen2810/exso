<?php

namespace Modules\Inventory\Dashboard;

use App\Support\Dashboard\DashboardContext;
use Modules\Inventory\Models\Inventory;

/**
 * "Thông tin kho" on Tổng quan (filter dashboard.stock): products below
 * their minimum, stock quantity and stock value in the chosen branches.
 */
class StockSummary
{
    /**
     * @param  array<string, mixed>|null  $value
     * @return array{below_min: int, on_hand: int, value: ?int, links: array{below_min: string, inventory: string}}
     */
    public function __invoke(?array $value, DashboardContext $context): array
    {
        $inBranches = fn () => Inventory::query()->whereIn('inventories.branch_id', $context->branchIds);

        $totals = $inBranches()
            ->join('product_variants', 'product_variants.id', '=', 'inventories.variant_id')
            ->selectRaw('COALESCE(SUM(inventories.on_hand), 0) as on_hand, COALESCE(SUM(CASE WHEN inventories.on_hand > 0 THEN inventories.on_hand * product_variants.cost_price ELSE 0 END), 0) as value')
            ->first();

        $branch = count($context->branchIds) === 1 ? 'filter[branch_id]='.$context->branchIds[0] : '';

        return [
            'below_min' => $inBranches()
                ->whereNotNull('min_quantity')
                ->whereColumn('on_hand', '<', 'min_quantity')
                ->distinct()
                ->count('variant_id'),
            'on_hand' => (int) ($totals?->getAttribute('on_hand') ?? 0),
            // Stock value shows cost prices, a data permission.
            'value' => $context->user->can('products.view_cost') ? (int) ($totals?->getAttribute('value') ?? 0) : null,
            'links' => [
                'below_min' => '/inventory?filter[stock]=below_min'.($branch !== '' ? "&{$branch}" : ''),
                'inventory' => '/inventory'.($branch !== '' ? "?{$branch}" : ''),
            ],
        ];
    }
}
