<?php

namespace Modules\Inventory\Services;

use App\Support\Sequences\Sequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Models\CostAdjustment;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Stocktake;
use Modules\Inventory\Models\StockTransfer;
use Modules\Product\Models\ProductVariant;

/**
 * Stocktakes, transfers between branches and cost adjustments. Each one is
 * a draft until confirmed; confirming changes stock or cost only through
 * InventoryService, in one transaction.
 */
class StockDocumentService
{
    public function __construct(protected InventoryService $inventory) {}

    /**
     * @param  array{branch_id: int, note?: string|null, items: list<array{variant_id: int, actual_quantity: int, reason?: string|null}>}  $data
     */
    public function saveStocktake(?Stocktake $stocktake, array $data): Stocktake
    {
        $this->assertDraft($stocktake?->status, Stocktake::DRAFT, 'Phiếu kiểm đã cân bằng hoặc đã hủy, không sửa được.');
        $this->checkVariants(array_column($data['items'], 'variant_id'), true);

        return DB::transaction(function () use ($stocktake, $data): Stocktake {
            $stocktake ??= new Stocktake(['code' => Sequence::next('KH', Stocktake::class), 'created_by' => auth()->id()]);
            $stocktake->fill(['branch_id' => $data['branch_id'], 'note' => $data['note'] ?? null])->save();
            $stocktake->items()->delete();

            foreach ($data['items'] as $item) {
                $stocktake->items()->create([
                    'variant_id' => $item['variant_id'],
                    'actual_quantity' => max(0, $item['actual_quantity']),
                    'reason' => $item['reason'] ?? null,
                ]);
            }

            return $stocktake;
        });
    }

    /**
     * Set stock to the counted numbers and keep what the system had.
     */
    public function balance(Stocktake $stocktake): void
    {
        DB::transaction(function () use ($stocktake): void {
            /** @var Stocktake $locked */
            $locked = Stocktake::query()->with('items.variant.product')->lockForUpdate()->findOrFail($stocktake->id);
            $this->assertDraft($locked->status, Stocktake::DRAFT, 'Phiếu kiểm đã cân bằng hoặc đã hủy.');

            foreach ($locked->items->sortBy('variant_id') as $item) {
                $result = $this->inventory->setOnHand($locked->branch_id, $item->variant, $item->actual_quantity, StockMovement::STOCKTAKE, [
                    'reference' => $locked,
                    'reference_code' => $locked->code,
                    'note' => $item->reason,
                ]);

                $item->update(['system_quantity' => $result['system'], 'difference' => $result['difference']]);
            }

            $locked->update(['status' => Stocktake::BALANCED, 'balanced_at' => now(), 'balanced_by' => auth()->id()]);
            $stocktake->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function cancelStocktake(Stocktake $stocktake): void
    {
        $this->assertDraft($stocktake->status, Stocktake::DRAFT, 'Chỉ hủy được phiếu đang kiểm.');
        $stocktake->update(['status' => Stocktake::CANCELLED]);
    }

    /**
     * @param  array{from_branch_id: int, to_branch_id: int, note?: string|null, items: list<array{variant_id: int, quantity: int}>}  $data
     */
    public function saveTransfer(?StockTransfer $transfer, array $data): StockTransfer
    {
        $this->assertDraft($transfer?->status, StockTransfer::DRAFT, 'Phiếu đã chuyển đi, không sửa được.');

        if ($data['from_branch_id'] === $data['to_branch_id']) {
            throw ValidationException::withMessages(['to_branch_id' => 'Chi nhánh nhận phải khác chi nhánh chuyển.']);
        }

        $this->checkVariants(array_column($data['items'], 'variant_id'), false);

        return DB::transaction(function () use ($transfer, $data): StockTransfer {
            $transfer ??= new StockTransfer(['code' => Sequence::next('CH', StockTransfer::class), 'created_by' => auth()->id()]);
            $transfer->fill([
                'from_branch_id' => $data['from_branch_id'],
                'to_branch_id' => $data['to_branch_id'],
                'note' => $data['note'] ?? null,
            ])->save();
            $transfer->items()->delete();

            foreach ($data['items'] as $item) {
                $transfer->items()->create(['variant_id' => $item['variant_id'], 'quantity' => $item['quantity']]);
            }

            return $transfer;
        });
    }

    /**
     * Take the goods out of the sending branch.
     */
    public function ship(StockTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer): void {
            /** @var StockTransfer $locked */
            $locked = StockTransfer::query()->with('items.variant.product')->lockForUpdate()->findOrFail($transfer->id);
            $this->assertDraft($locked->status, StockTransfer::DRAFT, 'Phiếu đã chuyển đi hoặc đã hủy.');

            foreach ($locked->items->sortBy('variant_id') as $item) {
                $movements = $this->inventory->move($locked->from_branch_id, $item->variant, -$item->quantity, StockMovement::TRANSFER_OUT, [
                    'reference' => $locked,
                    'reference_code' => $locked->code,
                ]);

                $item->update(['unit_cost' => ($movements[0]->cost_after ?? 0) * max(1, $item->variant->unit_ratio)]);
            }

            $locked->update(['status' => StockTransfer::SHIPPING, 'shipped_at' => now(), 'shipped_by' => auth()->id()]);
            $transfer->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Put the shipped goods into the receiving branch.
     */
    public function receiveTransfer(StockTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer): void {
            /** @var StockTransfer $locked */
            $locked = StockTransfer::query()->with('items.variant.product')->lockForUpdate()->findOrFail($transfer->id);
            $this->assertDraft($locked->status, StockTransfer::SHIPPING, 'Phiếu chưa chuyển đi hoặc đã nhận.');

            foreach ($locked->items->sortBy('variant_id') as $item) {
                $this->inventory->move($locked->to_branch_id, $item->variant, $item->quantity, StockMovement::TRANSFER_IN, [
                    'reference' => $locked,
                    'reference_code' => $locked->code,
                ]);

                $item->update(['received_quantity' => $item->quantity]);
            }

            $locked->update(['status' => StockTransfer::RECEIVED, 'received_at' => now(), 'received_by' => auth()->id()]);
            $transfer->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Cancel a transfer; goods already shipped go back to the sending branch.
     */
    public function cancelTransfer(StockTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer): void {
            /** @var StockTransfer $locked */
            $locked = StockTransfer::query()->with('items.variant.product')->lockForUpdate()->findOrFail($transfer->id);

            if (! in_array($locked->status, [StockTransfer::DRAFT, StockTransfer::SHIPPING], true)) {
                throw ValidationException::withMessages(['status' => 'Phiếu đã nhận hoặc đã hủy.']);
            }

            if ($locked->status === StockTransfer::SHIPPING) {
                foreach ($locked->items->sortBy('variant_id') as $item) {
                    $this->inventory->move($locked->from_branch_id, $item->variant, $item->quantity, StockMovement::TRANSFER_IN, [
                        'reference' => $locked,
                        'reference_code' => $locked->code,
                        'note' => 'Hủy chuyển hàng',
                    ]);
                }
            }

            $locked->update(['status' => StockTransfer::CANCELLED]);
            $transfer->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * @param  array{branch_id: int, note?: string|null, items: list<array{variant_id: int, new_cost: int}>}  $data
     */
    public function saveCostAdjustment(?CostAdjustment $adjustment, array $data): CostAdjustment
    {
        $this->assertDraft($adjustment?->status, CostAdjustment::DRAFT, 'Phiếu đã điều chỉnh hoặc đã hủy, không sửa được.');
        $this->checkVariants(array_column($data['items'], 'variant_id'), true);

        return DB::transaction(function () use ($adjustment, $data): CostAdjustment {
            $adjustment ??= new CostAdjustment(['code' => Sequence::next('DCGV', CostAdjustment::class), 'created_by' => auth()->id()]);
            $adjustment->fill(['branch_id' => $data['branch_id'], 'note' => $data['note'] ?? null])->save();
            $adjustment->items()->delete();
            $costs = ProductVariant::query()->whereIn('id', array_column($data['items'], 'variant_id'))->pluck('cost_price', 'id');

            foreach ($data['items'] as $item) {
                $adjustment->items()->create([
                    'variant_id' => $item['variant_id'],
                    'old_cost' => (int) ($costs[$item['variant_id']] ?? 0),
                    'new_cost' => max(0, $item['new_cost']),
                ]);
            }

            return $adjustment;
        });
    }

    public function applyCostAdjustment(CostAdjustment $adjustment): void
    {
        DB::transaction(function () use ($adjustment): void {
            /** @var CostAdjustment $locked */
            $locked = CostAdjustment::query()->with('items.variant.product')->lockForUpdate()->findOrFail($adjustment->id);
            $this->assertDraft($locked->status, CostAdjustment::DRAFT, 'Phiếu đã điều chỉnh hoặc đã hủy.');

            foreach ($locked->items->sortBy('variant_id') as $item) {
                $previous = $item->variant->cost_price;
                $this->inventory->setCost($locked->branch_id, $item->variant, $item->new_cost, [
                    'reference' => $locked,
                    'reference_code' => $locked->code,
                ]);
                $item->update(['old_cost' => $previous]);
            }

            $locked->update(['status' => CostAdjustment::APPLIED, 'applied_at' => now(), 'applied_by' => auth()->id()]);
            $adjustment->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function cancelCostAdjustment(CostAdjustment $adjustment): void
    {
        $this->assertDraft($adjustment->status, CostAdjustment::DRAFT, 'Chỉ hủy được phiếu chưa điều chỉnh.');
        $adjustment->update(['status' => CostAdjustment::CANCELLED]);
    }

    protected function assertDraft(?string $status, string $expected, string $message): void
    {
        if ($status !== null && $status !== $expected) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    /**
     * Goods that keep stock; stocktakes and cost adjustments take base
     * variants only (stock and cost live there), transfers also take units.
     *
     * @param  array<int, int>  $ids
     */
    protected function checkVariants(array $ids, bool $baseOnly): void
    {
        if ($ids === []) {
            throw ValidationException::withMessages(['items' => 'Thêm ít nhất một sản phẩm.']);
        }

        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['items' => 'Mỗi sản phẩm chỉ thêm một lần.']);
        }

        $variants = ProductVariant::query()->with('product')->whereIn('id', $ids)->get();

        if ($variants->count() !== count($ids)) {
            throw ValidationException::withMessages(['items' => 'Có sản phẩm không tồn tại hoặc đã bị xóa.']);
        }

        foreach ($variants as $variant) {
            if (! $variant->product->tracksInventory()) {
                throw ValidationException::withMessages(['items' => "{$variant->name} không quản lý tồn kho."]);
            }

            if ($baseOnly && $variant->base_variant_id !== null) {
                throw ValidationException::withMessages(['items' => "{$variant->name} là đơn vị quy đổi, hãy chọn đơn vị gốc."]);
            }
        }
    }
}
