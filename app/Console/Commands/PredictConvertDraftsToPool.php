<?php

namespace App\Console\Commands;

use App\Models\PredictMarket;
use App\Services\Predict\MarketService;
use Illuminate\Console\Command;

class PredictConvertDraftsToPool extends Command
{
    /**
     * @var string
     */
    protected $signature = 'predict:convert-drafts-to-pool
                            {--force : Convert the drafts; without it the command only counts them}
                            {--admin-user=ops-cli : Who the audit log names}';

    /**
     * @var string
     */
    protected $description = 'Move imported fixed-odds drafts to our pool with the import seed (a dry run unless --force)';

    public function handle(MarketService $markets): int
    {
        $drafts = PredictMarket::query()
            ->where('source', PredictMarket::SOURCE_POLYMARKET)
            ->where('engine', PredictMarket::ENGINE_REFERENCE)
            ->where('status', PredictMarket::STATUS_DRAFT);

        $count = $drafts->count();

        if (! $this->option('force')) {
            $this->info("{$count} imported fixed-odds draft(s) would move to the pool. Run with --force to convert them.");

            return self::SUCCESS;
        }

        $converted = 0;
        $refused = 0;

        $drafts->chunkById(200, function ($chunk) use ($markets, &$converted, &$refused) {
            foreach ($chunk as $market) {
                $markets->convertDraftToPool($market, (string) $this->option('admin-user'))->isRefused() ? $refused++ : $converted++;
            }
        });

        $this->info("Converted {$converted} draft(s) to the pool; {$refused} changed status meanwhile and were left alone.");

        return self::SUCCESS;
    }
}
