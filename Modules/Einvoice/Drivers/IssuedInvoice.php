<?php

namespace Modules\Einvoice\Drivers;

use Carbon\CarbonImmutable;

/**
 * What the provider gives back for an issued invoice.
 */
final class IssuedInvoice
{
    public function __construct(
        public readonly string $invoiceNo,
        public readonly ?string $lookupCode = null,
        public readonly ?CarbonImmutable $issuedAt = null,
    ) {}
}
