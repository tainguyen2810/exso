<?php

namespace Modules\Order\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Order\Models\Order;

/**
 * An order moved to another step (approved, shipped, completed, cancelled...).
 * Fired after the transaction commits.
 */
class OrderStatusChanged
{
    use Dispatchable;

    public function __construct(public readonly Order $order, public readonly string $action) {}
}
