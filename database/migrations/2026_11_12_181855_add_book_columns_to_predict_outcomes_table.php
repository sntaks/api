<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The order book behind the reference price: best bid and ask, the spread, and the USD resting on the ask side
     * near the best ask (what a buyer could take without moving the price much).
     */
    public function up(): void
    {
        Schema::table('predict_outcomes', function (Blueprint $table) {
            $table->decimal('best_bid', 8, 6)->nullable()->after('midpoint');
            $table->decimal('best_ask', 8, 6)->nullable()->after('best_bid');
            $table->decimal('spread', 8, 6)->nullable()->after('best_ask');
            $table->decimal('depth_usd', 20, 2)->nullable()->after('spread');
        });
    }

    public function down(): void
    {
        Schema::table('predict_outcomes', function (Blueprint $table) {
            $table->dropColumn(['best_bid', 'best_ask', 'spread', 'depth_usd']);
        });
    }
};
