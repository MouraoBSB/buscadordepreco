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
        Schema::table('product_sources', function (Blueprint $table) {
            $table->string('status', 30)->default('active')->after('active'); // active, out_of_stock, temporarily_unavailable, removed, blocked
            $table->timestamp('last_checked_at')->nullable()->after('priority');
            $table->foreignId('discovery_candidate_id')->nullable()->after('last_checked_at')->constrained('discovery_candidates')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_sources', function (Blueprint $table) {
            $table->dropForeign(['discovery_candidate_id']);
            $table->dropColumn(['status', 'last_checked_at', 'discovery_candidate_id']);
        });
    }
};
