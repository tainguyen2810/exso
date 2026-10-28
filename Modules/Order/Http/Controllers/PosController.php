<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Branches\CurrentBranch;
use App\Support\Settings\Banks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Order\Http\Concerns\HandlesOrderInput;
use Modules\Order\Models\Order;
use Modules\Order\Models\PosShift;
use Modules\Order\Services\PosService;
use Modules\Order\Support\VietQr;

/**
 * "Bán tại quầy": the full screen counter, cashier shifts and sales.
 */
class PosController extends Controller
{
    use HandlesOrderInput;

    public function __construct(protected PosService $pos) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $branchId = $this->branchId($request);
        $shift = $branchId === null ? null : $this->pos->currentShift($user, $branchId);

        return Inertia::render('pos/index', [
            ...$this->orderOptions($request),
            'branchId' => $branchId,
            'shift' => $shift === null ? null : [
                'id' => $shift->id,
                'opened_at' => $shift->opened_at->toIso8601String(),
                'opening_cash' => $shift->opening_cash,
                ...$this->pos->summary($shift),
            ],
            'printDraft' => (bool) settings('pos.print_draft'),
            'requirePaidAmount' => (bool) settings('pos.require_paid_amount'),
            'storeInfo' => app(OrderController::class)->storeInfo(),
        ]);
    }

    public function openShift(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::in($user->branchIds())],
            'opening_cash' => ['required', 'integer', 'min:0', 'max:999999999999'],
        ], [], ['opening_cash' => 'tiền đầu ca']);

        $this->pos->openShift($user, (int) $data['branch_id'], (int) $data['opening_cash']);
        $this->toast('Đã mở ca làm việc.');

        return back();
    }

    public function closeShift(Request $request, PosShift $shift): RedirectResponse
    {
        abort_unless($shift->user_id === $request->user()?->id, 403, 'Đây không phải ca của bạn.');
        $data = $request->validate([
            'counted_cash' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], ['counted_cash' => 'tiền mặt thực tế']);

        $closed = $this->pos->closeShift($shift, (int) $data['counted_cash'], $data['note'] ?? null);
        $difference = number_format($closed->difference, 0, ',', '.');
        $this->toast($closed->difference === 0 ? 'Đã chốt ca, tiền mặt khớp.' : "Đã chốt ca, chênh lệch {$difference}.", $closed->difference === 0 ? 'success' : 'warning');

        return back();
    }

    /**
     * Sell: answers JSON so the counter stays on screen and prints the bill.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $this->orderInput($request, true);
        $branchId = $data['branch_id'] !== 0 ? $data['branch_id'] : $this->branchId($request);
        $shift = $branchId === null ? null : $this->pos->currentShift($user, $branchId);

        if ($shift === null) {
            return response()->json(['message' => 'Hãy mở ca làm việc trước khi bán hàng.', 'errors' => ['shift' => ['Hãy mở ca làm việc trước khi bán hàng.']]], 422);
        }

        $order = $this->pos->sell($data, $user, $shift);
        $order->load(['items', 'branch', 'assignee:id,name', 'customer']);

        return response()->json([
            'order' => ['id' => $order->id, 'code' => $order->code, 'total' => $order->total, 'paid' => $order->paid],
            'print' => app(OrderController::class)->printable($order),
            'shift' => $this->pos->summary($shift),
        ]);
    }

    /**
     * VietQR of the amount on screen for a bank payment method.
     */
    public function qr(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_method_id' => ['required', 'integer'],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
        ]);

        $method = PaymentMethod::query()->where('type', 'bank')->where('is_active', true)->find((int) $data['payment_method_id']);

        if ($method === null || $method->bank_bin === null || $method->account_number === null) {
            return response()->json(null);
        }

        $store = mb_substr(Str::ascii((string) settings('store.name')), 0, 20);

        return response()->json([
            'svg' => VietQr::svg(VietQr::payload($method->bank_bin, $method->account_number, (int) $data['amount'], trim('TT '.$store))),
            'bank' => Banks::LIST[$method->bank_bin] ?? null,
            'account_number' => $method->account_number,
            'account_name' => $method->account_name,
            'amount' => (int) $data['amount'],
        ]);
    }

    /**
     * The branch the counter sells from: the user's current branch.
     */
    protected function branchId(Request $request): ?int
    {
        /** @var User $user */
        $user = $request->user();
        $id = $request->integer('branch_id') ?: app(CurrentBranch::class)->id($user);

        return $id !== null && in_array($id, $user->branchIds(), true) ? $id : ($user->branchIds()[0] ?? null);
    }

    /**
     * Recent sales of the open shift, to reprint a bill.
     */
    public function recent(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $branchId = $this->branchId($request);
        $shift = $branchId === null ? null : $this->pos->currentShift($user, $branchId);

        if ($shift === null) {
            return response()->json([]);
        }

        return response()->json(Order::query()->where('pos_shift_id', $shift->id)->latest('id')->limit(20)->get()
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'code' => $order->code,
                'customer' => $order->customer_name ?? 'Khách lẻ',
                'total' => $order->total,
                'status' => $order->status,
                'sale_at' => $order->sale_at?->toIso8601String(),
            ]));
    }
}
