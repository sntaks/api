<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One settlement or void run of a market, with the frozen totals it paid from. A failed run is retried
     * under the same row: the wager round only ever touches open, never-settled wagers.
     */
    public function up(): void
    {
        Schema::create('predict_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('predict_market_id')->constrained('predict_markets');
            $table->unsignedInteger('run_no');
            $table->string('kind', 20);
            $table->string('result', 5)->nullable();
            $table->string('status', 20);
            $table->unsignedBigInteger('yes_minor');
            $table->unsignedBigInteger('no_minor');
            $table->unsignedInteger('final_multiplier_x100')->nullable();
            $table->unsignedInteger('winners_count')->default(0);
            $table->unsignedBigInteger('user_stake_minor')->default(0);
            $table->unsignedBigInteger('payout_minor')->default(0);
            $table->bigInteger('house_net_minor')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->string('admin_user', 100);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['predict_market_id', 'run_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predict_settlements');
    }
};
