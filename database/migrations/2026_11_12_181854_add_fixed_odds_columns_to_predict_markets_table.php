<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fixed-odds terms of imported markets: the house margin, the most the house may lose on a side, the most one
     * customer may stake on the market, and the game start time that ends betting on sports markets.
     */
    public function up(): void
    {
        Schema::table('predict_markets', function (Blueprint $table) {
            $table->unsignedSmallInteger('margin_bp')->default(500)->after('cut_bp');
            $table->unsignedBigInteger('max_liability_minor')->default(50000000)->after('max_payout_minor');
            $table->unsignedBigInteger('max_customer_stake_minor')->default(2000000)->after('max_liability_minor');
            $table->timestamp('external_game_start_at')->nullable()->after('external_end_at');
        });
    }

    public function down(): void
    {
        Schema::table('predict_markets', function (Blueprint $table) {
            $table->dropColumn(['margin_bp', 'max_liability_minor', 'max_customer_stake_minor', 'external_game_start_at']);
        });
    }
};
