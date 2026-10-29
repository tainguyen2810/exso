<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vận chuyển (G6): shipping partners (integrated carriers such as GHN and
     * partners the store contacts itself), shipments ("phiếu giao hàng") of
     * orders with their status history, and COD reconciliations ("đối soát
     * COD và phí"). Money in đồng, weight in grams, sizes in cm.
     */
    public function up(): void
    {
        Schema::create('carriers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            // integrated (connected by API) | manual ("Đối tác tự liên hệ")
            $table->string('kind', 20);
            // Driver of an integrated carrier, e.g. "ghn".
            $table->string('driver', 30)->nullable();
            $table->string('name');
            $table->string('phone', 20)->nullable();
            $table->string('note', 500)->nullable();
            $table->boolean('is_active')->default(true);
            // Integrated: use the carrier's test environment.
            $table->boolean('is_sandbox')->default(false);
            // API token etc., encrypted with the app key; never sent to the browser.
            $table->text('credentials')->nullable();
            // Shop id, pick-up shops per branch, default service...
            $table->json('settings')->nullable();
            // Secret part of the webhook URL given to the carrier.
            $table->string('webhook_token', 64)->nullable()->unique();
            $table->timestamp('connected_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'kind', 'is_active']);
        });

        Schema::create('cod_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('carrier_id')->constrained()->restrictOnDelete();
            $table->string('code', 50);
            // reconciling (Đang đối soát) | done (Đã đối soát) | cancelled
            $table->string('status', 20)->default('reconciling');
            $table->unsignedInteger('shipments_count')->default(0);
            // What EXSO expects ("hệ thống") and what the partner reports.
            $table->bigInteger('cod_total')->default(0);
            $table->bigInteger('fee_total')->default(0);
            $table->bigInteger('partner_cod_total')->default(0);
            $table->bigInteger('partner_fee_total')->default(0);
            // Money the partner pays the store: partner COD - partner fee.
            $table->bigInteger('amount')->default(0);
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 1000)->nullable();
            $table->string('tags')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'carrier_id', 'status']);
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('carrier_id')->constrained()->restrictOnDelete();
            // FUN00001 ("mã đóng gói") and the carrier's tracking code ("mã vận đơn").
            $table->string('code', 50);
            $table->string('tracking_code', 100)->nullable();
            // pending | shipping | redelivery | delivered | returning | returned | cancelled
            $table->string('status', 20)->default('pending');
            // Raw status of the carrier (e.g. GHN "delivering").
            $table->string('carrier_status', 50)->nullable();
            $table->string('service_id', 50)->nullable();
            $table->string('service_name', 100)->nullable();
            // Money the carrier collects from the receiver.
            $table->bigInteger('cod_amount')->default(0);
            // What the store pays the carrier ("Phí trả ĐTVC").
            $table->bigInteger('fee')->default(0);
            // shop | receiver
            $table->string('fee_payer', 20)->default('shop');
            $table->bigInteger('insurance_value')->default(0);
            $table->unsignedInteger('weight')->default(0);
            $table->unsignedInteger('length')->default(0);
            $table->unsignedInteger('width')->default(0);
            $table->unsignedInteger('height')->default(0);
            $table->string('receiver_name');
            $table->string('receiver_phone', 20);
            $table->string('address', 500);
            $table->string('ward')->nullable();
            $table->string('district')->nullable();
            $table->string('province')->nullable();
            // CHOTHUHANG | CHOXEMHANGKHONGTHU | KHONGCHOXEMHANG
            $table->string('required_note', 30)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('expected_delivery_at')->nullable();
            $table->timestamp('picked_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            // Current reconciliation (not cancelled) and when it was completed.
            $table->foreignId('cod_reconciliation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'carrier_id', 'tracking_code']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->nullable();
            $table->string('carrier_status', 50)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'shipment_id']);
        });

        Schema::create('cod_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cod_reconciliation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->bigInteger('cod')->default(0);
            $table->bigInteger('fee')->default(0);
            $table->bigInteger('partner_cod')->default(0);
            $table->bigInteger('partner_fee')->default(0);
            // Money taken for the order when the reconciliation was completed.
            $table->bigInteger('paid')->default(0);

            $table->index(['tenant_id', 'cod_reconciliation_id']);
            $table->index(['tenant_id', 'shipment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cod_reconciliation_items');
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('cod_reconciliations');
        Schema::dropIfExists('carriers');
    }
};
