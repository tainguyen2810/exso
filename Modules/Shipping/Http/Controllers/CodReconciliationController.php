<?php

namespace Modules\Shipping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Branches\CurrentBranch;
use App\Support\DataTable\DataTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\CodReconciliation;
use Modules\Shipping\Models\CodReconciliationItem;
use Modules\Shipping\Services\CodReconciliationService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Đối soát COD và phí": list, create, view (with the shipments whose
 * figures differ), complete or cancel. Limited to the user's branches.
 */
class CodReconciliationController extends Controller
{
    public function __construct(protected CodReconciliationService $reconciliations) {}

    public function index(Request $request): Response|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        $table = DataTable::for(CodReconciliation::query()->visibleTo($user)->with(['carrier:id,name', 'branch:id,name', 'creator:id,name']), $request, 'cod-reconciliations')
            ->search(['code', 'note'])
            ->filterExact('status')
            ->filterExact('carrier_id')
            ->filterExact('branch_id')
            ->filterDateRange('created_at')
            ->sortable(['code', 'created_at', 'amount'], '-id');

        if ($request->query('export') === 'xlsx') {
            abort_unless($user->can('cod_reconciliations.export'), 403);

            return $table->export('doi-soat-cod-'.now()->format('Ymd-His'), [
                'Mã phiếu đối soát' => fn (CodReconciliation $row) => $row->code,
                'Ngày tạo phiếu' => fn (CodReconciliation $row) => $row->created_at?->setTimezone((string) config('app.timezone')),
                'Đối tác vận chuyển' => fn (CodReconciliation $row) => $row->carrier->name,
                'Chi nhánh' => fn (CodReconciliation $row) => $row->branch->name,
                'Trạng thái' => fn (CodReconciliation $row) => CodReconciliation::STATUSES[$row->status],
                'Số vận đơn' => fn (CodReconciliation $row) => $row->shipments_count,
                'COD hệ thống' => fn (CodReconciliation $row) => $row->cod_total,
                'Phí GH hệ thống' => fn (CodReconciliation $row) => $row->fee_total,
                'COD đối tác' => fn (CodReconciliation $row) => $row->partner_cod_total,
                'Phí GH đối tác' => fn (CodReconciliation $row) => $row->partner_fee_total,
                'Đối soát hệ thống' => fn (CodReconciliation $row) => $row->systemAmount(),
                'Đối soát đối tác' => fn (CodReconciliation $row) => $row->amount,
            ]);
        }

        return Inertia::render('cod-reconciliations/index', [
            'reconciliations' => $table->paginate(fn (CodReconciliation $row): array => [
                'id' => $row->id,
                'code' => $row->code,
                'status' => $row->status,
                'carrier' => $row->carrier->name,
                'branch' => $row->branch->name,
                'shipments_count' => $row->shipments_count,
                'system_amount' => $row->systemAmount(),
                'amount' => $row->amount,
                'creator' => $row->creator?->name,
                'created_at' => $row->created_at?->toIso8601String(),
                'completed_at' => $row->completed_at?->toIso8601String(),
            ]),
            'carriers' => Carrier::query()->withTrashed()->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::query()->whereIn('id', $user->branchIds())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('cod-reconciliations/create', [
            'carriers' => Carrier::query()->where('is_active', true)->orderByDesc('kind')->orderBy('name')->get(['id', 'name', 'kind']),
            'branches' => Branch::query()->whereIn('id', $user->branchIds())->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'currentBranchId' => app(CurrentBranch::class)->id($user),
            'presetCarrierId' => $request->integer('carrier_id') ?: null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'carrier_id' => ['required', 'integer'],
            'branch_id' => ['required', 'integer', Rule::in($user->branchIds())],
            'note' => ['nullable', 'string', 'max:1000'],
            'tags' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.shipment_id' => ['required', 'integer'],
            'items.*.partner_cod' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'items.*.partner_fee' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ], [], ['carrier_id' => 'đối tác vận chuyển', 'branch_id' => 'chi nhánh', 'items' => 'vận đơn']);

        $reconciliation = $this->reconciliations->create([
            'carrier_id' => (int) $data['carrier_id'],
            'branch_id' => (int) $data['branch_id'],
            'note' => $data['note'] ?? null,
            'tags' => $data['tags'] ?? null,
            'items' => array_values(array_map(fn (array $item): array => [
                'shipment_id' => (int) $item['shipment_id'],
                'partner_cod' => isset($item['partner_cod']) ? (int) $item['partner_cod'] : null,
                'partner_fee' => isset($item['partner_fee']) ? (int) $item['partner_fee'] : null,
            ], $data['items'])),
        ], $user);

        $this->toast("Đã tạo phiếu đối soát {$reconciliation->code}.");

        return to_route('cod-reconciliations.show', $reconciliation);
    }

    public function show(Request $request, CodReconciliation $reconciliation): Response
    {
        $this->authorizeReconciliation($request, $reconciliation);
        $reconciliation->load(['carrier', 'branch:id,name', 'creator:id,name', 'completer:id,name', 'paymentMethod:id,name', 'items.shipment:id,code,tracking_code,status', 'items.order:id,code']);

        return Inertia::render('cod-reconciliations/show', [
            'reconciliation' => [
                'id' => $reconciliation->id,
                'code' => $reconciliation->code,
                'status' => $reconciliation->status,
                'carrier' => $reconciliation->carrier->only('id', 'name'),
                'branch' => $reconciliation->branch->name,
                'creator' => $reconciliation->creator?->name,
                'completer' => $reconciliation->completer?->name,
                'payment_method' => $reconciliation->paymentMethod?->name,
                'note' => $reconciliation->note,
                'tags' => $reconciliation->tags,
                'created_at' => $reconciliation->created_at?->toIso8601String(),
                'completed_at' => $reconciliation->completed_at?->toIso8601String(),
                'cod_total' => $reconciliation->cod_total,
                'fee_total' => $reconciliation->fee_total,
                'partner_cod_total' => $reconciliation->partner_cod_total,
                'partner_fee_total' => $reconciliation->partner_fee_total,
                'system_amount' => $reconciliation->systemAmount(),
                'amount' => $reconciliation->amount,
                'items' => $reconciliation->items->map(fn (CodReconciliationItem $item): array => [
                    'id' => $item->id,
                    'shipment_id' => $item->shipment_id,
                    'shipment_code' => $item->shipment->code,
                    'tracking_code' => $item->shipment->tracking_code,
                    'shipment_status' => $item->shipment->status,
                    'order_id' => $item->order_id,
                    'order_code' => $item->order->code,
                    'cod' => $item->cod,
                    'fee' => $item->fee,
                    'partner_cod' => $item->partner_cod,
                    'partner_fee' => $item->partner_fee,
                    'paid' => $item->paid,
                    'differs' => $item->differs(),
                ])->all(),
            ],
            'paymentMethods' => PaymentMethod::query()->where('is_active', true)->where('type', '!=', 'points')->orderBy('sort')->get(['id', 'name', 'type']),
        ]);
    }

    /**
     * "Thanh toán công nợ": the partner paid, complete the reconciliation.
     */
    public function complete(Request $request, CodReconciliation $reconciliation): RedirectResponse
    {
        $this->authorizeReconciliation($request, $reconciliation);
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['payment_method_id' => ['required', 'integer']], [], ['payment_method_id' => 'hình thức thanh toán']);

        $this->reconciliations->complete($reconciliation, (int) $data['payment_method_id'], $user);
        $this->toast("Đã hoàn thành đối soát {$reconciliation->code}.");

        return back();
    }

    public function cancel(Request $request, CodReconciliation $reconciliation): RedirectResponse
    {
        $this->authorizeReconciliation($request, $reconciliation);

        $this->reconciliations->cancel($reconciliation);
        $this->toast("Đã hủy phiếu đối soát {$reconciliation->code}.");

        return back();
    }

    /**
     * Figures reported by the partner for one shipment.
     */
    public function updateItem(Request $request, CodReconciliation $reconciliation, int $item): RedirectResponse
    {
        $this->authorizeReconciliation($request, $reconciliation);
        $data = $request->validate([
            'partner_cod' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'partner_fee' => ['required', 'integer', 'min:0', 'max:999999999999'],
        ]);

        $this->reconciliations->updateItem($reconciliation, $item, (int) $data['partner_cod'], (int) $data['partner_fee']);

        return back();
    }

    protected function authorizeReconciliation(Request $request, CodReconciliation $reconciliation): void
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($reconciliation->isVisibleTo($user), 403, 'Bạn không xem được phiếu đối soát này.');
    }
}
