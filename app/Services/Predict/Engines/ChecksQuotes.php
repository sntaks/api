<?php

namespace App\Services\Predict\Engines;

use App\Models\Customer;
use App\Models\PredictMarket;
use App\Models\PredictQuote;

/**
 * Quote checks shared by the engines that lock a price through a quote (fixed odds, LMSR).
 */
trait ChecksQuotes
{
    private function quoteRefusal(?PredictQuote $quote, PredictMarket $market, Customer $customer, string $side, int $stakeMinor): ?BetTerms
    {
        if ($quote === null || $quote->predict_market_id !== $market->id || $quote->customer_id !== $customer->id || $quote->side !== $side || $quote->stake_minor !== $stakeMinor) {
            return BetTerms::refused('quote_mismatch', 'The quote is not for this customer, market, side and stake', 409);
        }

        if ($quote->used_at !== null) {
            return BetTerms::refused('quote_used', 'This quote was already used; ask for a new one', 409);
        }

        if ($quote->isExpired()) {
            return BetTerms::refused('quote_expired', 'The quote expired; ask for a new one', 409);
        }

        return null;
    }

    private function quoteRequired(): BetTerms
    {
        return BetTerms::refused('quote_required', 'Ask for a quote first and bet with its quote_id', 422);
    }
}
