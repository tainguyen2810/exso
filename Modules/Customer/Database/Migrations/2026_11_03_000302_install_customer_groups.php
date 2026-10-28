<?php

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Modules\Customer\Models\CustomerGroup;

return new class extends Migration
{
    /**
     * Default customer groups for stores created before this module.
     */
    public function up(): void
    {
        $tenancy = app(TenantContext::class);

        foreach (Tenant::query()->get() as $tenant) {
            $tenancy->run($tenant, fn () => CustomerGroup::installDefaults());
        }
    }

    public function down(): void {}
};
