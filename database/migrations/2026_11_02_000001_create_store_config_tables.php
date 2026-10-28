<?php

use App\Models\Tenant;
use App\Support\Settings\StoreDefaults;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store configuration like Sapo's "Cấu hình": tax rates, payment methods,
 * sales sources, cancel/return reasons and the export/import file log.
 * Existing stores get the default rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 30);
            // Hundredths of a percent: 1000 = 10%, so no floats.
            $table->unsignedInteger('rate_bp')->default(0);
            $table->string('type', 20)->default('other'); // sale | purchase | other
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('type', 20); // cash | bank | ewallet | cod | points
            $table->string('bank_bin', 10)->nullable();
            $table->string('account_number', 30)->nullable();
            $table->string('account_name', 100)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('sales_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 30);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('order_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10); // cancel | return
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
        });

        Schema::create('file_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('module', 50);
            $table->string('action', 10); // export | import
            $table->string('file_name', 191);
            $table->string('status', 20)->default('processing'); // processing | done | failed
            $table->unsignedInteger('rows')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });

        $tenancy = app(TenantContext::class);

        foreach (Tenant::query()->get() as $tenant) {
            $tenancy->run($tenant, fn () => StoreDefaults::install());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('file_logs');
        Schema::dropIfExists('order_reasons');
        Schema::dropIfExists('sales_sources');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('tax_rates');
    }
};
