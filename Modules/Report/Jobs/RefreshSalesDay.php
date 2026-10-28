<?php

namespace Modules\Report\Jobs;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Report\Services\SalesAggregator;

/**
 * Rebuild the daily sales rows of one store, branch and day after an order
 * is completed or goods are returned. Runs on the queue (the cron worker on
 * hosting); several changes of the same day are merged into one run.
 */
class RefreshSalesDay implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $tenantId, public int $branchId, public string $date) {}

    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->branchId}:{$this->date}";
    }

    public function handle(SalesAggregator $aggregator): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant !== null) {
            tenancy()->run($tenant, fn () => $aggregator->refresh($this->branchId, $this->date));
        }
    }
}
