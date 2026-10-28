<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sổ quỹ: receipt / payment vouchers (phiếu thu, phiếu chi) and their
     * types. Vouchers are never deleted, only cancelled; money in đồng.
     */
    public function up(): void
    {
        Schema::create('cash_voucher_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // receipt (loại phiếu thu) | payment (loại phiếu chi)
            $table->string('kind', 20);
            $table->string('code', 50);
            $table->string('name');
            // "Hạch toán kết quả kinh doanh": counted in the profit report.
            $table->boolean('affects_profit')->default(false);
            $table->string('description', 500)->nullable();
            // Built-in types (Tự động, Thu nợ khách hàng...) cannot be deleted.
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'kind', 'code']);
        });

        Schema::create('cash_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->string('kind', 20);
            // RVN (phiếu thu) / PVN (phiếu chi)
            $table->string('code', 50);
            $table->foreignId('type_id')->constrained('cash_voucher_types');
            // completed | cancelled
            $table->string('status', 20)->default('completed');
            // Created from another document (order payment, supplier payment).
            $table->boolean('is_auto')->default(false);
            // customer | supplier | staff | other
            $table->string('party_type', 20);
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('party_name');
            $table->bigInteger('amount');
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->string('method_name', 100);
            // cash | bank | ewallet | cod | points: which fund the money is in.
            $table->string('method_type', 20);
            $table->string('reference', 100)->nullable();
            // Record behind an automatic voucher (order payment, supplier
            // ledger line); one voucher per record.
            $table->nullableMorphs('origin');
            // Chứng từ gốc shown to the user (order, return, purchase receipt).
            $table->nullableMorphs('document');
            $table->string('document_code', 50)->nullable();
            $table->boolean('changes_debt')->default(false);
            $table->boolean('affects_profit')->default(false);
            $table->string('description', 1000)->nullable();
            $table->string('tags')->nullable();
            // Ngày ghi nhận: the day the money counts in the cash book.
            $table->timestamp('recorded_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'origin_type', 'origin_id']);
            $table->index(['tenant_id', 'branch_id', 'recorded_at']);
            $table->index(['tenant_id', 'kind', 'status']);
            $table->index(['tenant_id', 'party_type', 'party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_vouchers');
        Schema::dropIfExists('cash_voucher_types');
    }
};
