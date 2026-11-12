<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reference price history of imported markets' YES outcome, kept when the price moves or at a set interval,
     * and pruned after `predict.polymarket.snapshot_days`.
     */
    public function up(): void
    {
        Schema::create('predict_price_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('predict_outcome_id')->constrained('predict_outcomes')->cascadeOnDelete();
            $table->decimal('midpoint', 8, 6);
            $table->timestamp('captured_at');

            $table->index(['predict_outcome_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predict_price_snapshots');
    }
};
