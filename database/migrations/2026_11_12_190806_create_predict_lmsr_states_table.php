<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An LMSR market's outstanding shares per side (each pays KES 1 if its side wins) and its liquidity b, in
     * KES. The row every LMSR bet locks.
     */
    public function up(): void
    {
        Schema::create('predict_lmsr_states', function (Blueprint $table) {
            $table->foreignId('predict_market_id')->primary()->constrained('predict_markets');
            $table->decimal('q_yes', 24, 6);
            $table->decimal('q_no', 24, 6);
            $table->decimal('liquidity', 16, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predict_lmsr_states');
    }
};
