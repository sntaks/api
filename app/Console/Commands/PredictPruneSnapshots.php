<?php

namespace App\Console\Commands;

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
    protected $description = 'Delete reference price history older than predict.polymarket.snapshot_days';

    public function handle(): int
    {
        $deleted = PredictPriceSnapshot::where('captured_at', '<', now()->subDays((int) config('predict.polymarket.snapshot_days')))->delete();

        $this->info("Deleted {$deleted} price snapshot(s).");

        return self::SUCCESS;
    }
}
