<?php

namespace Modules\Einvoice\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Support\DataTable\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Einvoice\Drivers\EinvoiceException;
use Modules\Einvoice\Drivers\EinvoiceManager;
use Modules\Einvoice\Models\Einvoice;
use Modules\Einvoice\Models\EinvoiceItem;
use Modules\Einvoice\Models\EinvoiceProvider;
use Modules\Einvoice\Services\EinvoiceService;
use Modules\Einvoice\Support\MoneyWords;
use Modules\Order\Models\Order;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Đơn hàng → Hóa đơn điện tử: the invoice list, "Đơn chưa tạo HĐĐT", one
 * invoice (edit while not issued, issue, cancel, adjust, PDF). Limited to
 * the user's branches; orders as Order::visibleTo.
 */
class EinvoiceController extends Controller
{
    public function __construct(protected EinvoiceService $invoices, protected EinvoiceManager $drivers) {}

    public function index(Request $request): Response|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        $table = DataTable::for(Einvoice::query()->visibleTo($user)->with(['order:id,code', 'branch:id,name', 'provider:id,name', 'original:id,code']), $request, 'einvoices')
            ->search(['code', 'invoice_no', 'lookup_code', 'buyer_name', 'buyer_company', 'buyer_tax_code', 'buyer_phone', 'order.code'])
            ->filterExact('status')
            ->filterExact('kind')
            ->filterExact('branch_id')
            ->filterExact('provider_id')
            ->filterDateRange('issued_at')
            ->filterDateRange('created_at')
            ->sortable(['created_at', 'issued_at', 'total', 'code'], '-created_at');

        if ($request->query('export') === 'xlsx') {
            abort_unless($user->can('einvoices.export'), 403);

            return $table->export('hoa-don-dien-tu-'.now()->format('Ymd-His'), [
                'Mã hóa đơn' => fn (Einvoice $invoice) => $invoice->code,
                'Mã đơn hàng' => fn (Einvoice $invoice) => $invoice->order?->code,
                'Loại hóa đơn' => fn (Einvoice $invoice) => $invoice->kind === Einvoice::ADJUSTMENT ? Einvoice::ADJUSTMENT_TYPES[$invoice->adjustment_type ?? 'info'] ?? 'Điều chỉnh' : 'Hóa đơn gốc',
                'Ký hiệu' => fn (Einvoice $invoice) => $invoice->series,
                'Số hóa đơn' => fn (Einvoice $invoice) => $invoice->invoice_no,
                'Trạng thái phát hành' => fn (Einvoice $invoice) => Einvoice::STATUSES[$invoice->status],
                'Đơn vị phát hành' => fn (Einvoice $invoice) => $invoice->provider?->name,
                'Mã tra cứu' => fn (Einvoice $invoice) => $invoice->lookup_code,
                'Chi nhánh' => fn (Einvoice $invoice) => $invoice->branch->name,
                'Người mua' => fn (Einvoice $invoice) => $invoice->buyer_name,
                'Đơn vị mua' => fn (Einvoice $invoice) => $invoice->buyer_company,
                'Mã số thuế' => fn (Einvoice $invoice) => $invoice->buyer_tax_code,
                'Tiền trước thuế' => fn (Einvoice $invoice) => $invoice->amount_before_tax,
                'Tiền thuế' => fn (Einvoice $invoice) => $invoice->tax_amount,
                'Tổng tiền' => fn (Einvoice $invoice) => $invoice->total,
                'Ngày hóa đơn' => fn (Einvoice $invoice) => $invoice->issued_at?->setTimezone((string) config('app.timezone')),
                'Phương thức thanh toán' => fn (Einvoice $invoice) => $invoice->payment_method,
                'Lỗi' => fn (Einvoice $invoice) => $invoice->error,
            ]);
        }

        return Inertia::render('e-invoices/index', [
            'invoices' => $table->paginate(fn (Einvoice $invoice): array => [
                'id' => $invoice->id,
                'code' => $invoice->code,
                'kind' => $invoice->kind,
                'adjustment_type' => $invoice->adjustment_type,
                'original_code' => $invoice->original?->code,
                'order_id' => $invoice->order_id,
                'order_code' => $invoice->order?->code,
                'status' => $invoice->status,
                'error' => $invoice->error,
                'series' => $invoice->series,
                'invoice_no' => $invoice->invoice_no,
                'provider' => $invoice->provider?->name,
                'lookup_code' => $invoice->lookup_code,
                'branch' => $invoice->branch->name,
                'buyer' => $invoice->buyer_company ?? $invoice->buyer_name,
                'buyer_tax_code' => $invoice->buyer_tax_code,
                'total' => $invoice->total,
                'issued_at' => $invoice->issued_at?->toIso8601String(),
                'created_at' => $invoice->created_at?->toIso8601String(),
                'payment_method' => $invoice->payment_method,
            ]),
            'branches' => $this->branches($user),
            'providers' => EinvoiceProvider::query()->orderBy('id')->get(['id', 'name']),
            'connected' => $this->drivers->active() !== null,
        ]);
    }

    /**
     * "Đơn chưa tạo HĐĐT": exported orders without an invoice in force.
     */
    public function pending(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $query = Order::query()->visibleTo($user)
            ->whereIn('status', [Order::SHIPPED, Order::COMPLETED])
            ->whereNotExists(fn (QueryBuilder $query) => $query->from('einvoices')
                ->whereColumn('einvoices.order_id', 'orders.id')
                ->where('einvoices.kind', Einvoice::ORIGINAL)
                ->where('einvoices.status', '!=', Einvoice::CANCELLED))
            ->with(['customer:id,name,tax_code', 'branch:id,name'])
            ->select('orders.*');

        $table = DataTable::for($query, $request, 'einvoice_pending')
            ->search(['code', 'customer_name', 'customer_phone', 'reference', 'customer.tax_code'])
            ->filterExact('branch_id')
            ->filterExact('status')
            ->filter('buyer', fn (Builder $query, mixed $value) => match ($value) {
                'company' => $query->whereHas('customer', fn (Builder $query) => $query->whereNotNull('tax_code')),
                'personal' => $query->whereDoesntHave('customer', fn (Builder $query) => $query->whereNotNull('tax_code')),
                default => $query,
            })
            ->filterDateRange('shipped_at')
            ->sortable(['shipped_at', 'completed_at', 'total', 'code'], '-shipped_at');

        return Inertia::render('e-invoices/pending', [
            'orders' => $table->paginate(fn (Order $order): array => [
                'id' => $order->id,
                'code' => $order->code,
                'status' => $order->status,
                'reference' => $order->reference,
                'customer' => $order->customer->name ?? $order->customer_name,
                'tax_code' => $order->customer?->tax_code,
                'branch' => $order->branch->name,
                'total' => $order->total - $order->returned_total,
                'shipped_at' => $order->shipped_at?->toIso8601String(),
                'completed_at' => $order->completed_at?->toIso8601String(),
            ]),
            'branches' => $this->branches($user),
            'connected' => $this->drivers->active() !== null,
        ]);
    }

    /**
     * "Tạo hóa đơn" for one order (from the order page).
     */
    public function storeForOrder(Request $request, Order $order): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($order->isVisibleTo($user), 404);

        $invoice = $this->invoices->invoiceOf($order) ?? $this->invoices->fromOrder($order, $user);
        $this->toast("Đã tạo hóa đơn nháp {$invoice->code}. Kiểm tra thông tin rồi bấm Phát hành.");

        return to_route('e-invoices.show', $invoice);
    }

    /**
     * Bulk on "Đơn chưa tạo HĐĐT": create drafts, or create and issue.
     */
    public function bulkOrders(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'action' => ['required', Rule::in(['create', 'issue'])],
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        if ($data['action'] === 'issue') {
            abort_unless($user->can('einvoices.approve'), 403);
        }

        $orders = Order::query()->visibleTo($user)->whereKey($data['ids'])->orderBy('id')->get();
        $done = 0;
        $errors = [];

        foreach ($orders as $order) {
            try {
                $invoice = $this->invoices->invoiceOf($order) ?? $this->invoices->fromOrder($order, $user);

                if ($data['action'] === 'issue' && $invoice->isEditable()) {
                    $this->invoices->issue($invoice, $user);
                }

                $done++;
            } catch (ValidationException $exception) {
                $errors[] = "{$order->code}: ".$exception->validator->errors()->first();
            }
        }

        $verb = $data['action'] === 'issue' ? 'phát hành' : 'tạo';
        $this->toast(
            "Đã {$verb} hóa đơn cho {$done} đơn.".($errors === [] ? '' : ' Lỗi: '.implode('; ', array_slice($errors, 0, 5)).(count($errors) > 5 ? '…' : '')),
            $errors === [] ? 'success' : ($done === 0 ? 'error' : 'warning'),
        );

        return back();
    }

    public function show(Request $request, Einvoice $einvoice): Response
    {
        $this->authorizeInvoice($request, $einvoice);
        $einvoice->load(['items', 'order:id,code,customer_id', 'branch:id,name', 'provider:id,name,driver,settings', 'original:id,code,invoice_no,series', 'creator:id,name']);
        /** @var User $user */
        $user = $request->user();

        $adjustments = Einvoice::query()->where('original_id', $einvoice->id)->orderBy('id')->get(['id', 'code', 'status', 'adjustment_type', 'invoice_no', 'total']);
        $provider = $einvoice->provider ?? $this->drivers->active();

        return Inertia::render('e-invoices/show', [
            'invoice' => [
                'id' => $einvoice->id,
                'code' => $einvoice->code,
                'kind' => $einvoice->kind,
                'adjustment_type' => $einvoice->adjustment_type,
                'original' => $einvoice->original === null ? null : [
                    'id' => $einvoice->original->id,
                    'code' => $einvoice->original->code,
                    'number' => $einvoice->original->invoice_no,
                ],
                'status' => $einvoice->status,
                'editable' => $einvoice->isEditable(),
                'error' => $einvoice->error,
                'order' => $einvoice->order === null ? null : ['id' => $einvoice->order->id, 'code' => $einvoice->order->code],
                'branch' => $einvoice->branch->name,
                'provider' => $provider?->name,
                'template_code' => $einvoice->template_code ?? $provider?->setting('template_code'),
                'series' => $einvoice->series ?? $provider?->setting('invoice_series'),
                'seller_tax_code' => $provider?->setting('seller_tax_code'),
                'invoice_no' => $einvoice->invoice_no,
                'lookup_code' => $einvoice->lookup_code,
                'issued_at' => $einvoice->issued_at?->toIso8601String(),
                'created_at' => $einvoice->created_at?->toIso8601String(),
                'creator' => $einvoice->creator?->name,
                'cancelled_at' => $einvoice->cancelled_at?->toIso8601String(),
                'cancel_reason' => $einvoice->cancel_reason,
                'buyer_name' => $einvoice->buyer_name,
                'buyer_company' => $einvoice->buyer_company,
                'buyer_tax_code' => $einvoice->buyer_tax_code,
                'buyer_address' => $einvoice->buyer_address,
                'buyer_email' => $einvoice->buyer_email,
                'buyer_phone' => $einvoice->buyer_phone,
                'buyer_id_number' => $einvoice->buyer_id_number,
                'buyer_bank_account' => $einvoice->buyer_bank_account,
                'buyer_bank_name' => $einvoice->buyer_bank_name,
                'payment_method' => $einvoice->payment_method,
                'note' => $einvoice->note,
                'subtotal' => $einvoice->subtotal,
                'discount' => $einvoice->discount,
                'amount_before_tax' => $einvoice->amount_before_tax,
                'tax_amount' => $einvoice->tax_amount,
                'total' => $einvoice->total,
                'total_words' => MoneyWords::of($einvoice->total),
                'items' => $einvoice->items->map(fn (EinvoiceItem $item): array => [
                    'id' => $item->id,
                    'variant_id' => $item->variant_id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'unit' => $item->unit,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'discount' => $item->discount,
                    'tax_percent' => $item->tax_percent,
                    'amount' => $item->amount,
                    'amount_before_tax' => $item->amount_before_tax,
                    'tax_amount' => $item->tax_amount,
                    'amount_after_tax' => $item->amount_after_tax,
                    'is_promotion' => $item->is_promotion,
                ])->all(),
                'adjustments' => $adjustments->map(fn (Einvoice $adjustment): array => [
                    'id' => $adjustment->id,
                    'code' => $adjustment->code,
                    'status' => $adjustment->status,
                    'type' => Einvoice::ADJUSTMENT_TYPES[$adjustment->adjustment_type ?? 'info'] ?? '',
                    'invoice_no' => $adjustment->invoice_no,
                    'total' => $adjustment->total,
                ])->all(),
            ],
            'taxRates' => collect(Einvoice::TAX_RATES)->map(fn (string $label, int $value): array => ['value' => (string) $value, 'label' => $label])->values()->all(),
            'adjustmentTypes' => collect(Einvoice::ADJUSTMENT_TYPES)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            'paymentMethods' => ['Tiền mặt', 'Chuyển khoản', 'TM/CK', 'Đối trừ công nợ', 'Không thu tiền'],
            'priceIncludesTax' => (bool) settings('tax.price_includes_tax'),
            'connected' => $provider !== null && $provider->is_active,
            'canPdf' => $einvoice->status === Einvoice::ISSUED && $user->can('einvoices.view'),
        ]);
    }

    public function update(Request $request, Einvoice $einvoice): RedirectResponse
    {
        $this->authorizeInvoice($request, $einvoice);
        $data = $request->validate([
            'buyer_name' => ['nullable', 'string', 'max:255'],
            'buyer_company' => ['nullable', 'string', 'max:255'],
            'buyer_tax_code' => ['nullable', 'string', 'regex:/^\d{10}(-\d{3})?$/'],
            'buyer_address' => ['nullable', 'string', 'max:500'],
            'buyer_email' => ['nullable', 'email', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:20'],
            'buyer_id_number' => ['nullable', 'string', 'max:20'],
            'buyer_bank_account' => ['nullable', 'string', 'max:50'],
            'buyer_bank_name' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['required', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['nullable', 'array', 'max:500'],
            'items.*.variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('tenant_id', tenancy()->id())],
            'items.*.code' => ['nullable', 'string', 'max:100'],
            'items.*.name' => ['required', 'string', 'max:500'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'items.*.price' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'items.*.discount' => ['nullable', 'integer', 'min:0'],
            'items.*.tax_percent' => ['required', 'integer', Rule::in(array_keys(Einvoice::TAX_RATES))],
            'items.*.is_promotion' => ['boolean'],
        ], ['buyer_tax_code.regex' => 'Mã số thuế gồm 10 số (hoặc 10 số - 3 số chi nhánh).'], [
            'buyer_tax_code' => 'mã số thuế', 'buyer_email' => 'email', 'payment_method' => 'hình thức thanh toán',
            'items.*.name' => 'tên hàng hóa', 'items.*.quantity' => 'số lượng', 'items.*.price' => 'đơn giá',
        ]);

        $lines = array_key_exists('items', $data) && $data['items'] !== null ? array_values($data['items']) : null;
        unset($data['items']);
        $data = array_map(fn (mixed $value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $data);

        $this->invoices->update($einvoice, $data, $lines);

        if ($request->boolean('issue')) {
            abort_unless($request->user()?->can('einvoices.approve'), 403);
            $this->invoices->issue($einvoice, $request->user());
            $this->toast("Đã phát hành hóa đơn {$einvoice->refresh()->invoice_no}.");

            return back();
        }

        $this->toast("Đã lưu hóa đơn {$einvoice->code}.");

        return back();
    }

    public function issue(Request $request, Einvoice $einvoice): RedirectResponse
    {
        $this->authorizeInvoice($request, $einvoice);
        $invoice = $this->invoices->issue($einvoice, $request->user());
        $this->toast("Đã phát hành hóa đơn số {$invoice->invoice_no}.");

        return back();
    }

    public function cancel(Request $request, Einvoice $einvoice): RedirectResponse
    {
        $this->authorizeInvoice($request, $einvoice);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'lý do hủy']);

        $this->invoices->cancel($einvoice, $data['reason']);
        $this->toast("Đã hủy hóa đơn {$einvoice->code}.");

        return back();
    }

    public function adjust(Request $request, Einvoice $einvoice): RedirectResponse
    {
        $this->authorizeInvoice($request, $einvoice);
        $data = $request->validate(['adjustment_type' => ['required', Rule::in(array_keys(Einvoice::ADJUSTMENT_TYPES))]], [], ['adjustment_type' => 'loại điều chỉnh']);

        $adjustment = $this->invoices->adjust($einvoice, $data['adjustment_type'], $request->user());
        $this->toast("Đã tạo hóa đơn điều chỉnh nháp {$adjustment->code}. Sửa các dòng thành phần chênh lệch rồi phát hành.");

        return to_route('e-invoices.show', $adjustment);
    }

    /**
     * "Tải hóa đơn" (PDF from the provider).
     */
    public function pdf(Request $request, Einvoice $einvoice): HttpResponse
    {
        $this->authorizeInvoice($request, $einvoice);
        abort_unless($einvoice->status === Einvoice::ISSUED && $einvoice->provider !== null, 404);

        try {
            $file = $this->drivers->for($einvoice->provider)->pdf($einvoice);
        } catch (EinvoiceException $exception) {
            throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
        }

        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']) ?: 'hoa-don.pdf';

        return response($file['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Bulk on the invoice list: issue the drafts.
     */
    public function bulk(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'action' => ['required', Rule::in(['issue'])],
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $invoices = Einvoice::query()->visibleTo($user)->whereKey($data['ids'])->whereIn('status', [Einvoice::DRAFT, Einvoice::FAILED])->orderBy('id')->get();
        $done = 0;
        $errors = [];

        foreach ($invoices as $invoice) {
            try {
                $this->invoices->issue($invoice, $user);
                $done++;
            } catch (ValidationException $exception) {
                $errors[] = "{$invoice->code}: ".$exception->validator->errors()->first();
            }
        }

        $this->toast(
            "Đã phát hành {$done} hóa đơn.".($errors === [] ? '' : ' Lỗi: '.implode('; ', array_slice($errors, 0, 5)).(count($errors) > 5 ? '…' : '')),
            $errors === [] ? 'success' : ($done === 0 ? 'error' : 'warning'),
        );

        return back();
    }

    protected function authorizeInvoice(Request $request, Einvoice $einvoice): void
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(in_array($einvoice->branch_id, $user->branchIds(), true), 404);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    protected function branches(User $user): array
    {
        return array_values(Branch::query()->whereIn('id', $user->branchIds())->orderBy('name')->get(['id', 'name'])
            ->map(fn (Branch $branch): array => ['id' => $branch->id, 'name' => $branch->name])->all());
    }
}
