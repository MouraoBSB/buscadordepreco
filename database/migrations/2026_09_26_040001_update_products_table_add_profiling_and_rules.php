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
            $table->string('commercial_name')->nullable()->after('name');
            $table->json('hard_constraints')->nullable()->after('metadata');
            $table->json('inferred_attributes')->nullable()->after('hard_constraints');
            $table->json('required_terms')->nullable()->after('inferred_attributes');
            $table->json('forbidden_terms')->nullable()->after('required_terms');
            $table->boolean('strict_model')->default(false)->after('forbidden_terms');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'commercial_name',
                'hard_constraints',
                'inferred_attributes',
                'required_terms',
                'forbidden_terms',
                'strict_model',
            ]);
        });
    }
};
