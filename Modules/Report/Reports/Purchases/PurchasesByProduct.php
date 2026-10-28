<?php

namespace Modules\Report\Reports\Purchases;

use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportContext;

/** Báo cáo nhập hàng theo sản phẩm. */
class PurchasesByProduct extends PurchasesReport
{
    public function name(): string
    {
        return 'product';
    }

    public function title(): string
    {
        return 'Báo cáo nhập hàng theo sản phẩm';
    }

    public function description(): string
    {
        return 'Số lượng, giá nhập trung bình và giá trị nhập của từng sản phẩm.';
    }

    public function options(): array
    {
        return [];
    }

    public function columns(ReportContext $context): array
    {
        return [
            ['key' => 'sku', 'label' => 'Mã SKU', 'type' => 'text'],
            ['key' => 'label', 'label' => 'Tên sản phẩm', 'type' => 'text', 'link' => 'url'],
            ['key' => 'quantity', 'label' => 'SL nhập', 'type' => 'number'],
            ['key' => 'returned_quantity', 'label' => 'SL trả NCC', 'type' => 'number'],
            ['key' => 'average_price', 'label' => 'Giá nhập TB', 'type' => 'money', 'cost' => true, 'total' => false],
            ['key' => 'amount', 'label' => 'Giá trị nhập', 'type' => 'money', 'cost' => true],
        ];
    }

    public function rows(ReportContext $context): array
    {
        $receipts = self::received($context)->select('id');

        return array_values(DB::table('purchase_receipt_items')
            ->join('product_variants', 'product_variants.id', '=', 'purchase_receipt_items.variant_id')
            ->where('purchase_receipt_items.tenant_id', tenancy()->id())
            ->whereIn('purchase_receipt_items.purchase_receipt_id', $receipts)
            ->groupBy('product_variants.id', 'product_variants.name', 'product_variants.sku', 'product_variants.product_id')
            ->selectRaw('product_variants.product_id, product_variants.name, product_variants.sku, SUM(purchase_receipt_items.quantity) as quantity, '
                .'SUM(purchase_receipt_items.returned_quantity) as returned_quantity, SUM(purchase_receipt_items.total) as amount')
            ->orderByDesc('amount')
            ->get()
            ->map(fn (object $row): array => [
                'sku' => $row->sku,
                'label' => $row->name,
                'url' => "/products/{$row->product_id}",
                'quantity' => (int) $row->quantity,
                'returned_quantity' => (int) $row->returned_quantity,
                'average_price' => (int) $row->quantity > 0 ? intdiv((int) $row->amount, (int) $row->quantity) : 0,
                'amount' => (int) $row->amount,
            ])
            ->all());
    }

    public function chart(ReportContext $context): ?array
    {
        return null;
    }
}
