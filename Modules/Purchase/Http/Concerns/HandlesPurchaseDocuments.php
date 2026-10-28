<?php

namespace Modules\Purchase\Http\Concerns;

use App\Models\Branch;
use App\Models\User;
use App\Support\Branches\CurrentBranch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Inventory\Http\Concerns\AuthorizesBranch;
use Modules\Inventory\Services\InventoryService;
use Modules\Product\Models\ProductVariant;
use Modules\Purchase\Http\Controllers\SupplierController;
use Modules\Purchase\Models\Supplier;
use Modules\Purchase\Models\SupplierDebt;
use Modules\Purchase\Services\PurchaseService;

/**
 * Shared by purchase orders and receipts: input validation, branch checks
 * and the line rows sent to the pages.
 *
 * @phpstan-import-type DocumentInput from PurchaseService
 */
trait HandlesPurchaseDocuments
{
    use AuthorizesBranch;

    /**
     * @return DocumentInput
     */
    protected function documentInput(Request $request, bool $withCosts): array
    {
        /** @var User $user */
        $user = $request->user();
        $money = ['nullable', 'integer', 'min:0', 'max:999999999999'];

        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('tenant_id', tenancy()->id())->whereNull('deleted_at')],
            'branch_id' => ['required', 'integer', Rule::in($user->branchIds())],
            'discount' => $money,
            'note' => ['nullable', 'string', 'max:5000'],
            'reference' => ['nullable', 'string', 'max:100'],
            'expected_at' => ['nullable', 'string', 'max:20'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000000'],
            'items.*.price' => ['required', ...array_slice($money, 1)],
            'items.*.discount' => $money,
            ...($withCosts ? [
                'costs' => ['array', 'max:20'],
                'costs.*.name' => ['required', 'string', 'max:100'],
                'costs.*.amount' => ['required', ...array_slice($money, 1)],
            ] : []),
        ], [], [
            'supplier_id' => 'nhà cung cấp',
            'branch_id' => 'chi nhánh',
            'items' => 'sản phẩm',
            'items.*.quantity' => 'số lượng',
            'items.*.price' => 'đơn giá',
            'costs.*.name' => 'tên chi phí',
            'costs.*.amount' => 'số tiền chi phí',
        ]);

        return [
            'supplier_id' => (int) $data['supplier_id'],
            'branch_id' => (int) $data['branch_id'],
            'discount' => (int) ($data['discount'] ?? 0),
            'note' => $data['note'] ?? null,
            'reference' => $data['reference'] ?? null,
            'expected_at' => $this->date($data['expected_at'] ?? null),
            'costs' => array_values(array_map(fn (array $cost): array => ['name' => (string) $cost['name'], 'amount' => (int) $cost['amount']], $data['costs'] ?? [])),
            'items' => array_values(array_map(fn (array $item): array => [
                'variant_id' => (int) $item['variant_id'],
                'quantity' => (int) $item['quantity'],
                'price' => (int) $item['price'],
                'discount' => (int) ($item['discount'] ?? 0),
            ], $data['items'])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formOptions(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        return [
            'branches' => Branch::query()->whereIn('id', $user->branchIds())->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'currentBranchId' => app(CurrentBranch::class)->id($user),
            'paymentMethods' => array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys(SupplierDebt::PAYMENT_METHODS), SupplierDebt::PAYMENT_METHODS),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function lineRow(ProductVariant $variant, int $branchId): array
    {
        $variant->loadMissing('product');
        $stock = app(InventoryService::class)->stockOf($variant, [$branchId]);

        return [
            'variant_id' => $variant->id,
            'product_id' => $variant->product_id,
            'name' => $variant->name,
            'sku' => $variant->sku,
            'unit' => $variant->unit,
            'image_url' => $variant->product->image_url,
            'tracks_inventory' => $variant->product->tracksInventory(),
            'on_hand' => $stock['on_hand'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function supplierOption(?Supplier $supplier): ?array
    {
        return $supplier === null ? null : SupplierController::option($supplier);
    }

    protected function date(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        foreach (['d/m/Y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, trim($value));
            } catch (\Throwable) {
                continue;
            }

            if ($date !== null && $date->format($format) === trim($value)) {
                return $date->toDateString();
            }
        }

        return null;
    }
}
