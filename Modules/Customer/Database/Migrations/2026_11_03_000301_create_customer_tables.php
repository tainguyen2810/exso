<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customers ("Khách hàng"), their groups (price policy and default
     * discount), addresses, contacts, notes and debt ledger. Money in đồng.
     */
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->text('description')->nullable();
            // Price used for orders of the group: retail_price | wholesale_price,
            // or a price list (price_list_id) which wins when set.
            $table->string('price_column', 30)->default('retail_price');
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            // Default order discount in basis points (500 = 5%).
            $table->unsignedInteger('discount_bp')->default(0);
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            // Digits only; unique in the store when present.
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('birthday')->nullable();
            $table->string('tax_code', 30)->nullable();
            $table->string('website')->nullable();
            $table->string('address')->nullable();
            $table->string('province', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('ward', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('tags')->nullable();
            $table->foreignId('group_id')->nullable()->constrained('customer_groups')->nullOnDelete();
            // Staff in charge: users with customers.view (not view_all) only see theirs.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            // Overrides of the group defaults ("Thông tin gợi ý khi bán hàng").
            $table->string('price_column', 30)->nullable();
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            $table->unsignedInteger('discount_bp')->nullable();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            // Marketplace customers come masked (L******h); keep their platform id.
            $table->string('source', 30)->nullable();
            $table->string('marketplace_id', 100)->nullable();
            $table->string('status', 20)->default('active');
            // What the customer owes the store; changed only by CustomerLedger.
            $table->bigInteger('debt')->default(0);
            // Kept up to date by the orders module.
            $table->bigInteger('total_spent')->default(0);
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->timestamp('last_order_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'group_id']);
            $table->index(['tenant_id', 'assigned_to']);
            $table->index(['tenant_id', 'marketplace_id']);
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address');
            $table->string('province', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('ward', 100)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('position', 100)->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::create('customer_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::create('customer_debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            // Positive: the customer owes more (goods delivered, refund paid);
            // negative: payment received, goods returned, order cancelled.
            $table->bigInteger('amount');
            $table->bigInteger('debt_after');
            $table->nullableMorphs('reference');
            $table->string('reference_code', 50)->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'customer_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_debts');
        Schema::dropIfExists('customer_notes');
        Schema::dropIfExists('customer_contacts');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('customer_groups');
    }
};
