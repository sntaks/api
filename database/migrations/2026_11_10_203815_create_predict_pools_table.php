<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A pari-mutuel market's running totals, the row every bet locks. Totals include the house seed, which is
     * notional: it shapes the odds but never moves money.
     */
    public function up(): void
    {
        Schema::create('predict_pools', function (Blueprint $table) {
            $table->foreignId('predict_market_id')->primary()->constrained('predict_markets');
            $table->unsignedBigInteger('yes_minor');
            $table->unsignedBigInteger('no_minor');
            $table->unsignedBigInteger('seed_yes_minor');
            $table->unsignedBigInteger('seed_no_minor');
            $table->unsignedInteger('bets_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predict_pools');
    }
};
