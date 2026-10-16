<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The bet shop a stake or competition entry was made at, when the game server sends `bet_shop_no`.
     * Recorded per stake, not per game: one game can mix players from different shops and online.
     */
    public function up(): void
    {
        Schema::table('game_transactions', function (Blueprint $table) {
            $table->foreignId('bet_shop_id')->nullable()->after('customer_id')->constrained('bet_shops');
        });

        Schema::table('competition_transactions', function (Blueprint $table) {
            $table->foreignId('bet_shop_id')->nullable()->after('customer_id')->constrained('bet_shops');
        });
    }

    public function down(): void
    {
        Schema::table('competition_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bet_shop_id');
        });

        Schema::table('game_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bet_shop_id');
        });
    }
};
