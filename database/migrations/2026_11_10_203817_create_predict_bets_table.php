<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A player's bet on one side of a market. The money lives on the linked wager (stake, payout, tax, ledger);
     * this row keeps the market side and the odds.
     */
    public function up(): void
    {
        Schema::create('predict_bets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('predict_market_id')->constrained('predict_markets');
            $table->foreignId('wager_id')->unique()->constrained('wagers');
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('reference', 100);
            $table->string('side', 5);
            $table->char('currency', 3);
            $table->unsignedBigInteger('stake_minor');
            $table->unsignedInteger('estimated_multiplier_x100');
            $table->unsignedInteger('final_multiplier_x100')->nullable();
            $table->unsignedBigInteger('payout_minor')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('placed_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['predict_market_id', 'side']);
            $table->index(['customer_id', 'placed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predict_bets');
    }
};
