<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A fixed-odds price offered to one customer for one stake, valid for a few seconds and usable once.
     */
    public function up(): void
    {
        Schema::create('predict_quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('predict_market_id')->constrained('predict_markets');
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('side', 5);
            $table->unsignedBigInteger('stake_minor');
            $table->decimal('p_fair', 8, 6);
            $table->decimal('p_offer', 8, 6);
            $table->unsignedInteger('multiplier_x100');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('predict_bet_id')->nullable()->constrained('predict_bets');
            $table->timestamp('created_at')->nullable();

            $table->index(['customer_id', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predict_quotes');
    }
};
