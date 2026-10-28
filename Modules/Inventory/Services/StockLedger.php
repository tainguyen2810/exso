<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;

/**
 * Checks that inventories (the totals) match stock_movements (the ledger).
 */
class StockLedger
{
    /**
     * Rows where on_hand differs from the sum of movements, for the current tenant.
     *
     * @return list<array{branch_id: int, variant_id: int, on_hand: int, ledger: int}>
     */
    public function mismatches(): array
    {
        $tenantId = tenancy()->id();

        if ($tenantId === null) {
            return [];
        }

        $ledger = DB::table('stock_movements')
            ->where('tenant_id', $tenantId)
            ->groupBy('branch_id', 'variant_id')
            ->selectRaw('branch_id, variant_id, SUM(quantity) as total');

        $fromInventories = DB::table('inventories as i')
            ->leftJoinSub($ledger, 'l', fn ($join) => $join->on('l.branch_id', '=', 'i.branch_id')->on('l.variant_id', '=', 'i.variant_id'))
            ->where('i.tenant_id', $tenantId)
            ->whereRaw('i.on_hand <> COALESCE(l.total, 0)')
            ->get(['i.branch_id', 'i.variant_id', 'i.on_hand', DB::raw('COALESCE(l.total, 0) as ledger')]);

        $withoutInventory = DB::query()
            ->fromSub($ledger, 'l')
            ->whereNotExists(fn ($query) => $query->from('inventories as i')
                ->where('i.tenant_id', $tenantId)
                ->whereColumn('i.branch_id', 'l.branch_id')
                ->whereColumn('i.variant_id', 'l.variant_id'))
            ->where('l.total', '<>', 0)
            ->get(['l.branch_id', 'l.variant_id', DB::raw('0 as on_hand'), 'l.total as ledger']);

        return array_values($fromInventories->concat($withoutInventory)
            ->map(fn (object $row): array => [
                'branch_id' => (int) $row->branch_id,
                'variant_id' => (int) $row->variant_id,
                'on_hand' => (int) $row->on_hand,
                'ledger' => (int) $row->ledger,
            ])
            ->all());
    }
}
