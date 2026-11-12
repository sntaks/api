<?php

use App\Models\PredictMarket;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A fixed-odds market's running totals per side: the stakes taken and the payouts owed if that side wins.
     * Every fixed-odds bet locks both rows of its market.
     */
    public function up(): void
    {
        Schema::create('predict_exposures', function (Blueprint $table) {
            $table->foreignId('predict_market_id')->constrained('predict_markets');
            $table->string('side', 5);
            $table->unsignedBigInteger('stakes_minor')->default(0);
            $table->unsignedBigInteger('payouts_minor')->default(0);
            $table->timestamps();

            $table->primary(['predict_market_id', 'side']);
        });

        // Imported markets already published in phase 1b get their rows now; new ones get them when published
        $now = now();
        DB::table('predict_markets')
            ->where('engine', PredictMarket::ENGINE_REFERENCE)
            ->whereNotNull('opened_at')
            ->pluck('id')
            ->each(fn (int $id) => DB::table('predict_exposures')->insert([
                ['predict_market_id' => $id, 'side' => PredictMarket::SIDE_YES, 'created_at' => $now, 'updated_at' => $now],
                ['predict_market_id' => $id, 'side' => PredictMarket::SIDE_NO, 'created_at' => $now, 'updated_at' => $now],
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('predict_exposures');
    }
};
