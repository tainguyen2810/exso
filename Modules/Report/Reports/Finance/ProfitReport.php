<?php

namespace Modules\Report\Reports\Finance;

use Modules\Cashbook\Models\CashVoucher;
use Modules\Report\Reports\Report;
use Modules\Report\Reports\Sales\SalesReport;
use Modules\Report\Support\ReportContext;
use Modules\Report\Support\SalesMetrics;

/**
 * Báo cáo lãi lỗ, laid out like Sapo: kỳ trước, kỳ hiện tại and % thay đổi.
 *
 *   Lợi nhuận = I. Doanh thu + III. Thu nhập khác − II. Chi phí − IV. Chi phí khác
 *
 * Other income and expenses are the manual vouchers marked "Hạch toán kết
 * quả kinh doanh".
 */
class ProfitReport extends Report
{
    public function group(): string
    {
        return 'finance';
    }

    public function name(): string
    {
        return 'profit';
    }

    public function title(): string
    {
        return 'Báo cáo lãi lỗ';
    }

    public function description(): string
    {
        return 'Doanh thu, giá vốn, thu nhập và chi phí khác, lợi nhuận so với kỳ trước.';
    }

    public function filters(): array
    {
        return ['period', 'branch'];
    }

    public function permission(): ?string
    {
        return 'products.view_cost';
    }

    public function columns(ReportContext $context): array
    {
        return [
            ['key' => 'label', 'label' => 'Chỉ tiêu', 'type' => 'text'],
            ['key' => 'previous', 'label' => 'Kỳ trước', 'type' => 'money'],
            ['key' => 'current', 'label' => 'Kỳ hiện tại', 'type' => 'money'],
            ['key' => 'change', 'label' => '% thay đổi', 'type' => 'percent'],
        ];
    }

    public function rows(ReportContext $context): array
    {
        $current = self::figures($context);
        $previous = self::figures($context->previous());

        $lines = [
            ['revenue', 'I. Doanh thu bán hàng (1 + 2 − 3)', 'section'],
            ['net_goods', '1. Tiền hàng thực bán (a − b)', 'item'],
            ['goods', 'a. Tiền hàng bán ra', 'sub'],
            ['returned', 'b. Tiền hàng trả lại', 'sub'],
            ['shipping', '2. Phí giao hàng thu của khách', 'item'],
            ['discount', '3. Chiết khấu', 'item'],
            ['expenses', 'II. Chi phí bán hàng', 'section'],
            ['cost', '1. Giá vốn hàng bán', 'item'],
            ['other_income', 'III. Thu nhập khác (phiếu thu hạch toán)', 'section'],
            ['other_expenses', 'IV. Chi phí khác (phiếu chi hạch toán)', 'section'],
            ['profit', 'LỢI NHUẬN (I + III − II − IV)', 'total'],
        ];

        return array_values(array_map(fn (array $line): array => [
            'key' => $line[0],
            'label' => $line[1],
            'level' => $line[2],
            'previous' => $previous[$line[0]],
            'current' => $current[$line[0]],
            'change' => $previous[$line[0]] !== 0
                ? round(($current[$line[0]] - $previous[$line[0]]) * 100 / abs($previous[$line[0]]), 1)
                : null,
        ], $lines));
    }

    public function totals(ReportContext $context, array $rows): ?array
    {
        return null;
    }

    public function chart(ReportContext $context): ?array
    {
        return null;
    }

    /**
     * @return array<string, int>
     */
    public static function figures(ReportContext $context): array
    {
        $sales = SalesMetrics::from(SalesReport::daily($context)->selectRaw(SalesMetrics::SUMS)->first()?->getAttributes() ?? []);

        $vouchers = fn (string $kind): int => (int) CashVoucher::query()
            ->whereIn('branch_id', $context->branchIds)
            ->where('kind', $kind)
            ->where('status', CashVoucher::COMPLETED)
            ->where('is_auto', false)
            ->where('affects_profit', true)
            ->whereBetween('recorded_at', [$context->from, $context->to])
            ->sum('amount');

        $figures = [
            'goods' => $sales['goods_amount'],
            'returned' => $sales['returned_amount'],
            'net_goods' => $sales['goods_amount'] - $sales['returned_amount'],
            'shipping' => $sales['shipping_fee'],
            'discount' => $sales['discount'],
            'revenue' => $sales['revenue'],
            'cost' => $sales['cost'],
            'expenses' => $sales['cost'],
            'other_income' => $vouchers(CashVoucher::RECEIPT),
            'other_expenses' => $vouchers(CashVoucher::PAYMENT),
        ];

        $figures['profit'] = $figures['revenue'] + $figures['other_income'] - $figures['expenses'] - $figures['other_expenses'];

        return $figures;
    }
}
