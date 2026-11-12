<?php

namespace App\Console\Commands;

use App\Services\Predict\Polymarket\PriceSync;
use Illuminate\Console\Command;

class PredictSyncPrices extends Command
{
    /**
     * @var string
     */
    protected $signature = 'predict:sync-prices';

    /**
     * @var string
     */
    protected $description = 'Refresh Polymarket reference prices; suspend imported markets without a usable price';

    public function handle(PriceSync $prices): int
    {
        if (! config('predict.polymarket.enabled')) {
            $this->info('Polymarket import is off (PREDICT_POLYMARKET_ENABLED).');

            return self::SUCCESS;
        }

        $counts = $prices->sync();

        $this->info(sprintf('Markets %d; priced %d, suspended %d, resumed %d.', $counts['markets'], $counts['priced'], $counts['suspended'], $counts['resumed']));

        if ($counts['unavailable']) {
            $this->error('Polymarket was unavailable; open imported markets were suspended.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
