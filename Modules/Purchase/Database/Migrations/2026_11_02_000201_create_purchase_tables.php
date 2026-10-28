<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suppliers with their debt ledger, purchase orders ("Đặt hàng nhập"),
     * purchase receipts ("Nhập hàng") and returns to suppliers. Money in đồng.
     */
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('tax_code', 30)->nullable();
            $table->string('contact_name')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 20)->default('active');
            // What the store owes the supplier; changed only by SupplierLedger.
            $table->bigInteger('debt')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('supplier_debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            // Positive: the store owes more (goods received); negative: payment, return.
            $table->bigInteger('amount');
            $table->bigInteger('debt_after');
            $table->string('payment_method', 30)->nullable();
            $table->nullableMorphs('reference');
            $table->string('reference_code', 50)->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'supplier_id', 'id']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('supplier_id')->constrained();
            $table->string('code', 50);
            $table->string('status', 20)->default('pending');
            $table->date('expected_at')->nullable();
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('discount')->default(0);
            $table->bigInteger('total')->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'branch_id', 'created_at']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants');
            $table->bigInteger('quantity');
            $table->bigInteger('received_quantity')->default(0);
            $table->bigInteger('price');
            $table->bigInteger('discount')->default(0);
            $table->bigInteger('total');
        });

        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 50);
            // draft = not in stock yet; received = in stock; cancelled.
            $table->string('status', 20)->default('draft');
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('discount')->default(0);
            // Purchase costs (shipping, unloading...), [{name, amount}], spread into unit cost.
            $table->json('costs')->nullable();
            $table->bigInteger('cost_total')->default(0);
            // Payable to the supplier = subtotal - discount + cost_total.
            $table->bigInteger('total')->default(0);
            $table->bigInteger('paid')->default(0);
            $table->bigInteger('returned_total')->default(0);
            $table->string('reference', 100)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'branch_id', 'created_at']);
            $table->index(['tenant_id', 'supplier_id']);
        });

        Schema::create('purchase_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants');
            $table->bigInteger('quantity');
            $table->bigInteger('returned_quantity')->default(0);
            $table->bigInteger('price');
            $table->bigInteger('discount')->default(0);
            $table->bigInteger('total');
            // Cost per unit of the variant after spreading discount and costs.
            $table->bigInteger('unit_cost')->default(0);
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('purchase_receipt_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->bigInteger('total')->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_receipt_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants');
            $table->bigInteger('quantity');
            $table->bigInteger('price');
            $table->bigInteger('total');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('supplier_debts');
        Schema::dropIfExists('suppliers');
    }
};
