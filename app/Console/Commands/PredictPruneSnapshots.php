<?php

namespace App\Console\Commands;

use App\Models\PredictMarket;
use App\Models\PredictPriceSnapshot;
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
    protected $description = 'Delete reference price history older than predict.polymarket.snapshot_days, and any kept on drafts';

    public function handle(): int
    {
        $deleted = PredictPriceSnapshot::where('captured_at', '<', now()->subDays((int) config('predict.polymarket.snapshot_days')))->delete();

        // History is only kept for markets players can see; drop any left on drafts and rejected markets
        $hidden = PredictPriceSnapshot::whereHas('outcome.market', fn ($market) => $market->whereIn('status', [PredictMarket::STATUS_DRAFT, PredictMarket::STATUS_REJECTED]))->delete();

        $this->info("Deleted {$deleted} old and {$hidden} draft price snapshot(s).");

        return self::SUCCESS;
    }
}
