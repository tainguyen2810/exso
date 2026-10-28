<?php

namespace Modules\Purchase\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Purchase\Models\PurchaseReceipt;
use Modules\Purchase\Models\SupplierDebt;

/**
 * The store paid a supplier for a receipt. Fired after the transaction
 * commits; the cash book listens to create its payment voucher.
 */
class SupplierPaymentRecorded
{
    use Dispatchable;

    public function __construct(public readonly SupplierDebt $line, public readonly PurchaseReceipt $receipt) {}
}
