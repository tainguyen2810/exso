<?php

namespace Modules\Order\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Order\Models\OrderPayment;

/**
 * Money was received for an order or refunded (amount < 0). Fired after
 * the transaction commits; the cash book listens to create its vouchers.
 */
class OrderPaymentRecorded
{
    use Dispatchable;

    public function __construct(public readonly OrderPayment $payment) {}
}
