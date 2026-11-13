<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The polling feed (`GET /predict/updates`) asks every table that can move a market's figures for rows changed
     * after a time, so each gets an index on that time. `price_changed_at` is when an outcome's figures last
     * moved; `price_captured_at` also advances for an unchanged book.
     */
    public function up(): void
    {
        Schema::table('predict_outcomes', function (Blueprint $table) {
            $table->timestamp('price_changed_at')->nullable()->after('price_captured_at');
            $table->index('price_changed_at');
        });

        DB::table('predict_outcomes')->whereNotNull('price_captured_at')->update(['price_changed_at' => DB::raw('price_captured_at')]);

        foreach (['predict_markets', 'predict_pools', 'predict_exposures', 'predict_lmsr_states'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->index('updated_at'));
        }
    }

    public function down(): void
    {
        foreach (['predict_markets', 'predict_pools', 'predict_exposures', 'predict_lmsr_states'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropIndex(['updated_at']));
        }

        Schema::table('predict_outcomes', function (Blueprint $table) {
            $table->dropIndex(['price_changed_at']);
            $table->dropColumn('price_changed_at');
        });
    }
};
