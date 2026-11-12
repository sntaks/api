<?php

namespace App\Console\Commands;

use App\Models\PredictMarket;
use App\Models\PredictPriceSnapshot;
use App\Models\PredictQuote;
use Illuminate\Console\Command;

class PredictPruneSnapshots extends Command
{
    /**
     * @var string
     */
    protected $signature = 'predict:prune-snapshots';

    /**
     * @var string
     */
    protected $description = 'Delete old reference price history (and any kept on drafts) and quotes older than a day';

    public function handle(): int
    {
        $deleted = PredictPriceSnapshot::where('captured_at', '<', now()->subDays((int) config('predict.polymarket.snapshot_days')))->delete();

        // History is only kept for markets players can see; drop any left on drafts and rejected markets
        $hidden = PredictPriceSnapshot::whereHas('outcome.market', fn ($market) => $market->whereIn('status', [PredictMarket::STATUS_DRAFT, PredictMarket::STATUS_REJECTED]))->delete();

        // Quotes live 10 seconds; a day of them is kept for support
        $quotes = PredictQuote::where('created_at', '<', now()->subDay())->delete();

        $this->info("Deleted {$deleted} old and {$hidden} draft price snapshot(s), and {$quotes} old quote(s).");

        return self::SUCCESS;
    }
}
