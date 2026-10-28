<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock documents: stocktakes ("Kiểm hàng"), transfers between branches
     * ("Chuyển hàng", shipped then received) and cost adjustments.
     */
    public function up(): void
    {
        Schema::create('stocktakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->string('code', 50);
            // draft = counting; balanced = stock set to the counted numbers; cancelled.
            $table->string('status', 20)->default('draft');
            $table->text('note')->nullable();
            $table->timestamp('balanced_at')->nullable();
            $table->foreignId('balanced_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'branch_id', 'created_at']);
        });

        Schema::create('stocktake_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stocktake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants');
            // Stock in the system when balanced (shown live while counting).
            $table->bigInteger('system_quantity')->default(0);
            $table->bigInteger('actual_quantity');
            $table->bigInteger('difference')->default(0);
            $table->string('reason', 100)->nullable();
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_branch_id')->constrained('branches');
            $table->foreignId('to_branch_id')->constrained('branches');
            $table->string('code', 50);
            // draft -> shipping (out of the sending branch) -> received; or cancelled.
            $table->string('status', 20)->default('draft');
            $table->text('note')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->foreignId('shipped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'from_branch_id']);
            $table->index(['tenant_id', 'to_branch_id']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants');
            $table->bigInteger('quantity');
            $table->bigInteger('received_quantity')->default(0);
            // Cost per unit when shipped, for the transfer value.
            $table->bigInteger('unit_cost')->default(0);
        });

        Schema::create('cost_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->string('code', 50);
            $table->string('status', 20)->default('draft');
            $table->text('note')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('cost_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cost_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants');
            $table->bigInteger('old_cost')->default(0);
            $table->bigInteger('new_cost');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_adjustment_items');
        Schema::dropIfExists('cost_adjustments');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stocktake_items');
        Schema::dropIfExists('stocktakes');
    }
};
