<?php

namespace App\Services\Predict;

use App\Models\Customer;
use App\Models\PredictBet;
use App\Models\PredictMarket;
use App\Models\RiskEvent;

/**
 * Insider and abuse rules run after each prediction bet. They only flag: each writes a RiskEvent (types
 * `predict_*`) for GMS to review, and the bet stands. An admin can then block the player on the market.
 * Thresholds are in `predict.risk`. Each flag is written once per subject and window, never per bet.
 */
class RiskService
{
    public const TYPE_LATE_LARGE = 'predict_late_large_bet';

    public const TYPE_FRESH_ACCOUNT = 'predict_fresh_account';

    public const TYPE_CLUSTER = 'predict_side_cluster';

    public const TYPE_SHARP_WINNER = 'predict_sharp_winner';

    public const TYPES = [self::TYPE_LATE_LARGE, self::TYPE_FRESH_ACCOUNT, self::TYPE_CLUSTER, self::TYPE_SHARP_WINNER];

    /**
     * @return list<RiskEvent>
     */
    public function check(PredictBet $bet, PredictMarket $market, Customer $customer, ?string $ip = null): array
    {
        return array_values(array_filter([
            $this->lateLarge($bet, $market, $customer, $ip),
            $this->freshAccount($bet, $market, $customer, $ip),
            $this->cluster($bet, $market, $customer, $ip),
            $this->sharpWinner($customer, $ip),
        ]));
    }

    /**
     * A large bet close to the betting cut-off: someone may know the result.
     */
    private function lateLarge(PredictBet $bet, PredictMarket $market, Customer $customer, ?string $ip): ?RiskEvent
    {
        $rule = config('predict.risk.late_large');
        $minutesLeft = now()->diffInMinutes($market->bettingClosesAt(), false);

        if ($bet->stake_minor < $this->minor($rule['min_stake']) || $minutesLeft > $rule['minutes_before_cutoff']) {
            return null;
        }

        return $this->flag(self::TYPE_LATE_LARGE, 'medium', 30, $customer, $ip, [
            'market_id' => $market->id, 'bet_id' => $bet->id, 'side' => $bet->side, 'stake' => $bet->stake_minor / 100,
            'minutes_before_cutoff' => max(0, (int) floor($minutesLeft)),
        ]);
    }

    /**
     * A new account putting real money on one market, once per customer and market.
     */
    private function freshAccount(PredictBet $bet, PredictMarket $market, Customer $customer, ?string $ip): ?RiskEvent
    {
        $rule = config('predict.risk.fresh_account');

        if ($customer->created_at === null || $customer->created_at->lt(now()->subDays($rule['max_age_days']))) {
            return null;
        }

        $staked = (int) PredictBet::where('predict_market_id', $market->id)->where('customer_id', $customer->id)
            ->where('status', '!=', PredictBet::STATUS_VOID)->sum('stake_minor');

        if ($staked < $this->minor($rule['min_market_stake']) || $this->alreadyFlagged(self::TYPE_FRESH_ACCOUNT, $market->id, $customer->id)) {
            return null;
        }

        return $this->flag(self::TYPE_FRESH_ACCOUNT, 'medium', 30, $customer, $ip, [
            'market_id' => $market->id, 'stake_on_market' => $staked / 100, 'registered_at' => $customer->created_at->toIso8601String(),
        ]);
    }

    /**
     * Many customers backing the same side with sizeable stakes in a short window: coordinated or informed money.
     * Flagged once per market side and window.
     */
    private function cluster(PredictBet $bet, PredictMarket $market, Customer $customer, ?string $ip): ?RiskEvent
    {
        $rule = config('predict.risk.cluster');
        $since = now()->subMinutes($rule['window_minutes']);

        $customers = PredictBet::where('predict_market_id', $market->id)
            ->where('side', $bet->side)
            ->where('stake_minor', '>=', $this->minor($rule['min_stake']))
            ->where('placed_at', '>=', $since)
            ->distinct()
            ->pluck('customer_id');

        if ($customers->count() < $rule['min_customers']) {
            return null;
        }

        $recent = RiskEvent::where('event_type', self::TYPE_CLUSTER)
            ->where('details->market_id', $market->id)
            ->where('details->side', $bet->side)
            ->where('created_at', '>=', $since)
            ->exists();

        if ($recent) {
            return null;
        }

        return $this->flag(self::TYPE_CLUSTER, 'high', 40, $customer, $ip, [
            'market_id' => $market->id, 'side' => $bet->side, 'customer_ids' => $customers->values()->all(),
            'window_minutes' => $rule['window_minutes'],
        ]);
    }

    /**
     * A customer winning far more often than chance over enough settled bets. Flagged at most once a day.
     */
    private function sharpWinner(Customer $customer, ?string $ip): ?RiskEvent
    {
        $rule = config('predict.risk.sharp_winner');
        $settled = PredictBet::where('customer_id', $customer->id)->whereIn('status', [PredictBet::STATUS_WON, PredictBet::STATUS_LOST]);
        $total = (clone $settled)->count();

        if ($total < $rule['min_settled']) {
            return null;
        }

        $won = (clone $settled)->where('status', PredictBet::STATUS_WON)->count();

        if ($won < $total * $rule['min_win_rate']) {
            return null;
        }

        $recent = RiskEvent::where('event_type', self::TYPE_SHARP_WINNER)->where('customer_id', $customer->id)
            ->where('created_at', '>=', now()->subDay())->exists();

        return $recent ? null : $this->flag(self::TYPE_SHARP_WINNER, 'medium', 30, $customer, $ip, [
            'settled_bets' => $total, 'won' => $won, 'win_rate' => round($won / $total, 4),
        ]);
    }

    private function alreadyFlagged(string $type, int $marketId, int $customerId): bool
    {
        return RiskEvent::where('event_type', $type)->where('customer_id', $customerId)->where('details->market_id', $marketId)->exists();
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function flag(string $type, string $severity, int $score, Customer $customer, ?string $ip, array $details): RiskEvent
    {
        return RiskEvent::create([
            'ip_address' => (string) $ip,
            'customer_id' => $customer->id,
            'event_type' => $type,
            'severity' => $severity,
            'score' => $score,
            'details' => $details,
            'action_taken' => 'flagged',
            'created_at' => now(),
        ]);
    }

    private function minor(float|int|string $kes): int
    {
        return (int) round(((float) $kes) * 100);
    }
}
