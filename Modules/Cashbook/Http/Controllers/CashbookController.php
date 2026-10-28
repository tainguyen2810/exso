<?php

namespace Modules\Cashbook\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\DataTable\DataTable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Cashbook\Models\CashVoucher;
use Modules\Cashbook\Services\CashbookService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sổ quỹ: completed vouchers of a period with
 * tồn đầu kỳ + tổng thu − tổng chi = tồn cuối kỳ, per fund
 * (tiền mặt, ngân hàng, ví...) or payment method, in the user's branches.
 */
class CashbookController extends Controller
{
    /** Quỹ: which payment method types count in each fund. */
    public const FUNDS = [
        'cash' => ['Quỹ tiền mặt', ['cash']],
        'bank' => ['Quỹ tiền gửi (ngân hàng)', ['bank']],
        'other' => ['Ví điện tử, COD, điểm', ['ewallet', 'cod', 'points']],
    ];

    public function __construct(protected CashbookService $cashbook) {}

    public function index(Request $request): Response|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $timezone = (string) config('app.timezone');

        // The period defaults to this month, like Sapo's "Ngày ghi nhận".
        $filter = $request->query('filter');
        $filter = is_array($filter) ? $filter : [];
        $period = is_array($filter['recorded_at'] ?? null) ? $filter['recorded_at'] : [];

        if (($period['from'] ?? '') === '' && ($period['to'] ?? '') === '') {
            $today = CarbonImmutable::now($timezone);
            $filter['recorded_at'] = ['from' => $today->startOfMonth()->format('d/m/Y'), 'to' => $today->format('d/m/Y')];
            $request->query->set('filter', $filter);
        }

        $from = (DataTable::parseDate($filter['recorded_at']['from'] ?? null, $timezone) ?? CarbonImmutable::create(2000, 1, 1, 0, 0, 0, $timezone))->startOfDay()->toImmutable();
        $to = (DataTable::parseDate($filter['recorded_at']['to'] ?? null, $timezone) ?? CarbonImmutable::now($timezone))->endOfDay()->toImmutable();

        // Fund, branch, method and creator apply to the totals as well as the list.
        $scope = function (Builder $query) use ($user, $filter): void {
            $query->whereIn('branch_id', $user->branchIds());

            if (is_numeric($filter['branch_id'] ?? null)) {
                $query->where('branch_id', (int) $filter['branch_id']);
            }

            if (is_numeric($filter['payment_method_id'] ?? null)) {
                $query->where('payment_method_id', (int) $filter['payment_method_id']);
            }

            if (is_string($filter['fund'] ?? null) && isset(self::FUNDS[$filter['fund']])) {
                $query->whereIn('method_type', self::FUNDS[$filter['fund']][1]);
            }

            if (is_numeric($filter['created_by'] ?? null)) {
                $query->where('created_by', (int) $filter['created_by']);
            }
        };

        $query = CashVoucher::query()
            ->with(['type:id,name', 'branch:id,name', 'creator:id,name'])
            ->where('status', CashVoucher::COMPLETED);
        $scope($query);

        $none = fn (): null => null;
        $table = DataTable::for($query, $request, 'cashbook')
            ->search(['code', 'document_code', 'reference', 'party_name', 'tags'])
            ->filterDateRange('recorded_at')
            ->filterExact('kind')
            ->filter('branch_id', $none)
            ->filter('payment_method_id', $none)
            ->filter('fund', $none)
            ->filter('created_by', $none)
            ->sortable(['code', 'amount', 'recorded_at'], '-recorded_at');

        if ($request->query('export') === 'xlsx') {
            abort_unless($user->can('cashbook.export'), 403);

            return $table->export('so-quy-'.now()->format('Ymd-His'), [
                'Mã phiếu' => fn (CashVoucher $voucher) => $voucher->code,
                'Ngày ghi nhận' => fn (CashVoucher $voucher) => $voucher->recorded_at->setTimezone($timezone),
                'Loại phiếu' => fn (CashVoucher $voucher) => $voucher->type->name,
                'Tiền thu' => fn (CashVoucher $voucher) => $voucher->kind === CashVoucher::RECEIPT ? $voucher->amount : null,
                'Tiền chi' => fn (CashVoucher $voucher) => $voucher->kind === CashVoucher::PAYMENT ? $voucher->amount : null,
                'Hình thức thanh toán' => fn (CashVoucher $voucher) => $voucher->method_name,
                'Đối tượng' => fn (CashVoucher $voucher) => $voucher->party_name,
                'Chứng từ gốc' => fn (CashVoucher $voucher) => $voucher->document_code,
                'Chi nhánh' => fn (CashVoucher $voucher) => $voucher->branch->name,
                'Nhân viên tạo' => fn (CashVoucher $voucher) => $voucher->creator?->name,
            ]);
        }

        return Inertia::render('cashbook/index', [
            'vouchers' => $table->paginate(fn (CashVoucher $voucher): array => [
                'id' => $voucher->id,
                'code' => $voucher->code,
                'kind' => $voucher->kind,
                'type' => $voucher->type->name,
                'amount' => $voucher->amount,
                'method_name' => $voucher->method_name,
                'party_name' => $voucher->party_name,
                'document_code' => $voucher->document_code,
                'branch' => $voucher->branch->name,
                'creator' => $voucher->creator?->name,
                'recorded_at' => $voucher->recorded_at->toIso8601String(),
            ]),
            'summary' => $this->cashbook->summary($scope, $from, $to),
            'period' => ['from' => $from->format('d/m/Y'), 'to' => $to->format('d/m/Y')],
            'funds' => array_map(fn (array $fund): string => $fund[0], self::FUNDS),
            'branches' => Branch::query()->whereKey($user->branchIds())->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => PaymentMethod::query()->orderBy('sort')->get(['id', 'name']),
            'staff' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
