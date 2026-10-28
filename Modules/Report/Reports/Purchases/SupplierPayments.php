<?php

namespace Modules\Report\Reports\Purchases;

use Modules\Cashbook\Models\CashVoucher;
use Modules\Report\Support\Period;
use Modules\Report\Support\ReportContext;

/**
 * Báo cáo thanh toán nhập hàng: phiếu chi to suppliers (automatic from
 * receipts and entered by hand), by time, supplier or payment method.
 */
class SupplierPayments extends PurchasesReport
{
    public const BY = ['time' => 'Thời gian', 'supplier' => 'Nhà cung cấp', 'method' => 'Phương thức thanh toán'];

    public function name(): string
    {
        return 'payments';
    }

    public function title(): string
    {
        return 'Báo cáo thanh toán nhập hàng';
    }

    public function description(): string
    {
        return 'Tiền đã trả nhà cung cấp theo thời gian, nhà cung cấp, phương thức thanh toán.';
    }

    public function options(): array
    {
        return [
            'by' => ['label' => 'Theo', 'options' => self::BY, 'default' => 'time'],
            'group_by' => Period::option(),
        ];
    }

    public function columns(ReportContext $context): array
    {
        return [
            ['key' => 'label', 'label' => self::BY[$context->option('by')] ?? 'Nhóm', 'type' => 'text', 'link' => 'url'],
            ['key' => 'count', 'label' => 'Số phiếu chi', 'type' => 'number'],
            ['key' => 'amount', 'label' => 'Đã thanh toán', 'type' => 'money'],
        ];
    }

    public function rows(ReportContext $context): array
    {
        $by = $context->option('by');
        $group = $context->option('group_by');
        $rows = [];

        CashVoucher::query()
            ->whereIn('branch_id', $context->branchIds)
            ->where('kind', CashVoucher::PAYMENT)
            ->where('status', CashVoucher::COMPLETED)
            ->where('party_type', 'supplier')
            ->whereBetween('recorded_at', [$context->from, $context->to])
            ->orderBy('id')
            ->each(function (CashVoucher $voucher) use (&$rows, $by, $group): void {
                [$key, $label, $url] = match ($by) {
                    'supplier' => [(string) $voucher->party_id, $voucher->party_name, "/suppliers/{$voucher->party_id}"],
                    'method' => [$voucher->method_name, $voucher->method_name, null],
                    default => (function () use ($voucher, $group): array {
                        $key = Period::key($voucher->recorded_at->setTimezone((string) config('app.timezone'))->toDateString(), $group);

                        return [$key, Period::label($key, $group), null];
                    })(),
                };
                $row = $rows[$key] ?? ['label' => $label, 'url' => $url, 'count' => 0, 'amount' => 0];
                $row['count']++;
                $row['amount'] += $voucher->amount;
                $rows[$key] = $row;
            });

        if ($by === 'time') {
            krsort($rows);

            return array_values($rows);
        }

        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        return $rows;
    }

    public function chart(ReportContext $context): ?array
    {
        return ['x' => 'label', 'bars' => ['amount'], 'line' => null];
    }
}
