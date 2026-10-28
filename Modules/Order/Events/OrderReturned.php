<?php

namespace Modules\Order\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Order\Models\OrderReturn;

/**
 * Goods of an order came back (khách trả hàng). Fired after the
 * transaction commits; reports refresh the day of the return.
 */
class OrderReturned
{
    use Dispatchable;

    public function __construct(public readonly OrderReturn $return) {}
}
