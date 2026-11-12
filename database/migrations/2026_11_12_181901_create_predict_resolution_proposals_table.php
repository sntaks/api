<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A result Polymarket reported for an imported market, waiting for an admin to confirm (which pays out) or
     * reject it.
     */
    public function up(): void
    {
        Schema::create('predict_resolution_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('predict_market_id')->constrained('predict_markets');
            $table->string('result', 5);
            $table->json('evidence');
            $table->string('status', 20)->default('pending');
            $table->string('reviewed_by', 100)->nullable();
            $table->string('review_reason', 255)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['predict_market_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predict_resolution_proposals');
    }
};
