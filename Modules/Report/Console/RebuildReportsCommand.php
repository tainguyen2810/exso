<?php

namespace Modules\Report\Console;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Report\Services\SalesAggregator;

/**
 * Rebuild the daily sales tables from orders and returns. Runs nightly for
 * the last days (in case a queued refresh was lost) and after an update.
 */
class RebuildReportsCommand extends Command
{
    protected $signature = 'reports:rebuild {--days= : Chỉ dựng lại N ngày gần nhất}';

    protected $description = 'Dựng lại bảng tổng hợp doanh thu theo ngày cho báo cáo và Tổng quan';

    public function handle(SalesAggregator $aggregator): int
    {
        $days = $this->option('days');
        $timezone = (string) config('app.timezone');
        $from = is_numeric($days) ? CarbonImmutable::now($timezone)->subDays(max(1, (int) $days) - 1)->startOfDay() : null;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $count = tenancy()->run($tenant, fn (): int => $aggregator->rebuild($from));
            $this->info("{$tenant->name}: đã tổng hợp {$count} ngày bán hàng.");
        }

        return self::SUCCESS;
    }
}
