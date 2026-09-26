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
        Schema::create('discovery_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 50);
            $table->string('trigger_type', 30)->default('manual'); // manual, recurring, profiling
            $table->string('status', 20)->default('running'); // running, completed, failed
            $table->json('queries_executed')->nullable();
            $table->unsignedInteger('candidates_found')->default(0);
            $table->unsignedInteger('candidates_auto_approved')->default(0);
            $table->unsignedInteger('candidates_pending')->default(0);
            $table->unsignedInteger('candidates_rejected')->default(0);
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discovery_runs');
    }
};
