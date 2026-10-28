<?php

namespace Modules\Report\Reports\Purchases;

use Illuminate\Database\Eloquent\Builder;
use Modules\Purchase\Models\PurchaseReceipt;
use Modules\Report\Reports\Report;
use Modules\Report\Support\Period;
use Modules\Report\Support\ReportContext;

/**
 * Báo cáo hàng nhập kho: received purchase receipts (on the day they were
 * received), by time or supplier. Purchase volumes are small, so this reads
 * the receipts directly.
 */
class PurchasesReport extends Report
{
    public function group(): string
    {
        return 'purchases';
    }

    public function name(): string
    {
        return 'time';
    }

    public function title(): string
    {
        return 'Báo cáo nhập hàng theo thời gian';
    }

    public function description(): string
    {
        return 'Số phiếu, số lượng và giá trị hàng nhập kho theo ngày, tuần, tháng.';
    }

    public function filters(): array
    {
        return ['period', 'branch'];
    }

    public function options(): array
    {
        return ['group_by' => Period::option()];
    }

    /**
     * @return Builder<PurchaseReceipt>
     */
    public static function received(ReportContext $context): Builder
    {
        return PurchaseReceipt::query()
            ->whereIn('branch_id', $context->branchIds)
            ->where('status', PurchaseReceipt::RECEIVED)
            ->whereBetween('received_at', [$context->from, $context->to]);
    }

    public function columns(ReportContext $context): array
    {
        return [
            ['key' => 'label', 'label' => $this->labelHeading(), 'type' => 'text', 'link' => 'url'],
            ['key' => 'receipts', 'label' => 'SL phiếu nhập', 'type' => 'number'],
            ['key' => 'quantity', 'label' => 'SL hàng nhập', 'type' => 'number'],
            ['key' => 'subtotal', 'label' => 'Tiền hàng', 'type' => 'money'],
            ['key' => 'discount', 'label' => 'Chiết khấu', 'type' => 'money'],
            ['key' => 'cost_total', 'label' => 'Chi phí nhập', 'type' => 'money'],
            ['key' => 'total', 'label' => 'Giá trị nhập', 'type' => 'money'],
            ['key' => 'returned', 'label' => 'Hàng trả NCC', 'type' => 'money'],
            ['key' => 'paid', 'label' => 'Đã thanh toán', 'type' => 'money'],
            ['key' => 'remaining', 'label' => 'Còn phải trả', 'type' => 'money'],
        ];
    }

    protected function labelHeading(): string
    {
        return 'Thời gian';
    }

    /**
     * Bucket key and [label, url] of a receipt.
     *
     * @return array{0: string, 1: string, 2: string|null}
     */
    protected function bucket(PurchaseReceipt $receipt, ReportContext $context): array
    {
        $group = $context->option('group_by');
        $key = Period::key((string) $receipt->received_at?->setTimezone((string) config('app.timezone'))->toDateString(), $group);

        return [$key, Period::label($key, $group), null];
    }

    public function rows(ReportContext $context): array
    {
        $rows = [];

        self::received($context)
            ->with(['supplier' => fn ($query) => $query->withTrashed()->select(['id', 'name', 'code'])])
            ->withSum('items as quantity', 'quantity')
            ->orderBy('id')
            ->each(function (PurchaseReceipt $receipt) use (&$rows, $context): void {
                [$key, $label, $url] = $this->bucket($receipt, $context);
                $row = $rows[$key] ?? ['key' => $key, 'label' => $label, 'url' => $url, 'receipts' => 0, 'quantity' => 0, 'subtotal' => 0, 'discount' => 0, 'cost_total' => 0, 'total' => 0, 'returned' => 0, 'paid' => 0];
                $row['receipts']++;
                $row['quantity'] += (int) $receipt->getAttribute('quantity');
                $row['subtotal'] += $receipt->subtotal;
                $row['discount'] += $receipt->discount;
                $row['cost_total'] += $receipt->cost_total;
                $row['total'] += $receipt->total;
                $row['returned'] += $receipt->returned_total;
                $row['paid'] += $receipt->paid;
                $rows[$key] = $row;
            });

        $rows = array_map(fn (array $row): array => [...$row, 'remaining' => $row['total'] - $row['returned'] - $row['paid']], $rows);

        return $this->sort($rows);
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function sort(array $rows): array
    {
        krsort($rows);

        return array_values($rows);
    }

    public function chart(ReportContext $context): ?array
    {
        return ['x' => 'label', 'bars' => ['total'], 'line' => null, 'fill' => 'time'];
    }
}
