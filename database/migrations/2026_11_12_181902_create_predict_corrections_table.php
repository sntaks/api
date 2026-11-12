<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A change of a settled market's result: requested by one admin, approved by another, then applied wager by
     * wager (reverse the old settlement, settle under the new result).
     */
    public function up(): void
    {
        Schema::create('predict_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('predict_market_id')->constrained('predict_markets');
            $table->string('from_result', 5);
            $table->string('to_result', 5);
            $table->string('reason', 255);
            $table->string('status', 20)->default('requested');
            $table->string('requested_by', 100);
            $table->string('approved_by', 100)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('wagers_corrected')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['predict_market_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predict_corrections');
    }
};
