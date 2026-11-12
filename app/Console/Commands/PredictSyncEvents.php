<?php

namespace App\Console\Commands;

use App\Exceptions\PolymarketUnavailable;
use App\Services\Predict\Polymarket\EventImporter;
use Illuminate\Console\Command;

class PredictSyncEvents extends Command
{
    /**
     * @var string
     */
    protected $signature = 'predict:sync-events';

    /**
     * @var string
     */
    protected $description = 'Import open Polymarket events as draft prediction markets and refresh known ones';

    public function handle(EventImporter $importer): int
    {
        if (! config('predict.polymarket.enabled')) {
            $this->info('Polymarket import is off (PREDICT_POLYMARKET_ENABLED).');

            return self::SUCCESS;
        }

        try {
            $counts = $importer->sync();
        } catch (PolymarketUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Events %d; markets created %d, updated %d, closed %d, skipped %d.', $counts['events'], $counts['created'], $counts['updated'], $counts['closed'], $counts['skipped']));

        return self::SUCCESS;
    }
}
