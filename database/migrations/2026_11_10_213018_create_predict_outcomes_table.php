<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The YES and NO outcome of an imported market, with its Polymarket token and current reference price.
     */
    public function up(): void
    {
        Schema::create('predict_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('predict_market_id')->constrained('predict_markets');
            $table->string('side', 5);
            $table->string('label', 100);
            $table->string('external_token_id', 100);
            $table->decimal('midpoint', 8, 6)->nullable();
            $table->boolean('price_reliable')->default(false);
            $table->timestamp('price_captured_at')->nullable();
            $table->timestamps();

            $table->unique(['predict_market_id', 'side']);
            $table->index('external_token_id');
            $table->index('price_captured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predict_outcomes');
    }
};
