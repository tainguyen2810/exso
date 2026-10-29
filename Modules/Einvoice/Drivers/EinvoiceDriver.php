<?php

namespace Modules\Einvoice\Drivers;

use Modules\Einvoice\Models\Einvoice;

/**
 * An e-invoice provider (Viettel S-Invoice, MISA meInvoice, VNPT...). One
 * instance works for one connection (EinvoiceProvider). Add a provider by
 * implementing this and registering it with Hook::filter('einvoice.drivers').
 */
interface EinvoiceDriver
{
    /** Name shown on the pages, e.g. "Viettel S-Invoice". */
    public static function label(): string;

    /**
     * Fields of the connection form beyond username/password:
     * key => [label, help].
     *
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function fields(): array;

    /**
     * Check the account ("Kiểm tra kết nối").
     *
     * @throws EinvoiceException
     */
    public function verify(): void;

    /**
     * Issue the invoice (lines and totals already computed). The invoice's
     * transaction_uuid identifies the call: issuing again with the same one
     * must not make a second invoice.
     *
     * @throws EinvoiceException
     */
    public function issue(Einvoice $invoice): IssuedInvoice;

    /**
     * The invoice already issued with this transaction_uuid, if any (a
     * previous call may have reached the provider before failing here).
     *
     * @throws EinvoiceException
     */
    public function find(Einvoice $invoice): ?IssuedInvoice;

    /**
     * @throws EinvoiceException
     */
    public function cancel(Einvoice $invoice, string $reason): void;

    /**
     * The invoice as a PDF file ("Tải hóa đơn").
     *
     * @return array{name: string, content: string}
     *
     * @throws EinvoiceException
     */
    public function pdf(Einvoice $invoice): array;
}
