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
        Schema::create('discovery_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('discovered_store_name')->nullable();
            $table->string('discovered_store_domain')->nullable()->index();
            $table->text('url');
            $table->string('url_hash', 64)->index();
            $table->string('raw_title', 500);
            $table->string('detected_model')->nullable();
            $table->decimal('detected_price', 10, 2)->nullable();
            $table->string('seller')->nullable();
            $table->string('seller_type')->nullable();
            $table->unsignedTinyInteger('confidence_score')->default(0);
            $table->json('scoring_breakdown')->nullable();
            $table->string('status', 30)->default('pending_review'); // pending_review, approved, auto_approved, rejected
            $table->string('rejection_reason')->nullable();
            $table->string('discovery_provider', 50)->nullable();
            $table->foreignId('product_source_id')->nullable()->constrained('product_sources')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('discovered_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'url_hash'], 'prod_url_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discovery_candidates');
    }
};
