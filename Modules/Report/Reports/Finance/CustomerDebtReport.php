<?php

namespace Modules\Report\Reports\Finance;

use Illuminate\Support\Facades\DB;
use Modules\Customer\Models\Customer;
use Modules\Report\Reports\Report;
use Modules\Report\Support\ReportContext;

/**
 * Công nợ phải thu: nợ đầu kỳ, phát sinh tăng, phát sinh giảm, nợ cuối kỳ
 * of each customer the user may see, from the debt ledger.
 */
class CustomerDebtReport extends Report
{
    public function group(): string
    {
        return 'finance';
    }

    public function name(): string
    {
        return 'customer-debts';
    }

    public function title(): string
    {
        return 'Báo cáo công nợ khách hàng';
    }

    public function description(): string
    {
        return 'Nợ đầu kỳ, phát sinh tăng/giảm và nợ cuối kỳ của từng khách hàng.';
    }

    public function filters(): array
    {
        return ['period'];
    }

    public function permission(): ?string
    {
        return 'customers.view';
    }

    protected function ledger(): string
    {
        return 'customer_debts';
    }

    /** @return literal-string */
    protected function partyColumn(): string
    {
        return 'customer_id';
    }

    protected function partyHeading(): string
    {
        return 'Khách hàng';
    }

    public function columns(ReportContext $context): array
    {
        return [
            ['key' => 'label', 'label' => $this->partyHeading(), 'type' => 'text', 'link' => 'url'],
            ['key' => 'opening', 'label' => 'Nợ đầu kỳ', 'type' => 'money'],
            ['key' => 'increase', 'label' => 'Phát sinh tăng', 'type' => 'money'],
            ['key' => 'decrease', 'label' => 'Phát sinh giảm', 'type' => 'money'],
            ['key' => 'closing', 'label' => 'Nợ cuối kỳ', 'type' => 'money'],
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{0: string, 1: string}> id => [label, url]
     */
    protected function parties(ReportContext $context, array $ids): array
    {
        $labels = [];

        foreach (Customer::query()->withTrashed()->visibleTo($context->user)->whereKey($ids)->get(['id', 'name', 'code']) as $customer) {
            $labels[$customer->id] = ["{$customer->name} ({$customer->code})", "/customers/{$customer->id}"];
        }

        return $labels;
    }

    public function rows(ReportContext $context): array
    {
        $tenantId = tenancy()->id();
        $party = $this->partyColumn();
        $ledger = $this->ledger();

        // Balance before the period: debt_after of each party's last line.
        $opening = DB::table($ledger)
            ->where('tenant_id', $tenantId)
            ->whereIn('id', DB::table($ledger)
                ->where('tenant_id', $tenantId)
                ->where('created_at', '<', $context->from)
                ->groupBy($party)
                ->selectRaw('MAX(id)'))
            ->pluck('debt_after', $party);

        $moves = DB::table($ledger)
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$context->from, $context->to])
            ->groupBy($party)
            ->selectRaw("{$party} as party, SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as increase, SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) as decrease")
            ->get()
            ->keyBy('party');

        $ids = array_values(array_unique([...$opening->keys()->all(), ...$moves->keys()->all()]));
        $labels = $this->parties($context, array_map('intval', $ids));
        $rows = [];

        foreach ($labels as $id => [$label, $url]) {
            $start = (int) ($opening[$id] ?? 0);
            $increase = (int) ($moves[$id]->increase ?? 0);
            $decrease = (int) ($moves[$id]->decrease ?? 0);

            if ($start === 0 && $increase === 0 && $decrease === 0) {
                continue;
            }

            $rows[] = [
                'label' => $label,
                'url' => $url,
                'opening' => $start,
                'increase' => $increase,
                'decrease' => $decrease,
                'closing' => $start + $increase - $decrease,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['closing'] <=> $a['closing']);

        return $rows;
    }
}
