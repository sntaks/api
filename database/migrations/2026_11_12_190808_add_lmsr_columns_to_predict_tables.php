<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LMSR: the market's liquidity b (minor units), the payout a quote locks, and the payout (shares) a bet
     * bought.
     */
    public function up(): void
    {
        Schema::table('predict_markets', function (Blueprint $table) {
            $table->unsignedBigInteger('liquidity_minor')->nullable()->after('seed_minor');
        });

        Schema::table('predict_quotes', function (Blueprint $table) {
            $table->unsignedBigInteger('payout_minor')->nullable()->after('multiplier_x100');
        });

        Schema::table('predict_bets', function (Blueprint $table) {
            $table->unsignedBigInteger('shares_minor')->nullable()->after('estimated_multiplier_x100');
        });
    }

    public function down(): void
    {
        Schema::table('predict_markets', fn (Blueprint $table) => $table->dropColumn('liquidity_minor'));
        Schema::table('predict_quotes', fn (Blueprint $table) => $table->dropColumn('payout_minor'));
        Schema::table('predict_bets', fn (Blueprint $table) => $table->dropColumn('shares_minor'));
    }
};
