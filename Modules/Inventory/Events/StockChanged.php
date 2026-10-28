<?php

namespace Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Inventory\Models\StockMovement;

/**
 * Fired after the transaction that wrote the movements commits.
 */
class StockChanged
{
    use Dispatchable;

    /**
     * @param  list<StockMovement>  $movements
     */
    public function __construct(public readonly array $movements) {}
}
