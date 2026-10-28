<?php

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Modules\Report\Services\SalesAggregator;

return new class extends Migration
{
    /**
     * Daily totals for the sales made before the report tables existed.
     */
    public function up(): void
    {
        $tenancy = app(TenantContext::class);

        foreach (Tenant::query()->get() as $tenant) {
            $tenancy->run($tenant, fn () => app(SalesAggregator::class)->rebuild());
        }
    }

    public function down(): void {}
};
