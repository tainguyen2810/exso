<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promotions on orders (G5). orders.discount keeps the whole order
     * discount (typed + promotions) so reports and returns are unchanged;
     * promotion_discount is the promotions' part of it. A line's discount
     * likewise includes its promotion_discount; gift lines cost 0.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->bigInteger('promotion_discount')->default(0)->after('discount_bp');
            // {ids, coupon, gifts, applied}: what was chosen, to edit the order later.
            $table->json('promotions')->nullable()->after('promotion_discount');
        });

        Schema::table('order_items', function (Blueprint $table) {
            // No foreign key: promotions live in their own module.
            $table->unsignedBigInteger('promotion_id')->nullable()->after('discount');
            $table->bigInteger('promotion_discount')->default(0)->after('promotion_id');
            $table->boolean('is_gift')->default(false)->after('promotion_discount');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['promotion_id', 'promotion_discount', 'is_gift']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['promotion_discount', 'promotions']);
        });
    }
};
