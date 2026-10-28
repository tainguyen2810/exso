<?php

namespace Modules\Cashbook\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Branches\CurrentBranch;
use App\Support\DataTable\DataTable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Cashbook\Models\CashVoucher;
use Modules\Cashbook\Models\CashVoucherType;
use Modules\Cashbook\Services\CashbookService;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderReturn;
use Modules\Purchase\Models\PurchaseReceipt;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phiếu thu / phiếu chi: lists, entering by hand, viewing, cancelling.
 * Every voucher is limited to the user's branches.
 */
class VoucherController extends Controller
{
    public function __construct(protected CashbookService $cashbook) {}

    public function receipts(Request $request): Response|StreamedResponse
    {
        return $this->index($request, CashVoucher::RECEIPT);
    }

    public function payments(Request $request): Response|StreamedResponse
    {
        return $this->index($request, CashVoucher::PAYMENT);
    }

    public function createReceipt(Request $request): Response
    {
        return $this->form($request, CashVoucher::RECEIPT, null);
    }

    public function createPayment(Request $request): Response
    {
        return $this->form($request, CashVoucher::PAYMENT, null);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $kind = $request->string('kind')->toString();
        abort_unless(in_array($kind, [CashVoucher::RECEIPT, CashVoucher::PAYMENT], true), 422);

        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::in($user->branchIds())],
            'type_id' => ['required', 'integer'],
            'party_type' => ['required', Rule::in(array_keys(CashVoucher::PARTIES))],
            'party_id' => ['nullable', 'integer'],
            'party_name' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'payment_method_id' => ['required', 'integer'],
            'reference' => ['nullable', 'string', 'max:100'],
            'changes_debt' => ['boolean'],
            'affects_profit' => ['boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
            'tags' => ['nullable', 'string', 'max:255'],
            'recorded_at' => ['nullable', 'string'],
        ], [], [
            'branch_id' => 'chi nhánh',
            'type_id' => 'loại phiếu',
            'amount' => 'giá trị',
            'payment_method_id' => 'hình thức thanh toán',
        ]);

        $voucher = $this->cashbook->create([
            ...$data,
            'kind' => $kind,
            'recorded_at' => $this->recordedAt($data['recorded_at'] ?? null),
        ], $user);

        $this->toast('Đã tạo '.CashVoucher::label($kind).' '.$voucher->code.'.');

        return to_route('cashbook.vouchers.show', $voucher);
    }

    public function show(Request $request, CashVoucher $voucher): Response
    {
        $this->authorizeVoucher($request, $voucher);

        return $this->form($request, $voucher->kind, $voucher);
    }

    public function update(Request $request, CashVoucher $voucher): RedirectResponse
    {
        $this->authorizeVoucher($request, $voucher);

        $data = $request->validate([
            'type_id' => ['required', 'integer'],
            'reference' => ['nullable', 'string', 'max:100'],
            'affects_profit' => ['boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
            'tags' => ['nullable', 'string', 'max:255'],
            'recorded_at' => ['nullable', 'string'],
        ]);

        $this->cashbook->update($voucher, [...$data, 'recorded_at' => $this->recordedAt($data['recorded_at'] ?? null, $voucher)]);

        $this->toast('Đã lưu '.CashVoucher::label($voucher->kind).' '.$voucher->code.'.');

        return back();
    }

    public function cancel(Request $request, CashVoucher $voucher): RedirectResponse
    {
        $this->authorizeVoucher($request, $voucher);
        /** @var User $user */
        $user = $request->user();
        $this->cashbook->cancel($voucher, $user);

        $this->toast('Đã hủy '.CashVoucher::label($voucher->kind).' '.$voucher->code.'.');

        return back();
    }

    public function print(Request $request, CashVoucher $voucher): Response
    {
        $this->authorizeVoucher($request, $voucher);
        $voucher->load(['type', 'branch', 'creator:id,name']);

        return Inertia::render('cash-vouchers/print', [
            'voucher' => $this->detail($voucher),
            'storeInfo' => [
                'name' => (string) settings('store.name'),
                'phone' => settings('store.phone'),
                'address' => implode(', ', array_filter([settings('store.address'), settings('store.ward'), settings('store.district'), settings('store.province')])),
            ],
        ]);
    }

    protected function index(Request $request, string $kind): Response|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = CashVoucher::query()
            ->with(['type:id,name', 'branch:id,name', 'creator:id,name'])
            ->visibleTo($user)
            ->where('kind', $kind);

        $table = DataTable::for($query, $request, $kind === CashVoucher::RECEIPT ? 'cash-receipts' : 'cash-payments')
            ->search(['code', 'document_code', 'reference', 'party_name'])
            ->filterExact('status')
            ->filterExact('type_id')
            ->filterExact('party_type')
            ->filterExact('branch_id')
            ->filterExact('payment_method_id')
            ->filterExact('created_by')
            ->filterDateRange('recorded_at')
            ->filterDateRange('created_at')
            ->sortable(['code', 'amount', 'recorded_at', 'created_at'], '-recorded_at');

        $label = $kind === CashVoucher::RECEIPT ? 'thu' : 'chi';

        if ($request->query('export') === 'xlsx') {
            abort_unless($user->can('cashbook.export'), 403);

            return $table->export("phieu-{$label}-".now()->format('Ymd-His'), [
                'Mã phiếu' => fn (CashVoucher $voucher) => $voucher->code,
                'Ngày ghi nhận' => fn (CashVoucher $voucher) => $voucher->recorded_at->setTimezone((string) config('app.timezone')),
                'Loại phiếu' => fn (CashVoucher $voucher) => $voucher->type->name,
                'Trạng thái' => fn (CashVoucher $voucher) => CashVoucher::STATUSES[$voucher->status] ?? $voucher->status,
                'Số tiền' => fn (CashVoucher $voucher) => $voucher->amount,
                'Hình thức thanh toán' => fn (CashVoucher $voucher) => $voucher->method_name,
                'Nhóm đối tượng' => fn (CashVoucher $voucher) => CashVoucher::PARTIES[$voucher->party_type] ?? $voucher->party_type,
                'Tên đối tượng' => fn (CashVoucher $voucher) => $voucher->party_name,
                'Chứng từ gốc' => fn (CashVoucher $voucher) => $voucher->document_code,
                'Tham chiếu' => fn (CashVoucher $voucher) => $voucher->reference,
                'Chi nhánh' => fn (CashVoucher $voucher) => $voucher->branch->name,
                'Nhân viên tạo' => fn (CashVoucher $voucher) => $voucher->creator?->name,
                'Mô tả' => fn (CashVoucher $voucher) => $voucher->description,
            ]);
        }

        return Inertia::render('cash-vouchers/index', [
            'kind' => $kind,
            'vouchers' => $table->paginate(fn (CashVoucher $voucher): array => [
                'id' => $voucher->id,
                'code' => $voucher->code,
                'type' => $voucher->type->name,
                'status' => $voucher->status,
                'is_auto' => $voucher->is_auto,
                'amount' => $voucher->amount,
                'party_type' => $voucher->party_type,
                'party_name' => $voucher->party_name,
                'party_url' => $this->partyUrl($voucher),
                'method_name' => $voucher->method_name,
                'document_code' => $voucher->document_code,
                'document_url' => $this->documentUrl($voucher),
                'reference' => $voucher->reference,
                'branch' => $voucher->branch->name,
                'creator' => $voucher->creator?->name,
                'recorded_at' => $voucher->recorded_at->toIso8601String(),
                'created_at' => $voucher->created_at?->toIso8601String(),
            ]),
            'types' => CashVoucherType::query()->where('kind', $kind)->orderBy('id')->get(['id', 'name']),
            'branches' => Branch::query()->whereKey($user->branchIds())->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => PaymentMethod::query()->orderBy('sort')->get(['id', 'name']),
            'staff' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    protected function form(Request $request, string $kind, ?CashVoucher $voucher): Response
    {
        /** @var User $user */
        $user = $request->user();
        $voucher?->load(['type', 'branch', 'creator:id,name', 'canceller:id,name']);

        return Inertia::render('cash-vouchers/form', [
            'kind' => $kind,
            'voucher' => $voucher === null ? null : $this->detail($voucher),
            'types' => CashVoucherType::query()->where('kind', $kind)
                ->where(fn (Builder $query) => $query->where('code', '!=', CashVoucherType::AUTO)->when($voucher !== null, fn (Builder $query) => $query->orWhereKey($voucher->type_id)))
                ->orderBy('id')
                ->get(['id', 'name', 'code', 'affects_profit']),
            'branches' => Branch::query()->whereKey($user->branchIds())->orderBy('name')->get(['id', 'name']),
            'defaultBranch' => app(CurrentBranch::class)->id($user),
            'paymentMethods' => PaymentMethod::query()->where('is_active', true)->orderBy('sort')->get(['id', 'name', 'type']),
            'staff' => User::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'canChangeDate' => (bool) settings('cashbook.allow_change_date'),
            'parties' => CashVoucher::PARTIES,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function detail(CashVoucher $voucher): array
    {
        return [
            'id' => $voucher->id,
            'kind' => $voucher->kind,
            'code' => $voucher->code,
            'type_id' => $voucher->type_id,
            'type' => $voucher->type->name,
            'status' => $voucher->status,
            'is_auto' => $voucher->is_auto,
            'party_type' => $voucher->party_type,
            'party_id' => $voucher->party_id,
            'party_name' => $voucher->party_name,
            'party_url' => $this->partyUrl($voucher),
            'amount' => $voucher->amount,
            'method_name' => $voucher->method_name,
            'reference' => $voucher->reference,
            'document_code' => $voucher->document_code,
            'document_url' => $this->documentUrl($voucher),
            'changes_debt' => $voucher->changes_debt,
            'affects_profit' => $voucher->affects_profit,
            'description' => $voucher->description,
            'tags' => $voucher->tags,
            'branch' => $voucher->branch->name,
            'creator' => $voucher->creator?->name,
            'canceller' => $voucher->relationLoaded('canceller') ? $voucher->canceller?->name : null,
            'recorded_at' => $voucher->recorded_at->toIso8601String(),
            'created_at' => $voucher->created_at?->toIso8601String(),
            'updated_at' => $voucher->updated_at?->toIso8601String(),
            'cancelled_at' => $voucher->cancelled_at?->toIso8601String(),
        ];
    }

    protected function authorizeVoucher(Request $request, CashVoucher $voucher): void
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(in_array($voucher->branch_id, $user->branchIds(), true), 404);
    }

    /**
     * Ngày ghi nhận typed by the user, only when the store allows it
     * (Cấu hình bán hàng → Quản lý khác); never in the future.
     */
    protected function recordedAt(?string $value, ?CashVoucher $voucher = null): ?CarbonImmutable
    {
        if ($value === null || $value === '' || ! settings('cashbook.allow_change_date')) {
            return null;
        }

        $timezone = (string) config('app.timezone');
        $date = DataTable::parseDate($value, $timezone);

        if ($date === null) {
            throw ValidationException::withMessages(['recorded_at' => 'Ngày ghi nhận không hợp lệ (dd/mm/yyyy).']);
        }

        if ($date->isAfter(now($timezone)->endOfDay())) {
            throw ValidationException::withMessages(['recorded_at' => 'Ngày ghi nhận không được sau hôm nay.']);
        }

        // Keep the time of day of the voucher (or now) on the chosen date.
        $time = ($voucher?->recorded_at ?? CarbonImmutable::now())->setTimezone($timezone);

        return CarbonImmutable::parse($date->format('Y-m-d').' '.$time->format('H:i:s'), $timezone);
    }

    protected function partyUrl(CashVoucher $voucher): ?string
    {
        return match ($voucher->party_type) {
            'customer' => $voucher->party_id !== null ? "/customers/{$voucher->party_id}" : null,
            'supplier' => $voucher->party_id !== null ? "/suppliers/{$voucher->party_id}" : null,
            default => null,
        };
    }

    protected function documentUrl(CashVoucher $voucher): ?string
    {
        return match ($voucher->document_type) {
            (new Order)->getMorphClass() => "/orders/{$voucher->document_id}",
            (new OrderReturn)->getMorphClass() => "/returns/{$voucher->document_id}",
            (new PurchaseReceipt)->getMorphClass() => "/purchase-receipts/{$voucher->document_id}",
            default => null,
        };
    }
}
