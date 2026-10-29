<?php

namespace Modules\Shipping\Services;

use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Sequences\Sequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;
use Modules\Shipping\Events\CodReconciliationCompleted;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\CodReconciliation;
use Modules\Shipping\Models\CodReconciliationItem;
use Modules\Shipping\Models\Shipment;

/**
 * Đối soát COD và phí with a shipping partner.
 *
 * A reconciliation lists finished shipments (delivered or returned) of one
 * partner and branch, with the COD and fee EXSO expects next to what the
 * partner reports. The partner pays: partner COD - partner fee.
 * Completing it takes the COD as payments of the orders (so the cash book
 * gets its receipts) and the fees become an expense voucher (Cashbook
 * listens to CodReconciliationCompleted).
 *
 * @phpstan-type ItemInput array{shipment_id: int, partner_cod?: int|null, partner_fee?: int|null}
 */
class CodReconciliationService
{
    public function __construct(protected OrderService $orders) {}

    /**
     * @param  array{carrier_id: int, branch_id: int, note?: string|null, tags?: string|null, items: list<ItemInput>}  $data
     */
    public function create(array $data, User $user): CodReconciliation
    {
        $carrier = Carrier::query()->find($data['carrier_id'])
            ?? throw ValidationException::withMessages(['carrier_id' => 'Hãy chọn đối tác vận chuyển.']);

        if ($data['items'] === []) {
            throw ValidationException::withMessages(['items' => 'Thêm ít nhất một vận đơn.']);
        }

        return DB::transaction(function () use ($data, $user, $carrier): CodReconciliation {
            $ids = array_values(array_unique(array_map(fn (array $item): int => (int) $item['shipment_id'], $data['items'])));
            sort($ids);
            // Locked in id order, like stock lines.
            $shipments = Shipment::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if ($shipments->count() !== count($ids)) {
                throw ValidationException::withMessages(['items' => 'Có vận đơn không tồn tại.']);
            }

            foreach ($shipments as $shipment) {
                /** @var Shipment $shipment */
                $problem = match (true) {
                    $shipment->carrier_id !== $carrier->id => 'không thuộc đối tác '.$carrier->name,
                    $shipment->branch_id !== (int) $data['branch_id'] => 'không thuộc chi nhánh đã chọn',
                    ! $shipment->isFinished() => 'chưa giao xong hoặc chưa hoàn về',
                    $shipment->cod_reconciliation_id !== null || $shipment->reconciled_at !== null => 'đã nằm trong phiếu đối soát khác',
                    default => null,
                };

                if ($problem !== null) {
                    throw ValidationException::withMessages(['items' => "Vận đơn {$shipment->code} {$problem}."]);
                }
            }

            $reconciliation = CodReconciliation::query()->create([
                'branch_id' => (int) $data['branch_id'],
                'carrier_id' => $carrier->id,
                'code' => Sequence::next('DSCOD', CodReconciliation::class),
                'status' => CodReconciliation::RECONCILING,
                'note' => $data['note'] ?? null,
                'tags' => $data['tags'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($data['items'] as $item) {
                /** @var Shipment $shipment */
                $shipment = $shipments->get((int) $item['shipment_id']);
                $cod = $shipment->collectedCod();
                $fee = $shipment->fee;

                if (CodReconciliationItem::query()->where('cod_reconciliation_id', $reconciliation->id)->where('shipment_id', $shipment->id)->exists()) {
                    continue;
                }

                $reconciliation->items()->create([
                    'shipment_id' => $shipment->id,
                    'order_id' => $shipment->order_id,
                    'cod' => $cod,
                    'fee' => $fee,
                    // What the partner reports; without it, what EXSO expects.
                    'partner_cod' => max(0, (int) ($item['partner_cod'] ?? $cod)),
                    'partner_fee' => max(0, (int) ($item['partner_fee'] ?? $fee)),
                ]);
                $shipment->forceFill(['cod_reconciliation_id' => $reconciliation->id])->save();
            }

            $this->totals($reconciliation);

            return $reconciliation->refresh();
        });
    }

    /**
     * "Hoàn thành đối soát": the partner paid. Each order receives the COD
     * the partner collected for it (never more than it still owes).
     */
    public function complete(CodReconciliation $reconciliation, int $paymentMethodId, User $user): void
    {
        $method = PaymentMethod::query()->where('is_active', true)->find($paymentMethodId)
            ?? throw ValidationException::withMessages(['payment_method_id' => 'Hình thức thanh toán không hợp lệ hoặc đã ngừng dùng.']);

        DB::transaction(function () use ($reconciliation, $method, $user): void {
            /** @var CodReconciliation $locked */
            $locked = CodReconciliation::query()->with('items')->lockForUpdate()->findOrFail($reconciliation->id);

            if ($locked->status !== CodReconciliation::RECONCILING) {
                throw ValidationException::withMessages(['status' => 'Phiếu đối soát đã hoàn thành hoặc đã hủy.']);
            }

            foreach ($locked->items->sortBy('order_id') as $item) {
                /** @var CodReconciliationItem $item */
                $order = Order::query()->find($item->order_id);
                $paid = 0;

                if ($order !== null && $order->status !== Order::CANCELLED && $item->partner_cod > 0) {
                    $paid = min($item->partner_cod, max(0, $order->remaining()));

                    if ($paid > 0) {
                        $this->orders->pay($order, $method->id, $paid, "Đối soát COD {$locked->code}");
                    }
                }

                $item->forceFill(['paid' => $paid])->save();
            }

            Shipment::query()->where('cod_reconciliation_id', $locked->id)->update(['reconciled_at' => now()]);
            $locked->forceFill([
                'status' => CodReconciliation::DONE,
                'payment_method_id' => $method->id,
                'completed_by' => $user->id,
                'completed_at' => now(),
            ])->save();
            $reconciliation->setRawAttributes($locked->getAttributes(), true);

            DB::afterCommit(fn () => event(new CodReconciliationCompleted($locked)));
        });
    }

    /**
     * Cancel a reconciliation not yet completed; its shipments can be
     * reconciled again.
     */
    public function cancel(CodReconciliation $reconciliation): void
    {
        DB::transaction(function () use ($reconciliation): void {
            /** @var CodReconciliation $locked */
            $locked = CodReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);

            if ($locked->status !== CodReconciliation::RECONCILING) {
                throw ValidationException::withMessages(['status' => 'Chỉ hủy được phiếu đang đối soát.']);
            }

            Shipment::query()->where('cod_reconciliation_id', $locked->id)->update(['cod_reconciliation_id' => null]);
            $locked->forceFill(['status' => CodReconciliation::CANCELLED, 'cancelled_at' => now()])->save();
            $reconciliation->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Change what the partner reports for one shipment, before completing.
     */
    public function updateItem(CodReconciliation $reconciliation, int $itemId, int $partnerCod, int $partnerFee): void
    {
        DB::transaction(function () use ($reconciliation, $itemId, $partnerCod, $partnerFee): void {
            /** @var CodReconciliation $locked */
            $locked = CodReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);

            if ($locked->status !== CodReconciliation::RECONCILING) {
                throw ValidationException::withMessages(['status' => 'Phiếu đối soát đã hoàn thành hoặc đã hủy.']);
            }

            $locked->items()->whereKey($itemId)->firstOrFail()->forceFill([
                'partner_cod' => max(0, $partnerCod),
                'partner_fee' => max(0, $partnerFee),
            ])->save();
            $this->totals($locked);
        });
    }

    protected function totals(CodReconciliation $reconciliation): void
    {
        $items = CodReconciliationItem::query()->where('cod_reconciliation_id', $reconciliation->id);
        $partnerCod = (int) (clone $items)->sum('partner_cod');
        $partnerFee = (int) (clone $items)->sum('partner_fee');

        $reconciliation->forceFill([
            'shipments_count' => (clone $items)->count(),
            'cod_total' => (int) (clone $items)->sum('cod'),
            'fee_total' => (int) (clone $items)->sum('fee'),
            'partner_cod_total' => $partnerCod,
            'partner_fee_total' => $partnerFee,
            'amount' => $partnerCod - $partnerFee,
        ])->save();
    }
}
