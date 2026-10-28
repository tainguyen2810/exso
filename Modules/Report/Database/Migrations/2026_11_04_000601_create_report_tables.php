<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily sales totals, rebuilt per branch and day from orders (counted on
     * the day they are completed) and returns (on the day they are made), so
     * reports and Tổng quan open fast. They can always be rebuilt with
     * `php artisan reports:rebuild`; nothing else writes to them.
     */
    public function up(): void
    {
        Schema::create('report_sales_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('source_id')->nullable();
            // Staff in charge of the order (assigned, else who created it).
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedInteger('orders_count')->default(0);
            $table->bigInteger('items_quantity')->default(0);
            // Tiền hàng: price × quantity before discounts.
            $table->bigInteger('goods_amount')->default(0);
            // Line discounts + order discount.
            $table->bigInteger('discount')->default(0);
            $table->bigInteger('shipping_fee')->default(0);
            $table->bigInteger('cost')->default(0);
            $table->unsignedInteger('returns_count')->default(0);
            $table->bigInteger('returned_quantity')->default(0);
            $table->bigInteger('returned_amount')->default(0);
            $table->bigInteger('returned_cost')->default(0);

            $table->index(['tenant_id', 'date', 'branch_id']);
            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::create('report_product_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('source_id')->nullable();
            // Null for a free service line; such lines group by name.
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('name');
            $table->string('sku', 50)->nullable();
            $table->bigInteger('quantity')->default(0);
            $table->bigInteger('goods_amount')->default(0);
            $table->bigInteger('discount')->default(0);
            $table->bigInteger('cost')->default(0);
            $table->bigInteger('returned_quantity')->default(0);
            $table->bigInteger('returned_amount')->default(0);
            $table->bigInteger('returned_cost')->default(0);

            $table->index(['tenant_id', 'date', 'branch_id']);
            $table->index(['tenant_id', 'variant_id']);
        });

        // "Báo cáo tùy chỉnh": a report page with its filters, saved by name.
        Schema::create('saved_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('report', 100);
            $table->json('query')->nullable();
            // Shared with every staff member who can open reports, or only the owner.
            $table->boolean('is_shared')->default(false);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_reports');
        Schema::dropIfExists('report_product_daily');
        Schema::dropIfExists('report_sales_daily');
    }
};
