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
        Schema::create('api_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50)->index(); // e.g. tavily
            $table->string('purpose', 50)->index(); // profiling, discovery, direct_search
            $table->string('query', 500)->nullable();
            $table->unsignedInteger('credits_used')->default(1);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['provider', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_usage_logs');
    }
};
