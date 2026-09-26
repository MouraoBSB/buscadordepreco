<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('price_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_source_id')->constrained()->cascadeOnDelete();
            $table->decimal('regular_price', 10, 2)->nullable();
            $table->decimal('pix_price', 10, 2)->nullable();
            $table->decimal('shipping_price', 10, 2)->nullable();
            $table->decimal('installment_price', 10, 2)->nullable();
            $table->unsignedSmallInteger('installment_count')->nullable();
            $table->boolean('in_stock')->default(true);
            $table->string('seller')->nullable();
            $table->string('seller_type')->nullable(); // 1P, 3P, official
            $table->string('raw_title', 500)->nullable();
            $table->boolean('is_mismatch')->default(false);
            $table->string('mismatch_reason')->nullable();
            $table->timestamp('collected_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['product_source_id', 'collected_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_observations');
    }
};
