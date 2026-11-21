<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rollups behind /stats/players, /stats/players/top, /stats/promotions and /stats/payments. Derived data:
 * php artisan stats:rebuild regenerates them with the other stats tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        // What each player staked, won and cost the house per product and day
        Schema::create('stats_daily_player_money', function (Blueprint $table) {
            $table->date('date');
            $table->foreignId('product_id')->constrained('products');
            $table->unsignedBigInteger('customer_id');
            $table->boolean('is_test');
            $table->decimal('turnover', 14, 2)->default(0);
            $table->decimal('payouts', 14, 2)->default(0);
            $table->decimal('ggr', 14, 2)->default(0);
            $table->integer('wins')->default(0);

            $table->primary(['date', 'product_id', 'customer_id']);
            $table->index(['date', 'is_test', 'customer_id']);
        });

        // Promotion cost per player, kind and product (product 0 for platform promotions)
        Schema::create('stats_daily_player_promotions', function (Blueprint $table) {
            $table->date('date');
            $table->string('kind', 30);
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('customer_id');
            $table->boolean('is_test');
            $table->decimal('amount', 14, 2)->default(0);
            $table->integer('items')->default(0);

            $table->primary(['date', 'kind', 'product_id', 'customer_id']);
            $table->index(['date', 'is_test', 'kind']);
        });

        Schema::create('stats_daily_channels', function (Blueprint $table) {
            $table->date('date');
            $table->boolean('excludes_test');
            $table->foreignId('product_id')->constrained('products');
            $table->string('channel', 20);
            $table->unsignedInteger('entries')->default(0);
            $table->decimal('stakes', 14, 2)->default(0);
            $table->unsignedInteger('players')->default(0);

            $table->primary(['date', 'excludes_test', 'product_id', 'channel']);
        });

        // Payment events per hour: what happened, how much, and how long it took when that is known
        Schema::create('stats_daily_payments', function (Blueprint $table) {
            $table->date('date');
            $table->unsignedTinyInteger('hour');
            $table->boolean('excludes_test');
            $table->string('kind', 30);
            $table->string('outcome', 30);
            $table->unsignedInteger('events')->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->unsignedBigInteger('seconds_total')->default(0);
            $table->unsignedInteger('seconds_count')->default(0);

            $table->primary(['date', 'excludes_test', 'kind', 'outcome', 'hour']);
        });

        // Withdrawals paid per player, beside the deposits already there
        Schema::table('stats_daily_depositors', function (Blueprint $table) {
            $table->unsignedInteger('withdrawals')->default(0)->after('is_first');
            $table->decimal('withdrawal_amount', 14, 2)->default(0)->after('withdrawals');
        });
    }

    public function down(): void
    {
        Schema::table('stats_daily_depositors', function (Blueprint $table) {
            $table->dropColumn(['withdrawals', 'withdrawal_amount']);
        });
        Schema::dropIfExists('stats_daily_payments');
        Schema::dropIfExists('stats_daily_channels');
        Schema::dropIfExists('stats_daily_player_promotions');
        Schema::dropIfExists('stats_daily_player_money');
    }
};
