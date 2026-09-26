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
        Schema::table('price_observations', function (Blueprint $table) {
            $table->boolean('is_suspicious')->default(false)->after('is_mismatch');
            $table->string('sanity_check_reason')->nullable()->after('is_suspicious');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('price_observations', function (Blueprint $table) {
            $table->dropColumn(['is_suspicious', 'sanity_check_reason']);
        });
    }
};
