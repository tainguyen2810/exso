<?php

namespace Modules\Customer\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerDebt;

/**
 * The only place a customer's debt changes. Locks the customer row and
 * writes a ledger line, like InventoryService does for stock.
 */
class CustomerLedger
{
    /**
     * @param  int  $amount  positive = the customer owes more, negative = owes less
     * @param  array{reference?: Model|null, reference_code?: string|null, note?: string|null}  $options
     */
    public function record(Customer $customer, string $type, int $amount, array $options = []): ?CustomerDebt
    {
        if ($amount === 0) {
            return null;
        }

        return DB::transaction(function () use ($customer, $type, $amount, $options): CustomerDebt {
            /** @var Customer $locked */
            $locked = Customer::query()->withTrashed()->lockForUpdate()->findOrFail($customer->id);
            $debt = $locked->debt + $amount;
            $locked->forceFill(['debt' => $debt])->saveQuietly();
            $customer->debt = $debt;

            $reference = $options['reference'] ?? null;

            /** @var CustomerDebt $line */
            $line = CustomerDebt::query()->create([
                'customer_id' => $locked->id,
                'type' => $type,
                'amount' => $amount,
                'debt_after' => $debt,
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
