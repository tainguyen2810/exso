<?php

namespace App\Support\Dashboard;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * What the Tổng quan page is showing: who is looking, which branches
 * (always within the user's own) and the days of the chart. Modules get
 * it with the dashboard.* filters and must only count rows in branchIds.
 */
final class DashboardContext
{
    /**
     * @param  list<int>  $branchIds
     */
    public function __construct(
        public readonly User $user,
        public readonly array $branchIds,
        public readonly CarbonImmutable $today,
        public readonly int $days = 7,
    ) {}

    /** First day of the chart, at midnight. */
    public function from(): CarbonImmutable
    {
        return $this->today->subDays($this->days - 1)->startOfDay();
    }

    /**
     * Sales numbers before an orders module fills them: zeros, so the page
     * keeps Sapo's layout. "ready" tells the page no module reported yet.
     *
     * @return array{ready: bool, today: array<string, int>, chart: list<array{date: string, revenue: int}>, pending: array<string, int>, top: list<array{name: string, sku: ?string, quantity: int}>}
     */
    public function emptySales(): array
    {
        $chart = [];

        for ($day = $this->from(); $day <= $this->today; $day = $day->addDay()) {
            $chart[] = ['date' => $day->toDateString(), 'revenue' => 0];
        }

        return [
            'ready' => false,
            'today' => ['revenue' => 0, 'orders' => 0, 'returns' => 0, 'cancelled' => 0],
            'chart' => $chart,
            'pending' => ['approval' => 0, 'payment' => 0, 'packing' => 0, 'pickup' => 0, 'shipping' => 0, 'returning' => 0],
            'top' => [],
        ];
    }
}
