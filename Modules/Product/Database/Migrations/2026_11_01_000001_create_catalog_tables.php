<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Products, their variants (SKU level: price, barcode, unit) and the
     * lists that classify them. Stock lives in the Inventory module.
     */
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'name']);
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'name']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // normal | service (license, no stock) | combo
            $table->string('type', 20)->default('normal');
            $table->string('name');
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->text('description')->nullable();
            $table->string('tags')->nullable();
            $table->string('unit', 30)->nullable();
            $table->string('option1', 50)->nullable();
            $table->string('option2', 50)->nullable();
            $table->string('option3', 50)->nullable();
            $table->string('image_url', 500)->nullable();
            $table->boolean('is_sellable')->default(true);
            $table->boolean('allow_negative_stock')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('sku', 50);
            $table->string('barcode', 50)->nullable();
            $table->string('option1', 100)->nullable();
            $table->string('option2', 100)->nullable();
            $table->string('option3', 100)->nullable();
            $table->string('unit', 30)->nullable();
            // Conversion unit (e.g. "Thùng" = 24 × base): stock is kept on the base variant.
            $table->foreignId('base_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedInteger('unit_ratio')->default(1);
            $table->bigInteger('retail_price')->default(0);
            $table->bigInteger('wholesale_price')->default(0);
            $table->bigInteger('import_price')->default(0);
            // Weighted average cost, only changed by InventoryService.
            $table->bigInteger('cost_price')->default(0);
            $table->unsignedInteger('weight')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'sku']);
            $table->unique(['tenant_id', 'barcode']);
            $table->index(['tenant_id', 'product_id']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('combo_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->index(['tenant_id', 'product_id']);
        });

        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('variant_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->bigInteger('price');
            $table->timestamps();

            $table->unique(['price_list_id', 'variant_id']);
            $table->index(['tenant_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_prices');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('combo_items');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('product_categories');
    }
};
