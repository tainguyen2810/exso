<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sàn TMĐT (G7): connected shops ("gian hàng"), their listings linked to
     * EXSO variants ("liên kết sản phẩm") and the platform orders brought in
     * as EXSO orders. Money in đồng.
     */
    public function up(): void
    {
        Schema::create('marketplace_shops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // shopee (more platforms register a driver).
            $table->string('platform', 20);
            // Shop id on the platform; null until the seller authorised EXSO.
            $table->string('external_id', 50)->nullable();
            $table->string('name');
            // pending (waiting for the seller to authorise) | active | expired | disconnected
            $table->string('status', 20)->default('pending');
            // Branch that receives the shop's orders.
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->boolean('is_sandbox')->default(false);
            // Partner key, access/refresh tokens: encrypted with the app key, never sent to the browser.
            $table->text('credentials')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            // Price policy, staff in charge, stock branches, payment method...
            $table->json('settings')->nullable();
            // Secret part of the push (webhook) URL given to the platform.
            $table->string('webhook_token', 64)->nullable()->unique();
            $table->timestamp('orders_synced_at')->nullable();
            $table->timestamp('products_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'platform', 'external_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['platform', 'external_id']);
        });

        Schema::create('marketplace_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained('marketplace_shops')->cascadeOnDelete();
            $table->string('item_id', 50);
            // Variation ("phân loại") on the platform; "0" when the item has none.
            $table->string('model_id', 50)->default('0');
            $table->string('name');
            $table->string('model_name')->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('image_url', 500)->nullable();
            $table->bigInteger('price')->default(0);
            // Stock the platform shows.
            $table->integer('stock')->default(0);
            $table->string('status', 30)->nullable();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->timestamp('linked_at')->nullable();
            // "Có thể bán" changed and must be pushed to the platform.
            $table->boolean('stock_dirty')->default(false);
            $table->integer('pushed_stock')->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->string('push_error', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'shop_id', 'item_id', 'model_id']);
            $table->index(['tenant_id', 'variant_id']);
            $table->index(['tenant_id', 'shop_id', 'stock_dirty']);
        });

        Schema::create('marketplace_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained('marketplace_shops')->cascadeOnDelete();
            // Order number on the platform (e.g. Shopee order_sn).
            $table->string('external_id', 64);
            // Raw platform status and the common one (see MarketplaceOrder::STATES).
            $table->string('platform_status', 40)->nullable();
            $table->string('state', 20)->default('to_ship');
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            // synced | unlinked (products not linked yet) | error
            $table->string('sync_status', 20)->default('synced');
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('buyer_id', 50)->nullable();
            $table->string('buyer_username', 100)->nullable();
            $table->string('recipient_name')->nullable();
            $table->bigInteger('total')->default(0);
            $table->string('carrier', 100)->nullable();
            $table->string('tracking_code', 100)->nullable();
            // Lines as the platform sent them (names, SKUs, prices).
            $table->json('items')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('platform_updated_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'shop_id', 'external_id']);
            $table->index(['tenant_id', 'sync_status']);
            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'placed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_orders');
        Schema::dropIfExists('marketplace_listings');
        Schema::dropIfExists('marketplace_shops');
    }
};
