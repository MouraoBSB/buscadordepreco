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
            $table->decimal('coupon_price', 10, 2)->nullable()->after('pix_price');
            $table->foreignId('applied_coupon_id')->nullable()->after('coupon_price')->constrained('coupons')->nullOnDelete();
            $table->string('coupon_code')->nullable()->after('applied_coupon_id');
            $table->decimal('coupon_discount', 10, 2)->nullable()->after('coupon_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('price_observations', function (Blueprint $table) {
            $table->dropForeign(['applied_coupon_id']);
            $table->dropColumn(['coupon_price', 'applied_coupon_id', 'coupon_code', 'coupon_discount']);
        });
    }
};
