<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shop API keys (scope `shop`, bound to one shop; existing keys stay `full`), excise duty and referral
     * first deposits from shop loads, and shop floats in the daily financial snapshot.
     */
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->string('scope', 20)->default('full')->after('name');
            $table->foreignId('bet_shop_id')->nullable()->after('scope')->constrained('bet_shops');
            $table->timestamp('revoked_at')->nullable()->after('is_active');
            $table->string('revoked_by', 100)->nullable()->after('revoked_at');
        });

        Schema::table('excise_duty_charges', function (Blueprint $table) {
            $table->foreignId('bet_shop_load_id')->nullable()->unique()->after('promotion_credit_id')->constrained('bet_shop_loads');
        });

        Schema::table('referrals', function (Blueprint $table) {
            $table->foreignId('first_bet_shop_load_id')->nullable()->after('first_deposit_id')->constrained('bet_shop_loads')->nullOnDelete();
        });

        Schema::table('financial_snapshots', function (Blueprint $table) {
            $table->decimal('bet_shop_floats_total', 14, 2)->default(0)->after('referral_wallets_total');
        });
    }

    public function down(): void
    {
        Schema::table('financial_snapshots', function (Blueprint $table) {
            $table->dropColumn('bet_shop_floats_total');
        });

        Schema::table('referrals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('first_bet_shop_load_id');
        });

        Schema::table('excise_duty_charges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bet_shop_load_id');
        });

        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bet_shop_id');
            $table->dropColumn(['scope', 'revoked_at', 'revoked_by']);
        });
    }
};
