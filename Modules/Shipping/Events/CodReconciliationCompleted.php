<?php

namespace Modules\Shipping\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Shipping\Models\CodReconciliation;

/**
 * A COD reconciliation was completed: the COD is on the orders, the fees
 * the partner kept are an expense (the cash book records them).
 * Fired after the transaction commits.
 */
class CodReconciliationCompleted
{
    use Dispatchable;

    public function __construct(public readonly CodReconciliation $reconciliation) {}
}
