<?php

namespace App\Console\Commands;

use App\Exceptions\PolymarketUnavailable;
use App\Models\PredictBet;
use App\Models\PredictMarket;
use App\Services\Predict\MarketService;
use App\Services\Predict\Polymarket\ReferencePriceRecorder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class PredictConvertLiveToPool extends Command
{
    /**
     * @var string
     */
    protected $signature = 'predict:convert-live-to-pool
                            {--force : Convert the markets; without it the command only counts them}
                            {--admin-user=ops-cli : Who the audit log names}';

    /**
     * @var string
     */
    protected $description = 'Move published fixed-odds imports nobody has bet on to our pool at Polymarket\'s current price (a dry run unless --force)';

    public function handle(MarketService $markets, ReferencePriceRecorder $prices): int
    {
        $count = $this->candidates()->count();

        if (! $this->option('force')) {
            $withBets = $this->candidates()->whereIn('id', PredictBet::select('predict_market_id'))->count();
            $this->info("{$count} published fixed-odds market(s) found; {$withBets} have bets and would stay on fixed odds. Run with --force to convert the rest.");

            return self::SUCCESS;
        }

        $converted = 0;
        $refused = [];
        $roundsLeftOpen = 0;

        try {
            $this->candidates()->with('outcomes')->chunkById(100, function ($chunk) use ($markets, $prices, &$converted, &$refused, &$roundsLeftOpen) {
                // The opening price must be fresh, so each batch is priced just before it moves
                $prices->refresh($chunk, keepHistory: false);

                foreach ($chunk as $market) {
                    $result = $markets->convertLiveToPool($market, (string) $this->option('admin-user'));

                    if ($result->isRefused()) {
                        $refused[$result->refusal['code']] = ($refused[$result->refusal['code']] ?? 0) + 1;

                        continue;
                    }

                    $converted++;
                    $roundsLeftOpen += $result->data['old_round_voided'] ? 0 : 1;
                }
            });
        } catch (PolymarketUnavailable) {
            $this->error("Polymarket is unavailable; stopped after converting {$converted} market(s). Run the command again.");

            return self::FAILURE;
        }

        $this->info("Converted {$converted} market(s) to the pool.");

        foreach ($refused as $code => $number) {
            $this->line("Left on fixed odds: {$number} ({$code})");
        }

        if ($roundsLeftOpen > 0) {
            $this->warn("{$roundsLeftOpen} old empty round(s) could not be voided; nothing points at them.");
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<PredictMarket>
     */
    private function candidates(): Builder
    {
        return PredictMarket::query()
            ->where('source', PredictMarket::SOURCE_POLYMARKET)
            ->where('engine', PredictMarket::ENGINE_REFERENCE)
            ->whereIn('status', [PredictMarket::STATUS_OPEN, PredictMarket::STATUS_SUSPENDED]);
    }
}
