<?php

namespace Modules\Purchase\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Models\Supplier;
use Modules\Purchase\Models\SupplierDebt;

/**
 * The only place a supplier's debt changes. Locks the supplier row and
 * writes a ledger line, like InventoryService does for stock.
 */
class SupplierLedger
{
    /**
     * @param  int  $amount  positive = the store owes more, negative = owes less
     * @param  array{reference?: Model|null, reference_code?: string|null, note?: string|null, payment_method?: string|null}  $options
     */
    public function record(Supplier $supplier, string $type, int $amount, array $options = []): ?SupplierDebt
    {
        if ($amount === 0) {
            return null;
        }

        return DB::transaction(function () use ($supplier, $type, $amount, $options): SupplierDebt {
            /** @var Supplier $locked */
            $locked = Supplier::query()->withTrashed()->lockForUpdate()->findOrFail($supplier->id);
            $debt = $locked->debt + $amount;
            $locked->forceFill(['debt' => $debt])->saveQuietly();
            $supplier->debt = $debt;

            $reference = $options['reference'] ?? null;

            /** @var SupplierDebt $line */
            $line = SupplierDebt::query()->create([
                'supplier_id' => $locked->id,
                'type' => $type,
                'amount' => $amount,
                'debt_after' => $debt,
                'payment_method' => $options['payment_method'] ?? null,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'reference_code' => $options['reference_code'] ?? null,
                'note' => $options['note'] ?? null,
                'created_by' => auth()->id(),
            ]);

            return $line;
        });
    }
}
