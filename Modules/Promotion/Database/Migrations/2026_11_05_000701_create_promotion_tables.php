<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promotions (Sapo "Khuyến mại"), coupon batches and codes ("Mã giảm
     * giá"), their use by orders, and loyalty points with member tiers.
     * Money in đồng, percentages in basis points (1000 = 10%).
     */
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            // discount (Chiết khấu) | gift (Tặng quà)
            $table->string('kind', 20);
            // order_total | product | buy_more | quantity | buy_get | gift_order | gift_product
            $table->string('method', 30);
            // active | paused | stopped; "not started" / "expired" come from the dates.
            $table->string('status', 20)->default('active');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            // {time_from, time_to, weekdays[], months[], days[]} ("Hiển thị nâng cao").
            $table->json('schedule')->nullable();
            // Orders that may use it; null = unlimited.
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            // What the rows' product ids are: variant | category | brand.
            $table->string('target_type', 20)->default('variant');
            // Same, for the discounted / gift products of buy_get and gift_product.
            $table->string('get_target_type', 20)->default('variant');
            // "Cho phép áp dụng 1 điều kiện nhiều lần trong 1 đơn hàng".
            $table->boolean('repeatable')->default(false);
            // Rows of the method, see Promotion::METHODS.
            $table->json('conditions');
            $table->boolean('all_branches')->default(true);
            $table->json('branch_ids')->nullable();
            $table->boolean('all_sources')->default(true);
            $table->json('source_ids')->nullable();
            $table->boolean('all_customers')->default(true);
            $table->json('customer_group_ids')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('coupon_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            // percent (value in basis points) | amount (đồng)
            $table->string('value_type', 20)->default('percent');
            $table->bigInteger('value');
            // "Tối đa" for a percentage.
            $table->bigInteger('max_discount')->nullable();
            // "Mỗi mã được sử dụng" / "mỗi khách hàng được sử dụng tối đa"; null = unlimited.
            $table->unsignedInteger('uses_per_code')->nullable();
            $table->unsignedInteger('uses_per_customer')->nullable();
            // "Áp dụng chung với các khuyến mại khác".
            $table->boolean('combinable')->default(false);
            // "Điều kiện mua hàng".
            $table->bigInteger('min_order')->default(0);
            $table->string('product_target_type', 20)->nullable();
            $table->json('product_ids')->nullable();
            $table->boolean('all_branches')->default(true);
            $table->json('branch_ids')->nullable();
            $table->boolean('all_sources')->default(true);
            $table->json('source_ids')->nullable();
            $table->boolean('all_customers')->default(true);
            $table->json('customer_group_ids')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('coupon_batches')->cascadeOnDelete();
            $table->string('code', 50);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamps();

            // Codes are unique in the store, so a typed code finds one coupon.
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'batch_id']);
        });

        Schema::create('order_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promotion_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('coupon_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code', 50)->nullable();
            $table->bigInteger('amount')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'coupon_id', 'customer_id']);
            $table->index(['tenant_id', 'promotion_id']);
        });

        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            // Total spent (Tổng chi tiêu) from which a customer is in the tier.
            $table->bigInteger('min_spent')->default(0);
            // Points earned × this (10000 = ×1).
            $table->unsignedInteger('earn_multiplier_bp')->default(10000);
            $table->string('description', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'min_spent']);
        });

        Schema::create('loyalty_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('points')->default(0);
            $table->bigInteger('earned_total')->default(0);
            $table->bigInteger('used_total')->default(0);
            $table->foreignId('tier_id')->nullable()->constrained('loyalty_tiers')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'customer_id']);
        });

        Schema::create('loyalty_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // earn | return | redeem | refund | adjust
            $table->string('type', 20);
            $table->bigInteger('points');
            $table->bigInteger('balance_after');
            $table->nullableMorphs('reference');
            $table->string('reference_code', 50)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_points');
        Schema::dropIfExists('loyalty_accounts');
        Schema::dropIfExists('loyalty_tiers');
        Schema::dropIfExists('order_promotions');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('coupon_batches');
        Schema::dropIfExists('promotions');
    }
};
