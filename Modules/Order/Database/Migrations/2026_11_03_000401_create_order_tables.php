<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sales orders (web and POS), their lines, payments, history, customer
     * returns and POS shifts. Money in đồng; item names and prices are
     * copied at the time of sale.
     */
    public function up(): void
    {
        Schema::create('pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('user_id')->constrained('users');
            $table->string('status', 20)->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            // Cash in the drawer when opening; at closing the expected cash
            // (opening + cash received - cash refunded) is compared with the count.
            $table->bigInteger('opening_cash')->default(0);
            $table->bigInteger('cash_in')->default(0);
            $table->bigInteger('cash_out')->default(0);
            $table->bigInteger('expected_cash')->default(0);
            $table->bigInteger('counted_cash')->nullable();
            $table->bigInteger('difference')->default(0);
            $table->unsignedInteger('orders_count')->default(0);
            $table->bigInteger('sales_total')->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id', 'user_id', 'status']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->string('code', 50);
            // web (Tạo đơn) | pos (Bán tại quầy)
            $table->string('channel', 20)->default('web');
            // pending (Đặt hàng) → approved → packed → shipped (Xuất kho) → completed; cancelled.
            $table->string('status', 20)->default('pending');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->string('receiver_name')->nullable();
            $table->string('receiver_phone', 20)->nullable();
            $table->string('shipping_address', 500)->nullable();
            $table->foreignId('source_id')->nullable()->constrained('sales_sources')->nullOnDelete();
            // Price policy the order was priced with.
            $table->string('price_column', 30)->default('retail_price');
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            $table->string('price_label', 100)->nullable();
            // pickup (Khách nhận tại cửa hàng) | later (Giao hàng sau) | external (Đẩy vận chuyển ngoài)
            $table->string('delivery_method', 20)->default('later');
            // null | shipping | delivered | returning
            $table->string('delivery_status', 20)->nullable();
            $table->string('carrier_name', 100)->nullable();
            $table->string('tracking_code', 100)->nullable();
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('discount')->default(0);
            // Set when the order discount was typed as a percentage (500 = 5%).
            $table->unsignedInteger('discount_bp')->nullable();
            $table->bigInteger('shipping_fee')->default(0);
            // Customer pays: subtotal - discount + shipping_fee.
            $table->bigInteger('total')->default(0);
            // Net money received (payments - refunds).
            $table->bigInteger('paid')->default(0);
            // Value of goods taken back by returns.
            $table->bigInteger('returned_total')->default(0);
            // Cost of goods at export, for profit reports.
            $table->bigInteger('cost_total')->default(0);
            $table->string('reference', 100)->nullable();
            $table->string('tags')->nullable();
            $table->text('note')->nullable();
            $table->date('expected_delivery_at')->nullable();
            $table->timestamp('sale_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('pos_shift_id')->nullable()->constrained('pos_shifts')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('packed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancel_reason_id')->nullable()->constrained('order_reasons')->nullOnDelete();
            $table->string('cancel_note')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'branch_id', 'sale_at']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'assigned_to']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // Null for a free-text service line ("Thêm dịch vụ khác", F9).
            $table->foreignId('variant_id')->nullable()->constrained('product_variants');
            $table->foreignId('product_id')->nullable()->constrained('products');
            $table->string('product_type', 20)->default('normal');
            $table->string('name');
            $table->string('sku', 50)->nullable();
            $table->string('unit', 30)->nullable();
            $table->bigInteger('quantity');
            $table->bigInteger('price');
            // Line discount, amount for the whole line.
            $table->bigInteger('discount')->default(0);
            $table->bigInteger('total');
            // Unit cost when the goods left the stock.
            $table->bigInteger('cost')->default(0);
            $table->bigInteger('returned_quantity')->default(0);
            $table->string('note')->nullable();

            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'variant_id']);
        });

        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->string('method_name', 100);
            $table->string('method_type', 20);
            // payment (thu) | refund (hoàn tiền, amount < 0)
            $table->string('kind', 20)->default('payment');
            $table->bigInteger('amount');
            $table->nullableMorphs('reference');
            $table->foreignId('pos_shift_id')->nullable()->constrained('pos_shifts')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'branch_id', 'paid_at']);
        });

        Schema::create('order_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('action', 30);
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'order_id']);
        });

        Schema::create('order_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 50);
            $table->string('status', 20)->default('received');
            $table->foreignId('reason_id')->nullable()->constrained('order_reasons')->nullOnDelete();
            $table->text('note')->nullable();
            $table->bigInteger('quantity')->default(0);
            // Value of the goods taken back (after discounts).
            $table->bigInteger('total')->default(0);
            $table->bigInteger('refunded')->default(0);
            $table->timestamp('received_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'branch_id', 'created_at']);
            $table->index(['tenant_id', 'order_id']);
        });

        Schema::create('order_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants');
            $table->bigInteger('quantity');
            $table->bigInteger('price');
            $table->bigInteger('total');
            $table->bigInteger('cost')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_return_items');
        Schema::dropIfExists('order_returns');
        Schema::dropIfExists('order_histories');
        Schema::dropIfExists('order_payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('pos_shifts');
    }
};
