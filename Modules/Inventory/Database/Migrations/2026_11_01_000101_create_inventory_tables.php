<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * inventories holds the current numbers per variant × branch;
     * stock_movements is the ledger every change is written to.
     */
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->bigInteger('on_hand')->default(0);
            // Held for orders that are approved but not shipped yet.
            $table->bigInteger('reserved')->default(0);
            $table->bigInteger('min_quantity')->nullable();
            $table->bigInteger('max_quantity')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'branch_id', 'variant_id']);
            $table->index(['tenant_id', 'variant_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->string('type', 30);
            $table->bigInteger('quantity');
            $table->bigInteger('on_hand_after');
            $table->bigInteger('unit_cost')->default(0);
            $table->bigInteger('cost_after')->default(0);
            $table->nullableMorphs('reference');
            $table->string('reference_code', 50)->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'variant_id', 'branch_id', 'id']);
            $table->index(['tenant_id', 'branch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('inventories');
    }
};
