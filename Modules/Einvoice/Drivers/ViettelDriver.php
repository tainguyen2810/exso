<?php

namespace Modules\Einvoice\Drivers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Einvoice\Models\Einvoice;
use Modules\Einvoice\Models\EinvoiceItem;
use Modules\Einvoice\Models\EinvoiceProvider;
use Modules\Einvoice\Support\MoneyWords;

/**
 * Viettel S-Invoice through its API (api-vinvoice.viettel.vn; the test
 * environment is a separate host with a test account): log in for a
 * token, then call InvoiceAPI with it as the access_token cookie. Answers
 * are {errorCode, description, result}; errorCode null means success.
 *
 * Every invoice carries a transactionUuid; searchInvoiceByTransactionUuid
 * finds an invoice a timed-out call already issued, so it is never issued twice.
 */
class ViettelDriver implements EinvoiceDriver
{
    public const PRODUCTION = 'https://api-vinvoice.viettel.vn';

    public const SANDBOX = 'https://demo-sinvoice.viettel.vn:8443';

    protected const API = '/services/einvoiceapplication/api/InvoiceAPI';

    protected ?string $token = null;

    public function __construct(protected EinvoiceProvider $provider) {}

    public static function label(): string
    {
        return 'Viettel S-Invoice';
    }

    public static function fields(): array
    {
        return [
            'seller_tax_code' => ['Mã số thuế người bán', 'MST đăng ký với Viettel, ví dụ 0100109106-997.'],
            'template_code' => ['Mẫu số hóa đơn', 'Ví dụ 1/001 (hóa đơn GTGT).'],
            'invoice_series' => ['Ký hiệu hóa đơn', 'Ví dụ C26TAA.'],
        ];
    }

    public function verify(): void
    {
        $this->login();
    }

    public function issue(Einvoice $invoice): IssuedInvoice
    {
        $response = $this->post('/InvoiceWS/createInvoice/'.rawurlencode($this->taxCode()), $this->payload($invoice));
        $result = $this->result($response);
        $number = $result['invoiceNo'] ?? null;

        if (! is_string($number) || $number === '') {
            throw new EinvoiceException('Viettel không trả về số hóa đơn.');
        }

        return new IssuedInvoice(
            invoiceNo: $number,
            lookupCode: is_string($result['reservationCode'] ?? null) ? $result['reservationCode'] : null,
            issuedAt: now()->toImmutable(),
        );
    }

    public function find(Einvoice $invoice): ?IssuedInvoice
    {
        $response = $this->post('/InvoiceWS/searchInvoiceByTransactionUuid', [
            'supplierTaxCode' => $this->taxCode(),
            'transactionUuid' => $invoice->transaction_uuid,
        ], true);

        $json = $response->json();
        $result = is_array($json) ? ($json['result'] ?? null) : null;
        $first = is_array($result) ? ($result[0] ?? $result) : null;

        if (! is_array($first) || ! is_string($first['invoiceNo'] ?? null) || $first['invoiceNo'] === '') {
            return null;
        }

        $issued = isset($first['issueDate']) && is_numeric($first['issueDate'])
            ? CarbonImmutable::createFromTimestampMs((int) $first['issueDate'])
            : null;

        return new IssuedInvoice($first['invoiceNo'], is_string($first['reservationCode'] ?? null) ? $first['reservationCode'] : null, $issued);
    }

    public function cancel(Einvoice $invoice, string $reason): void
    {
        $this->result($this->post('/InvoiceWS/cancelTransactionInvoice', [
            'supplierTaxCode' => $this->taxCode(),
            'invoiceNo' => (string) $invoice->invoice_no,
            'strIssueDate' => ($invoice->issued_at ?? now()->toImmutable())->setTimezone('Asia/Ho_Chi_Minh')->format('YmdHis'),
            'additionalReferenceDesc' => mb_substr($reason, 0, 255),
            'additionalReferenceDate' => (string) now()->getTimestampMs(),
        ], true));
    }

    public function pdf(Einvoice $invoice): array
    {
        $result = $this->post('/InvoiceUtilsWS/getInvoiceRepresentationFile', [
            'supplierTaxCode' => $this->taxCode(),
            'invoiceNo' => (string) $invoice->invoice_no,
            'templateCode' => (string) $invoice->template_code,
            'transactionUuid' => $invoice->transaction_uuid,
            'fileType' => 'PDF',
        ])->json();

        $content = is_array($result) && is_string($result['fileToBytes'] ?? null) ? base64_decode($result['fileToBytes'], true) : false;

        if ($content === false || $content === '') {
            $message = is_array($result) ? ($result['description'] ?? null) : null;

            throw new EinvoiceException('Viettel: '.(is_string($message) && $message !== '' ? $message : 'không lấy được file hóa đơn.'));
        }

        return ['name' => "{$invoice->invoice_no}.pdf", 'content' => $content];
    }

    /**
     * The createInvoice body. Amounts come from EinvoiceService (already
     * split before/after tax); prices that include tax are sent as the
     * price before tax with the line discount folded in.
     *
     * @return array<string, mixed>
     */
    public function payload(Einvoice $invoice): array
    {
        $template = (string) ($invoice->template_code ?? $this->provider->setting('template_code'));
        $general = [
            'invoiceType' => str_starts_with($template, '2') ? '2' : '1',
            'templateCode' => $template,
            'invoiceSeries' => (string) ($invoice->series ?? $this->provider->setting('invoice_series')),
            'currencyCode' => 'VND',
            'adjustmentType' => '1',
            'paymentStatus' => true,
            'cusGetInvoiceRight' => true,
            'transactionUuid' => $invoice->transaction_uuid,
        ];

        $increase = true;

        if ($invoice->kind === Einvoice::ADJUSTMENT && $invoice->original !== null) {
            $original = $invoice->original;
            $increase = $invoice->adjustment_type !== 'decrease';
            $general = [
                ...$general,
                'adjustmentType' => '5',
                'adjustmentInvoiceType' => $invoice->adjustment_type === 'info' ? '2' : '1',
                'originalInvoiceId' => $original->invoice_no,
                'originalInvoiceIssueDate' => $original->issued_at?->getTimestampMs(),
                'originalTemplateCode' => $original->template_code,
                'additionalReferenceDesc' => mb_substr((string) ($invoice->note ?? 'Điều chỉnh hóa đơn '.$original->invoice_no), 0, 255),
                'additionalReferenceDate' => now()->getTimestampMs(),
            ];
        }

        $items = [];
        $breakdowns = [];

        foreach ($invoice->items as $index => $item) {
            /** @var EinvoiceItem $item */
            // Tax inside the price: send the price before tax, discount folded in.
            $folded = $item->amount !== $item->amount_before_tax;
            $items[] = array_filter([
                'lineNumber' => $index + 1,
                'selection' => 1,
                'itemCode' => $item->code,
                'itemName' => $item->name,
                'unitName' => $item->unit,
                'quantity' => $item->quantity,
                'unitPrice' => $folded
                    ? ($item->quantity > 0 ? round($item->amount_before_tax / $item->quantity, 4) : 0)
                    : $item->price,
                'itemDiscount' => $folded ? null : $item->discount,
                'itemTotalAmountWithoutTax' => $item->amount_before_tax,
                'taxPercentage' => $item->tax_percent,
                'taxAmount' => $item->tax_amount,
                'itemTotalAmountWithTax' => $item->amount_after_tax,
                'itemNote' => $item->is_promotion ? 'Hàng khuyến mại không thu tiền' : null,
                'isIncreaseItem' => $invoice->kind === Einvoice::ADJUSTMENT ? $increase : null,
            ], fn (mixed $value): bool => $value !== null);

            $rate = (string) $item->tax_percent;
            $breakdowns[$rate] ??= ['taxPercentage' => $item->tax_percent, 'taxableAmount' => 0, 'taxAmount' => 0];
            $breakdowns[$rate]['taxableAmount'] += $item->amount_before_tax;
            $breakdowns[$rate]['taxAmount'] += $item->tax_amount;
        }

        return [
            'generalInvoiceInfo' => array_filter($general, fn (mixed $value): bool => $value !== null),
            'buyerInfo' => array_filter([
                'buyerName' => $invoice->buyer_name,
                'buyerLegalName' => $invoice->buyer_company,
                'buyerTaxCode' => $invoice->buyer_tax_code,
                'buyerAddressLine' => $invoice->buyer_address,
                'buyerPhoneNumber' => $invoice->buyer_phone,
                'buyerEmail' => $invoice->buyer_email,
                'buyerIdNo' => $invoice->buyer_id_number,
                'buyerIdType' => $invoice->buyer_id_number !== null ? '1' : null,
                'buyerBankAccount' => $invoice->buyer_bank_account,
                'buyerBankName' => $invoice->buyer_bank_name,
                'buyerNotGetInvoice' => $invoice->buyer_name === null && $invoice->buyer_company === null ? 1 : null,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            'payments' => [['paymentMethodName' => $invoice->payment_method]],
            'itemInfo' => $items,
            'summarizeInfo' => [
                'totalAmountWithoutTax' => $invoice->amount_before_tax,
                'totalTaxAmount' => $invoice->tax_amount,
                'totalAmountWithTax' => $invoice->total,
                'totalAmountWithTaxInWords' => MoneyWords::of($invoice->total),
                'discountAmount' => 0,
            ],
            'taxBreakdowns' => array_values($breakdowns),
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function post(string $path, array $body, bool $form = false): Response
    {
        $token = $this->token ??= $this->login();
        $request = $this->http()->withHeaders(['Cookie' => 'access_token='.$token]);

        try {
            return $form
                ? $request->asForm()->post($this->baseUrl().self::API.$path, $body)
                : $request->asJson()->post($this->baseUrl().self::API.$path, $body);
        } catch (ConnectionException) {
            throw new EinvoiceException('Không kết nối được Viettel S-Invoice, hãy thử lại sau.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function result(Response $response): array
    {
        $json = $response->json();

        if (! is_array($json) || $response->failed() || ($json['errorCode'] ?? null) !== null) {
            $message = is_array($json) ? ($json['description'] ?? $json['message'] ?? $json['errorCode'] ?? null) : null;

            throw new EinvoiceException('Viettel: '.(is_string($message) && $message !== '' ? $message : 'lỗi '.$response->status().'.'));
        }

        return is_array($json['result'] ?? null) ? $json['result'] : [];
    }

    protected function login(): string
    {
        $username = $this->provider->credential('username');
        $password = $this->provider->credential('password');

        if ($username === null || $password === null) {
            throw new EinvoiceException('Chưa nhập tài khoản Viettel S-Invoice.');
        }

        try {
            $response = $this->http()->asJson()->post($this->baseUrl().'/auth/login', ['username' => $username, 'password' => $password]);
        } catch (ConnectionException) {
            throw new EinvoiceException('Không kết nối được Viettel S-Invoice, hãy thử lại sau.');
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '' || $response->failed()) {
            throw new EinvoiceException('Viettel: tài khoản hoặc mật khẩu không đúng.');
        }

        return $token;
    }

    protected function taxCode(): string
    {
        $code = $this->provider->setting('seller_tax_code');

        if (! is_string($code) || $code === '') {
            throw new EinvoiceException('Chưa nhập mã số thuế người bán.');
        }

        return $code;
    }

    protected function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(30)->connectTimeout(5);
    }

    protected function baseUrl(): string
    {
        // A local test server can stand in for Viettel (development only).
        $override = config('einvoice.viettel_url');

        if (is_string($override) && $override !== '') {
            return rtrim($override, '/');
        }

        return $this->provider->is_sandbox ? self::SANDBOX : self::PRODUCTION;
    }
}
