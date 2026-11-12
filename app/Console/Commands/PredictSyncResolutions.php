<?php

namespace App\Console\Commands;

use App\Exceptions\PolymarketUnavailable;
use App\Services\Predict\Polymarket\ResolutionSync;
use Illuminate\Console\Command;

class PredictSyncResolutions extends Command
{
    /**
     * @var string
     */
    protected $signature = 'predict:sync-resolutions';

    /**
     * @var string
     */
    protected $description = 'Propose results for imported markets Polymarket has resolved (an admin confirms them)';

    public function handle(ResolutionSync $resolutions): int
    {
        if (! config('predict.polymarket.enabled')) {
            $this->info('Polymarket import is off (PREDICT_POLYMARKET_ENABLED).');

            return self::SUCCESS;
        }

        try {
            $counts = $resolutions->sync();
        } catch (PolymarketUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Checked %d market(s); proposed %d, auto-confirmed %d.', $counts['checked'], $counts['proposed'], $counts['confirmed']));

        return self::SUCCESS;
    }
}
