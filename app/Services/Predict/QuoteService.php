<?php

namespace App\Services\Predict;

use App\Models\Customer;
use App\Models\PredictMarket;
use App\Models\PredictQuote;
use App\Services\Predict\Engines\EngineRegistry;

/**
 * Fixed-odds quotes: a multiplier held for one customer, side and stake for `predict.fixed.quote_seconds`.
 * The bet must send the quote's id; the engine checks it again under the market's locks.
 */
class QuoteService
{
    public function __construct(private BetService $bets, private EngineRegistry $engines) {}

    public function create(Customer $customer, PredictMarket $market, string $side, float|int|string $stake): PredictResult
    {
        if ($market->engine !== PredictMarket::ENGINE_REFERENCE) {
            return PredictResult::refused('quote_not_needed', 'Pool markets take bets without a quote; use the estimate', 409);
        }

        $stakeMinor = (int) round(((float) $stake) * 100);
        $refusal = $this->bets->refusalBeforeLock($customer, $market, $stakeMinor);

        if ($refusal) {
            return $refusal;
        }

        $terms = $this->engines->for($market)->quote($market, $side, $stakeMinor);

        if ($terms->isRefused()) {
            return PredictResult::refused($terms->refusalCode, $terms->refusalMessage, $terms->refusalStatus, $terms->extra);
        }

        $quote = PredictQuote::create([
            'predict_market_id' => $market->id,
            'customer_id' => $customer->id,
            'side' => $side,
            'stake_minor' => $stakeMinor,
            'p_fair' => $terms->extra['p_fair'],
            'p_offer' => $terms->extra['p_offer'],
            'multiplier_x100' => $terms->multiplierX100,
            'expires_at' => now()->addSeconds((int) config('predict.fixed.quote_seconds')),
        ]);

        return PredictResult::ok(['quote' => $quote, 'market' => $market], created: true);
    }
}
