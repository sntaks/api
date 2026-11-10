<?php

namespace App\Console\Commands;

use App\Services\Predict\MarketService;
use Illuminate\Console\Command;

class PredictCloseDue extends Command
{
    /**
     * @var string
     */
    protected $signature = 'predict:close-due';

    /**
     * @var string
     */
    protected $description = 'Close every open or suspended prediction market whose close time has passed';

    public function handle(MarketService $markets): int
    {
        $closed = $markets->closeDue();

        $this->info("Closed {$closed} market(s).");

        return self::SUCCESS;
    }
}
