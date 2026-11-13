<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pool markets take their cut from the losing side only (decided 2026-11-13), so a winning bet always returns
     * more than its stake. Pool markets already resolved, settled or voided keep the cut on the whole pool, so a
     * correction pays them as before. Live pool markets still on the old default terms (cut 10%, minimum 1.05x)
     * move to the new ones (cut 20% of the losing side, minimum 1.01x), audited as `terms_update`; terms an admin
     * set by hand are left alone. Every live pool market's odds change, so each one's updated_at moves for the
     * polling feed.
     */
    public function up(): void
    {
        Schema::table('predict_markets', function (Blueprint $table) {
            $table->string('cut_on', 10)->default('winnings')->after('cut_bp');
        });

        DB::table('predict_markets')
            ->where('engine', 'pool')
            ->whereIn('status', ['resolved', 'settled', 'voided'])
            ->update(['cut_on' => 'pool']);

        $live = ['draft', 'open', 'suspended', 'closed'];
        $now = now();

        DB::table('predict_markets')
            ->where('engine', 'pool')
            ->whereIn('status', $live)
            ->where('cut_bp', 1000)
            ->where('min_multiplier_x100', 105)
            ->orderBy('id')
            ->chunkById(500, function ($markets) use ($now) {
                DB::table('predict_markets')->whereIn('id', $markets->pluck('id'))->update(['cut_bp' => 2000, 'min_multiplier_x100' => 101]);

                DB::table('predict_audit_logs')->insert($markets->map(fn ($market) => [
                    'predict_market_id' => $market->id,
                    'action' => 'terms_update',
                    'admin_user' => 'system',
                    'reason' => 'Pool cut moved to 20% of the losing side, minimum multiplier 1.01x',
                    'before' => json_encode(['cut_bp' => 1000, 'cut_on' => 'pool', 'min_multiplier_x100' => 105]),
                    'after' => json_encode(['cut_bp' => 2000, 'cut_on' => 'winnings', 'min_multiplier_x100' => 101]),
                    'created_at' => $now,
                ])->all());
            });

        DB::table('predict_markets')->where('engine', 'pool')->whereIn('status', $live)->update(['updated_at' => $now]);
    }

    /**
     * Drops the rule column. Terms moved by up() stay as they are; the audit log records the old values.
     */
    public function down(): void
    {
        Schema::table('predict_markets', function (Blueprint $table) {
            $table->dropColumn('cut_on');
        });
    }
};
