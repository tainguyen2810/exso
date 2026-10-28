<?php

namespace Modules\Inventory\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Inventory\Events\StockChanged;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\StockMovement;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;

/**
 * The only place stock changes. Every change runs in a transaction, locks
 * the variant and inventory rows, and writes a stock movement.
 *
 * Quantities given here are in the variant's own unit: 2 × "Thùng 24" moves
 * 48 of the base variant; a combo moves its components; services move nothing.
 */
class InventoryService
{
    /**
     * Add (positive) or take (negative) stock in a branch.
     *
     * Options:
     *  - unit_cost: cost per unit of $variant for incoming stock (opening, purchase).
     *  - update_cost: recalculate the weighted average cost with unit_cost (default: false).
     *  - reference: the document (Model) that caused the change.
     *  - reference_code, note: shown on the stock card.
     *  - allow_negative: true to allow going below zero for this call.
     *
     * @param  array{unit_cost?: int, update_cost?: bool, reference?: Model|null, reference_code?: string|null, note?: string|null, allow_negative?: bool}  $options
     * @return list<StockMovement>
     */
    public function move(int $branchId, ProductVariant $variant, int $quantity, string $type, array $options = []): array
    {
        if (! array_key_exists($type, StockMovement::TYPES)) {
            throw new InvalidArgumentException("Unknown stock movement type [{$type}].");
        }

        if ($quantity === 0) {
            return [];
        }

        $unitCost = $options['unit_cost'] ?? null;
        $lines = $this->components($variant, $quantity, $unitCost);

        if ($lines === []) {
            return [];
        }

        $movements = DB::transaction(function () use ($branchId, $lines, $type, $options): array {
            $movements = [];

            foreach ($lines as [$base, $delta, $cost]) {
                $movements[] = $this->moveBase($branchId, $base, $delta, $cost, $type, $options);
            }

            return $movements;
        });

        DB::afterCommit(fn () => event(new StockChanged($movements)));

        return $movements;
    }

    /**
     * Set the stock of a branch to a counted quantity (stocktake). The
     * difference is computed under the lock, so a sale made while counting
     * is not lost. Only for base variants of ordinary goods.
     *
     * @param  array{reference?: Model|null, reference_code?: string|null, note?: string|null}  $options
     * @return array{system: int, difference: int}
     */
    public function setOnHand(int $branchId, ProductVariant $variant, int $actual, string $type, array $options = []): array
    {
        $this->assertBaseGoods($variant);

        return DB::transaction(function () use ($branchId, $variant, $actual, $type, $options): array {
            /** @var ProductVariant $locked */
            $locked = ProductVariant::query()->withTrashed()->with('product')->lockForUpdate()->findOrFail($variant->id);
            $system = $this->lockInventory($branchId, $locked->id)->on_hand;
            $difference = $actual - $system;

            if ($difference !== 0) {
                $movement = $this->moveBase($branchId, $locked, $difference, null, $type, [...$options, 'allow_negative' => true]);
                DB::afterCommit(fn () => event(new StockChanged([$movement])));
            }

            return ['system' => $system, 'difference' => $difference];
        });
    }

    /**
     * Set the average cost of a variant by hand ("Điều chỉnh giá vốn"). Writes
     * a zero-quantity movement so the stock card shows the change.
     *
     * @param  array{reference?: Model|null, reference_code?: string|null, note?: string|null}  $options
     */
    public function setCost(int $branchId, ProductVariant $variant, int $cost, array $options = []): StockMovement
    {
        $this->assertBaseGoods($variant);

        if ($cost < 0) {
            throw new InvalidArgumentException('Cost cannot be negative.');
        }

        return DB::transaction(function () use ($branchId, $variant, $cost, $options): StockMovement {
            /** @var ProductVariant $locked */
            $locked = ProductVariant::query()->withTrashed()->lockForUpdate()->findOrFail($variant->id);
            $inventory = $this->lockInventory($branchId, $locked->id);
            $previous = $locked->cost_price;
            $locked->forceFill(['cost_price' => $cost])->save();
            $variant->cost_price = $cost;
            $reference = $options['reference'] ?? null;

            /** @var StockMovement $movement */
            $movement = StockMovement::query()->create([
                'branch_id' => $branchId,
                'variant_id' => $locked->id,
                'type' => StockMovement::COST_ADJUSTMENT,
                'quantity' => 0,
                'on_hand_after' => $inventory->on_hand,
                'unit_cost' => $previous,
                'cost_after' => $cost,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'reference_code' => $options['reference_code'] ?? null,
                'note' => $options['note'] ?? null,
                'created_by' => auth()->id(),
            ]);

            return $movement;
        });
    }

    /**
     * Hold stock for an order that is approved but not shipped (Có thể bán goes down).
     */
    public function reserve(int $branchId, ProductVariant $variant, int $quantity): void
    {
        $this->changeReserved($branchId, $variant, $quantity);
    }

    public function release(int $branchId, ProductVariant $variant, int $quantity): void
    {
        $this->changeReserved($branchId, $variant, -$quantity);
    }

    /**
     * Stock of a variant per branch, in the variant's own unit for conversion
     * units (floor), and for combos the number of full combos the components allow.
     *
     * @param  list<int>  $branchIds
     * @return array{on_hand: int, available: int}
     */
    public function stockOf(ProductVariant $variant, array $branchIds): array
    {
        $variant->loadMissing('product');

        if ($variant->product->type === Product::TYPE_SERVICE) {
            return ['on_hand' => 0, 'available' => 0];
        }

        if ($variant->product->type === Product::TYPE_COMBO) {
            $variant->product->loadMissing('comboItems.variant.product');
            $onHand = null;
            $available = null;

            foreach ($variant->product->comboItems as $item) {
                $stock = $this->stockOf($item->variant, $branchIds);
                $onHand = min($onHand ?? PHP_INT_MAX, intdiv(max(0, $stock['on_hand']), $item->quantity));
                $available = min($available ?? PHP_INT_MAX, intdiv(max(0, $stock['available']), $item->quantity));
            }

            return ['on_hand' => $onHand ?? 0, 'available' => $available ?? 0];
        }

        $baseId = $variant->base_variant_id ?? $variant->id;
        $ratio = max(1, $variant->unit_ratio);

        $row = Inventory::query()
            ->where('variant_id', $baseId)
            ->whereIn('branch_id', $branchIds)
            ->selectRaw('COALESCE(SUM(on_hand), 0) as on_hand, COALESCE(SUM(reserved), 0) as reserved')
            ->first();

        $onHand = (int) ($row?->getAttribute('on_hand') ?? 0);
        $reserved = (int) ($row?->getAttribute('reserved') ?? 0);

        return [
            'on_hand' => $this->divide($onHand, $ratio),
            'available' => $this->divide($onHand - $reserved, $ratio),
        ];
    }

    /**
     * Resolve what really moves: [base variant, quantity in base unit, unit cost per base unit].
     *
     * @return list<array{0: ProductVariant, 1: int, 2: int|null}>
     */
    public function components(ProductVariant $variant, int $quantity, ?int $unitCost = null): array
    {
        $variant->loadMissing('product');

        return match ($variant->product->type) {
            Product::TYPE_SERVICE => [],
            Product::TYPE_COMBO => $this->comboComponents($variant->product, $quantity),
            default => $this->unitComponent($variant, $quantity, $unitCost),
        };
    }

    /**
     * @return list<array{0: ProductVariant, 1: int, 2: int|null}>
     */
    protected function comboComponents(Product $combo, int $quantity): array
    {
        $combo->loadMissing('comboItems.variant.product');
        $lines = [];

        foreach ($combo->comboItems as $item) {
            if ($item->variant->product->type === Product::TYPE_COMBO) {
                continue; // combos inside combos are not allowed
            }

            array_push($lines, ...$this->components($item->variant, $quantity * $item->quantity));
        }

        return $lines;
    }

    /**
     * @return list<array{0: ProductVariant, 1: int, 2: int|null}>
     */
    protected function unitComponent(ProductVariant $variant, int $quantity, ?int $unitCost): array
    {
        if ($variant->base_variant_id === null) {
            return [[$variant, $quantity, $unitCost]];
        }

        $base = ProductVariant::query()->withTrashed()->findOrFail($variant->base_variant_id);
        $ratio = max(1, $variant->unit_ratio);

        return [[$base, $quantity * $ratio, $unitCost === null ? null : intdiv($unitCost, $ratio)]];
    }

    /**
     * @param  array{unit_cost?: int, update_cost?: bool, reference?: Model|null, reference_code?: string|null, note?: string|null, allow_negative?: bool}  $options
     */
    protected function moveBase(int $branchId, ProductVariant $variant, int $delta, ?int $unitCost, string $type, array $options): StockMovement
    {
        // Lock the variant first: every change of this variant (any branch) waits here,
        // so the total used for the average cost is stable.
        /** @var ProductVariant $locked */
        $locked = ProductVariant::query()->withTrashed()->with('product')->lockForUpdate()->findOrFail($variant->id);
        $inventory = $this->lockInventory($branchId, $locked->id);

        $onHandAfter = $inventory->on_hand + $delta;

        if ($delta < 0 && $onHandAfter < 0 && ! ($options['allow_negative'] ?? false) && ! $locked->product->allow_negative_stock) {
            throw new InsufficientStockException($locked, $inventory->on_hand, -$delta);
        }

        $previousCost = $locked->cost_price;
        $cost = $previousCost;

        if ($delta > 0 && ($options['update_cost'] ?? false) && $unitCost !== null) {
            $cost = $this->averageCost($locked, $delta, $unitCost);
            $locked->forceFill(['cost_price' => $cost])->save();
        }

        $inventory->forceFill(['on_hand' => $onHandAfter])->save();

        $reference = $options['reference'] ?? null;

        /** @var StockMovement $movement */
        $movement = StockMovement::query()->create([
            'branch_id' => $branchId,
            'variant_id' => $locked->id,
            'type' => $type,
            'quantity' => $delta,
            'on_hand_after' => $onHandAfter,
            'unit_cost' => $delta > 0 && $unitCost !== null ? $unitCost : $previousCost,
            'cost_after' => $cost,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'reference_code' => $options['reference_code'] ?? null,
            'note' => $options['note'] ?? null,
            'created_by' => auth()->id(),
        ]);

        $variant->cost_price = $cost;

        return $movement;
    }

    /**
     * Weighted average over the stock of all branches:
     * (old qty × old cost + new qty × new cost) / (old qty + new qty).
     */
    protected function averageCost(ProductVariant $variant, int $quantity, int $unitCost): int
    {
        $total = (int) Inventory::query()->where('variant_id', $variant->id)->sum('on_hand');

        if ($total <= 0) {
            return $unitCost;
        }

        return intdiv($total * $variant->cost_price + $quantity * $unitCost + intdiv($total + $quantity, 2), $total + $quantity);
    }

    protected function lockInventory(int $branchId, int $variantId): Inventory
    {
        $find = fn (): ?Inventory => Inventory::query()
            ->where('branch_id', $branchId)
            ->where('variant_id', $variantId)
            ->lockForUpdate()
            ->first();

        $inventory = $find();

        if ($inventory === null) {
            Inventory::query()->insertOrIgnore([
                'tenant_id' => tenancy()->id(),
                'branch_id' => $branchId,
                'variant_id' => $variantId,
                'on_hand' => 0,
                'reserved' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $inventory = $find();
        }

        if ($inventory === null) {
            throw new InvalidArgumentException('Could not create the inventory row.');
        }

        return $inventory;
    }

    protected function changeReserved(int $branchId, ProductVariant $variant, int $quantity): void
    {
        DB::transaction(function () use ($branchId, $variant, $quantity): void {
            foreach ($this->components($variant, $quantity) as [$base, $delta]) {
                $inventory = $this->lockInventory($branchId, $base->id);
                $inventory->forceFill(['reserved' => max(0, $inventory->reserved + $delta)])->save();
            }
        });
    }

    protected function assertBaseGoods(ProductVariant $variant): void
    {
        $variant->loadMissing('product');

        if ($variant->base_variant_id !== null || ! $variant->product->tracksInventory()) {
            throw new InvalidArgumentException("{$variant->sku} is not a base variant of goods that keep stock.");
        }
    }

    protected function divide(int $quantity, int $ratio): int
    {
        return $ratio === 1 ? $quantity : intdiv($quantity, $ratio);
    }
}
