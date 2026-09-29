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
        Schema::table('products', function (Blueprint $table) {
            $table->string('model_code')->nullable()->change();
            $table->decimal('capacity_kg', 4, 1)->nullable()->change();
            $table->string('voltage', 10)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('model_code')->nullable(false)->change();
            $table->decimal('capacity_kg', 4, 1)->nullable(false)->change();
            $table->string('voltage', 10)->default('220V')->nullable(false)->change();
        });
    }
};
