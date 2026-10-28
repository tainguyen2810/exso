<?php

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Modules\Cashbook\Models\CashVoucherType;
use Modules\Cashbook\Services\CashbookService;

return new class extends Migration
{
    /**
     * Voucher types for existing stores, and automatic vouchers for the
     * payments they recorded before the cash book existed.
     */
    public function up(): void
    {
        $tenancy = app(TenantContext::class);

        foreach (Tenant::query()->get() as $tenant) {
            $tenancy->run($tenant, function (): void {
                CashVoucherType::installDefaults();
                app(CashbookService::class)->sync();
            });
        }
    }

    public function down(): void {}
};
