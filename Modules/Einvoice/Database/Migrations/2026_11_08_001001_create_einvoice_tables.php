<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hóa đơn điện tử (G7): connections to e-invoice providers ("đơn vị
     * phát hành", e.g. Viettel S-Invoice) and the invoices made from orders,
     * with their lines. Money in đồng; tax as a percentage (-1 = không kê
     * khai nộp thuế, -2 = không chịu thuế).
     */
    public function up(): void
    {
        Schema::create('einvoice_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Driver, e.g. "viettel".
            $table->string('driver', 30);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_sandbox')->default(false);
            // Account of the provider, encrypted with the app key; never sent to the browser.
            $table->text('credentials')->nullable();
            // Seller tax code, invoice template ("mẫu số"), series ("ký hiệu").
            $table->json('settings')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'driver']);
        });

        Schema::create('einvoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('code', 50);
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('einvoice_providers')->nullOnDelete();
            // original | adjustment (hóa đơn điều chỉnh)
            $table->string('kind', 20)->default('original');
            $table->foreignId('original_id')->nullable()->constrained('einvoices')->nullOnDelete();
            // Adjustment: increase | decrease (tiền) | info (thông tin).
            $table->string('adjustment_type', 20)->nullable();
            // draft (Chưa phát hành) | issued | failed | cancelled
            $table->string('status', 20)->default('draft');
            // Key sent to the provider so a retried call never issues twice.
            $table->uuid('transaction_uuid');
            $table->string('template_code', 30)->nullable();
            $table->string('series', 30)->nullable();
            $table->string('invoice_no', 50)->nullable();
            // "Mã tra cứu" given by the provider.
            $table->string('lookup_code', 100)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('buyer_company')->nullable();
            $table->string('buyer_tax_code', 20)->nullable();
            $table->string('buyer_address', 500)->nullable();
            $table->string('buyer_email')->nullable();
            $table->string('buyer_phone', 20)->nullable();
            $table->string('buyer_id_number', 20)->nullable();
            $table->string('buyer_bank_account', 50)->nullable();
            $table->string('buyer_bank_name')->nullable();
            $table->string('payment_method', 50)->default('TM/CK');
            // Sum of quantity × price, line discounts, then amounts before tax, tax and total.
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('discount')->default(0);
            $table->bigInteger('amount_before_tax')->default(0);
            $table->bigInteger('tax_amount')->default(0);
            $table->bigInteger('total')->default(0);
            $table->string('note', 500)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'transaction_uuid']);
            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'issued_at']);
        });

        Schema::create('einvoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('einvoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('code', 100)->nullable();
            $table->string('name', 500);
            $table->string('unit', 30)->nullable();
            $table->bigInteger('quantity');
            $table->bigInteger('price');
            $table->bigInteger('discount')->default(0);
            // quantity × price − discount, as sold (with or without tax per tax.price_includes_tax).
            $table->bigInteger('amount');
            $table->smallInteger('tax_percent')->default(10);
            $table->bigInteger('amount_before_tax')->default(0);
            $table->bigInteger('tax_amount')->default(0);
            $table->bigInteger('amount_after_tax')->default(0);
            // Gift line ("hàng khuyến mại, không thu tiền").
            $table->boolean('is_promotion')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);

            $table->index(['tenant_id', 'einvoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('einvoice_items');
        Schema::dropIfExists('einvoices');
        Schema::dropIfExists('einvoice_providers');
    }
};
