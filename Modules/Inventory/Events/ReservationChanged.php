<?php

namespace Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Stock held for orders changed ("Có thể bán" moved without a stock
 * movement). Fired after the transaction commits.
 */
class ReservationChanged
{
    use Dispatchable;

    /**
     * @param  list<int>  $variantIds  base variants whose reservation changed
     */
    public function __construct(public readonly int $branchId, public readonly array $variantIds) {}
}
