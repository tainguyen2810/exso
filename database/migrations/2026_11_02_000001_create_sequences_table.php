<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Last number used per document type and store, for codes like PN00001.
     */
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 50);
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};
