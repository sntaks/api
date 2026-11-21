<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily rollups behind /stats/dashboard, /stats/games and the player statistics. Derived data only:
 * php artisan stats:rebuild regenerates any day from the ledger and wagers. Aggregate tables hold two
 * variants of each row (excludes_test 1 and 0); per-player tables mark test customers with is_test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stats_daily_product', function (Blueprint $table) {
            $table->date('date');
            $table->foreignId('product_id')->constrained('products');
            $table->boolean('excludes_test');
            $table->integer('bets')->default(0);
            $table->integer('voids')->default(0);
            $table->decimal('turnover', 14, 2)->default(0);
            $table->decimal('payouts', 14, 2)->default(0);
            $table->decimal('ggr', 14, 2)->default(0);
            $table->decimal('promotions', 14, 2)->default(0);
            $table->decimal('free_bet_value', 14, 2)->default(0);
            $table->decimal('winnings_tax', 14, 2)->default(0);

            $table->primary(['date', 'excludes_test', 'product_id']);
        });

        Schema::create('stats_daily_player_product', function (Blueprint $table) {
            $table->date('date');
            $table->foreignId('product_id')->constrained('products');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('game_id');
            $table->boolean('is_test');
            $table->unsignedInteger('entries')->default(0);
            $table->decimal('stakes', 14, 2)->default(0);

            $table->primary(['date', 'product_id', 'customer_id']);
            $table->index(['date', 'is_test', 'game_id', 'customer_id'], 'stats_player_game_idx');
            $table->index(['date', 'is_test', 'product_id', 'customer_id', 'game_id'], 'stats_player_product_idx');
            $table->index(['customer_id', 'date']);
        });

        // Distinct players per day for the platform, each game and each product: a day's count never changes
        Schema::create('stats_daily_players', function (Blueprint $table) {
            $table->date('date');
            $table->boolean('excludes_test');
            $table->string('scope', 10);
            $table->unsignedBigInteger('scope_id');
            $table->unsignedInteger('players')->default(0);

            $table->primary(['date', 'excludes_test', 'scope', 'scope_id']);
        });

        Schema::create('stats_daily_platform', function (Blueprint $table) {
            $table->date('date');
            $table->boolean('excludes_test');
            $table->decimal('deposits', 14, 2)->default(0);
            $table->decimal('withdrawals', 14, 2)->default(0);
            $table->decimal('net_cash', 14, 2)->default(0);
            $table->unsignedInteger('new_players')->default(0);
            $table->unsignedInteger('first_depositors')->default(0);

            $table->primary(['date', 'excludes_test']);
        });

        Schema::create('stats_daily_depositors', function (Blueprint $table) {
            $table->date('date');
            $table->unsignedBigInteger('customer_id');
            $table->boolean('is_test');
            $table->unsignedInteger('deposits')->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->boolean('is_first')->default(false);

            $table->primary(['date', 'customer_id']);
        });

        Schema::create('stats_hourly_activity', function (Blueprint $table) {
            $table->date('date');
            $table->unsignedTinyInteger('hour');
            $table->string('scope', 10);
            $table->unsignedBigInteger('scope_id');
            $table->boolean('excludes_test');
            $table->decimal('stakes', 14, 2)->default(0);
            $table->unsignedInteger('players')->default(0);

            $table->primary(['date', 'excludes_test', 'scope', 'scope_id', 'hour']);
        });

        Schema::create('stats_rollup_days', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->timestamp('built_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stats_rollup_days');
        Schema::dropIfExists('stats_hourly_activity');
        Schema::dropIfExists('stats_daily_players');
        Schema::dropIfExists('stats_daily_depositors');
        Schema::dropIfExists('stats_daily_platform');
        Schema::dropIfExists('stats_daily_player_product');
        Schema::dropIfExists('stats_daily_product');
    }
};
