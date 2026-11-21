<?php

namespace App\Services\Stats;

use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\ReferralWithdrawal;
use App\Models\Wager;
use App\Models\WagerSettlement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-player rows of one day for the player statistics: what each player staked, won and cost the house per
 * product, and what promotions they received. House-banked money is the house wallet's side of each
 * settlement (as in the finance reports), tied to its player through the wager; reversals count on the day
 * they were made.
 */
class StatsPlayerRollup
{
    /** House-wallet entry type => what it adds to (its _reversal subtracts, through its debit/credit). */
    private const HOUSE_TYPES = [
        'wager_stake_settled' => 'turnover',
        'wager_payout' => 'payouts',
        'wager_bonus' => 'wager_bonus',
        'wager_free_bet_payout' => 'free_bet_winnings',
    ];

    /** Ledger entry types that pay Kadi winnings into a player wallet. */
    private const RAKE_PAYOUT_TYPES = ['game_payout', 'competition_payout'];

    /**
     * @param  list<array{product_id: int, customer_id: int, entries: int, stakes: float}>  $activity
     * @param  Collection<int, Product>  $products
     * @param  array<int, int>  $testIds  Test customer ids (flipped).
     * @return array{money: list<array<string, mixed>>, promotions: list<array<string, mixed>>}
     */
    public function build(CarbonImmutable $day, array $activity, $products, array $testIds): array
    {
        $date = $day->toDateString();
        $window = [$day->startOfDay(), $day->endOfDay()];
        $money = [];
        $promotions = [];

        $addMoney = function (int $productId, int $customerId, string $figure, float|int $amount) use (&$money) {
            $key = $productId.':'.$customerId;
            $money[$key] ??= ['product_id' => $productId, 'customer_id' => $customerId, 'turnover' => 0.0, 'payouts' => 0.0, 'wins' => 0];
            $money[$key][$figure] += $amount;
        };
        $addPromotion = function (string $kind, int $productId, int $customerId, float $amount, int $items) use (&$promotions) {
            $key = "{$kind}:{$productId}:{$customerId}";
            $promotions[$key] ??= ['kind' => $kind, 'product_id' => $productId, 'customer_id' => $customerId, 'amount' => 0.0, 'items' => 0];
            $promotions[$key]['amount'] += $amount;
            $promotions[$key]['items'] += $items;
        };

        $types = array_keys(self::HOUSE_TYPES);
        $house = DB::table('ledger_entries as l')
            ->join('wager_settlements as s', fn ($join) => $join->on('s.id', '=', 'l.referenceable_id')->where('l.referenceable_type', WagerSettlement::class))
            ->join('wagers as w', 'w.id', '=', 's.wager_id')
            ->where('l.wallet_type', LedgerEntry::WALLET_TYPE_WALLET)
            ->where('l.wallet_id', (int) config('wallets.house_wallet_id', 1))
            ->whereIn('l.entry_type', [...$types, ...array_map(fn (string $type) => $type.'_reversal', $types)])
            ->whereNotNull('l.product_id')
            ->whereNotNull('w.customer_id')
            ->whereBetween('l.created_at', $window)
            ->selectRaw('w.customer_id, l.product_id, l.entry_type, COUNT(*) as entries, SUM(l.credit - l.debit) as amount')
            ->groupBy('w.customer_id', 'l.product_id', 'l.entry_type')
            ->get();

        foreach ($house as $row) {
            $reversal = str_ends_with($row->entry_type, '_reversal');
            $figure = self::HOUSE_TYPES[str_replace('_reversal', '', $row->entry_type)];
            // amount is credit minus debit on the house wallet: stakes come in, everything else goes out
            $amount = $figure === 'turnover' ? (float) $row->amount : -(float) $row->amount;

            if ($figure === 'turnover' || $figure === 'payouts') {
                $addMoney((int) $row->product_id, (int) $row->customer_id, $figure, $amount);
            } else {
                $addPromotion($figure, (int) $row->product_id, (int) $row->customer_id, $amount, $reversal ? -(int) $row->entries : (int) $row->entries);
            }
        }

        // Won bets, and free bets settled won or lost (their face value), +1 when settled and -1 when reversed
        foreach (['created_at' => 1, 'reversed_at' => -1] as $column => $sign) {
            $settled = DB::table('wager_settlements as s')
                ->join('wagers as w', 'w.id', '=', 's.wager_id')
                ->where('w.is_tombstone', false)
                ->whereNotNull('w.customer_id')
                ->whereBetween("s.{$column}", $window)
                ->where('s.outcome', '!=', WagerSettlement::OUTCOME_VOID)
                ->selectRaw("w.customer_id, w.product_id, SUM(s.outcome = 'won') as wins, SUM(w.funding = ?) as free_bets, SUM(CASE WHEN w.funding = ? THEN w.free_bet_amount ELSE 0 END) as free_bet_value", [Wager::FUNDING_FREE_BET, Wager::FUNDING_FREE_BET])
                ->groupBy('w.customer_id', 'w.product_id')
                ->get();

            foreach ($settled as $row) {
                $addMoney((int) $row->product_id, (int) $row->customer_id, 'wins', $sign * (int) $row->wins);
                if ((int) $row->free_bets !== 0) {
                    $addPromotion('free_bet_value', (int) $row->product_id, (int) $row->customer_id, $sign * (float) $row->free_bet_value, $sign * (int) $row->free_bets);
                }
            }
        }

        // Kadi: stakes from the activity rows, winnings paid into the player wallet
        foreach ($activity as $player) {
            if (($products[$player['product_id']]->revenue_model ?? null) === Product::REVENUE_RAKE) {
                $addMoney($player['product_id'], $player['customer_id'], 'turnover', $player['stakes']);
            }
        }

        $rakePayouts = DB::table('ledger_entries as l')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('p.revenue_model', Product::REVENUE_RAKE)
            ->where('l.wallet_type', LedgerEntry::WALLET_TYPE_WALLET)
            ->whereIn('l.entry_type', self::RAKE_PAYOUT_TYPES)
            ->whereNotNull('l.customer_id')
            ->whereBetween('l.created_at', $window)
            ->selectRaw('l.customer_id, l.product_id, COUNT(*) as wins, SUM(l.credit - l.debit) as amount')
            ->groupBy('l.customer_id', 'l.product_id')
            ->get();

        foreach ($rakePayouts as $row) {
            $addMoney((int) $row->product_id, (int) $row->customer_id, 'payouts', (float) $row->amount);
            $addMoney((int) $row->product_id, (int) $row->customer_id, 'wins', (int) $row->wins);
        }

        // Platform promotions (product 0): promotion credits (gross cost), referral bonuses earned, referral payouts
        $platform = DB::table('promotion_credits')->whereBetween('created_at', $window)
            ->selectRaw('customer_id, promotion as kind, COUNT(*) as items, SUM(gross_amount) as amount')->groupBy('customer_id', 'promotion')
            ->unionAll(DB::table('referral_bonuses')->whereBetween('created_at', $window)
                ->selectRaw("customer_id, 'referral_bonus' as kind, COUNT(*) as items, SUM(amount) as amount")->groupBy('customer_id'))
            ->unionAll(DB::table('referral_withdrawals')->where('status', ReferralWithdrawal::STATUS_COMPLETED)->whereBetween('completed_at', $window)
                ->selectRaw("customer_id, 'referral_payout' as kind, COUNT(*) as items, SUM(amount) as amount")->groupBy('customer_id'))
            ->get();

        foreach ($platform as $row) {
            $addPromotion((string) $row->kind, 0, (int) $row->customer_id, (float) $row->amount, (int) $row->items);
        }

        $isTest = fn (int $customerId) => isset($testIds[$customerId]);

        return [
            'money' => array_values(array_map(function (array $row) use ($date, $products, $isTest) {
                $houseBanked = ($products[$row['product_id']]->revenue_model ?? null) === Product::REVENUE_HOUSE_BANKED;

                return [
                    'date' => $date,
                    'product_id' => $row['product_id'],
                    'customer_id' => $row['customer_id'],
                    'is_test' => $isTest($row['customer_id']),
                    'turnover' => round($row['turnover'], 2),
                    'payouts' => round($row['payouts'], 2),
                    'ggr' => $houseBanked ? round($row['turnover'] - $row['payouts'], 2) : 0.0,
                    'wins' => $row['wins'],
                ];
            }, $money)),
            'promotions' => array_values(array_map(fn (array $row) => [
                'date' => $date,
                'kind' => $row['kind'],
                'product_id' => $row['product_id'],
                'customer_id' => $row['customer_id'],
                'is_test' => $isTest($row['customer_id']),
                'amount' => round($row['amount'], 2),
                'items' => $row['items'],
            ], $promotions)),
        ];
    }
}
