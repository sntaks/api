<?php

namespace App\Services\Stats;

use App\Models\GameTransaction;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Services\FinanceDateRange;
use App\Services\FinanceReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Builds the daily stats rollups from the ledger and wagers. Derived data: a day can be rebuilt at any time
 * and rebuilding gives the same rows (delete then insert in one transaction).
 *
 * Money per product comes from FinanceReportService::productDayFigures, so it equals the income statement and
 * the house-banked report. Activity (who played) comes from wagers placed and, for the Kadi rake products,
 * stakes taken from player wallets (game_bet and competition_bet, less refunds).
 */
class StatsRollupService
{
    public const TABLES = ['stats_daily_product', 'stats_daily_player_product', 'stats_daily_platform', 'stats_daily_depositors', 'stats_hourly_activity', 'stats_daily_players',
        'stats_daily_player_money', 'stats_daily_player_promotions', 'stats_daily_channels', 'stats_daily_payments'];

    /** Ledger entry types that take a Kadi stake from a player wallet; refund gives one back. */
    private const RAKE_STAKE_TYPES = ['game_bet', 'competition_bet'];

    /** Ledger entry types that put a player's deposit in their wallet (M-Pesa, bet shop load). */
    private const DEPOSIT_TYPES = ['deposit', 'shop_load'];

    public function __construct(
        private FinanceReportService $finance,
        private StatsPlayerRollup $playerRollup,
        private StatsPaymentRollup $paymentRollup,
    ) {}

    /**
     * Rebuild every day from $from to $to (inclusive) and return the number of days built.
     */
    public function rebuildRange(CarbonImmutable $from, CarbonImmutable $to, ?callable $progress = null): int
    {
        $days = 0;

        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $this->rebuild($day);
            $days++;

            if ($progress) {
                $progress($day);
            }
        }

        return $days;
    }

    public function rebuild(CarbonImmutable $day): void
    {
        $day = $day->setTimezone(config('app.timezone'))->startOfDay();
        $date = $day->toDateString();

        Cache::lock('stats:rollup:'.$date, 120)->block(60, function () use ($day, $date) {
            $rows = $this->build($day);

            DB::transaction(function () use ($date, $rows) {
                foreach (self::TABLES as $table) {
                    DB::table($table)->where('date', $date)->delete();

                    foreach (array_chunk($rows[$table], 1000) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                }

                DB::table('stats_rollup_days')->upsert([['date' => $date, 'built_at' => now()]], ['date'], ['built_at']);
            });
        });
    }

    /**
     * Make sure every day of the window has a rollup that can be trusted: today no older than
     * stats.today_refresh_seconds, earlier days built after they ended. Up to stats.on_demand_days such days
     * are built now; any others are returned so the answer can warn about them.
     *
     * @return list<string> Days still missing or out of date.
     */
    public function ensureFresh(FinanceDateRange $range): array
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $last = $range->to->startOfDay()->min($today);
        $built = DB::table('stats_rollup_days')
            ->whereBetween('date', [$range->from->toDateString(), $last->toDateString()])
            ->pluck('built_at', 'date')
            ->map(fn ($at) => CarbonImmutable::parse($at, config('app.timezone')));

        $stale = [];
        for ($day = $range->from->startOfDay(); $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $at = $built[$day->toDateString()] ?? null;
            $fresh = $day->equalTo($today)
                ? $at !== null && $at->addSeconds((int) config('stats.today_refresh_seconds'))->isFuture()
                : $at !== null && $at->greaterThanOrEqualTo($day->addDay());

            if (! $fresh) {
                $stale[] = $day;
            }
        }

        $budget = (int) config('stats.on_demand_days');
        $missing = [];
        foreach ($stale as $day) {
            if ($budget-- > 0) {
                $this->rebuild($day);
            } else {
                $missing[] = $day->toDateString();
            }
        }

        return $missing;
    }

    /**
     * When the window's last day (today at the latest) was built: how fresh the answer is. Earlier days are
     * complete once built after they ended, so they never make an answer staler.
     */
    public function builtAt(FinanceDateRange $range): ?CarbonImmutable
    {
        $last = $range->to->startOfDay()->min(CarbonImmutable::today(config('app.timezone')));
        $at = DB::table('stats_rollup_days')->where('date', $last->toDateString())->value('built_at');

        return $at === null ? null : CarbonImmutable::parse($at, config('app.timezone'));
    }

    /**
     * The rollup rows of one day, per table.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function build(CarbonImmutable $day): array
    {
        $date = $day->toDateString();
        $testIds = array_flip(array_map('intval', config('finance.test_customer_ids')));
        $products = Product::query()->get(['id', 'game_id', 'revenue_model'])->keyBy('id');
        $activity = $this->activityRows($day);
        $depositors = $this->depositorRows($day);
        $playerRows = $this->playerRollup->build($day, $activity['players'], $products, $testIds);
        $payments = $this->paymentRollup->build($day, $testIds);

        $rows = array_fill_keys(self::TABLES, []);
        $rows['stats_daily_player_money'] = $playerRows['money'];
        $rows['stats_daily_player_promotions'] = $playerRows['promotions'];
        $rows['stats_daily_payments'] = $payments['payments'];

        foreach ($activity['players'] as $player) {
            $rows['stats_daily_player_product'][] = [
                'date' => $date,
                'product_id' => $player['product_id'],
                'customer_id' => $player['customer_id'],
                'game_id' => (int) $products[$player['product_id']]->game_id,
                'is_test' => isset($testIds[$player['customer_id']]),
                'entries' => $player['entries'],
                'stakes' => round($player['stakes'], 2),
            ];
        }

        foreach ([true, false] as $excludesTest) {
            $range = new FinanceDateRange($day->startOfDay(), $day->endOfDay(), FinanceDateRange::GROUP_BY_DAY, $excludesTest);
            $money = $this->finance->productDayFigures($range)[$date] ?? [];

            // Kadi rake products: bets are entries and turnover is stakes, from the activity rows
            $rake = [];
            foreach ($activity['players'] as $player) {
                if ($excludesTest && isset($testIds[$player['customer_id']])) {
                    continue;
                }
                if (($products[$player['product_id']]->revenue_model ?? null) === Product::REVENUE_RAKE) {
                    $rake[$player['product_id']]['bets'] = ($rake[$player['product_id']]['bets'] ?? 0) + $player['entries'];
                    $rake[$player['product_id']]['turnover'] = ($rake[$player['product_id']]['turnover'] ?? 0.0) + $player['stakes'];
                }
            }

            foreach (array_unique([...array_keys($money), ...array_keys($rake)]) as $productId) {
                $figures = $money[$productId] ?? [];
                $isRake = ($products[$productId]->revenue_model ?? null) === Product::REVENUE_RAKE;

                $rows['stats_daily_product'][] = [
                    'date' => $date,
                    'product_id' => $productId,
                    'excludes_test' => $excludesTest,
                    'bets' => $isRake ? ($rake[$productId]['bets'] ?? 0) : ($figures['bets'] ?? 0),
                    'voids' => $isRake ? 0 : ($figures['voids'] ?? 0),
                    'turnover' => round($isRake ? ($rake[$productId]['turnover'] ?? 0.0) : ($figures['turnover'] ?? 0.0), 2),
                    'payouts' => round($isRake ? 0.0 : ($figures['payouts'] ?? 0.0), 2),
                    'ggr' => round($figures['revenue'] ?? 0.0, 2),
                    'promotions' => round($figures['promotions'] ?? 0.0, 2),
                    'free_bet_value' => round($figures['free_bet_value'] ?? 0.0, 2),
                    'winnings_tax' => round($figures['winnings_tax'] ?? 0.0, 2),
                ];
            }

            $rows['stats_daily_platform'][] = ['date' => $date, 'excludes_test' => $excludesTest] + $this->platformFigures($range, $depositors, $excludesTest ? $testIds : []);

            foreach ($this->dailyPlayerRows($activity['players'], $products, $excludesTest ? $testIds : []) as $players) {
                $rows['stats_daily_players'][] = ['date' => $date, 'excludes_test' => $excludesTest] + $players;
            }

            foreach ($this->hourlyRows($activity['hourly'], $products, $excludesTest ? $testIds : []) as $hourly) {
                $rows['stats_hourly_activity'][] = ['date' => $date, 'excludes_test' => $excludesTest] + $hourly;
            }

            foreach ($this->channelRows($activity['hourly'], $excludesTest ? $testIds : []) as $channel) {
                $rows['stats_daily_channels'][] = ['date' => $date, 'excludes_test' => $excludesTest] + $channel;
            }
        }

        // One row per customer with money in or out of their wallet: deposits and loads, and withdrawals paid
        $cash = [];
        foreach ($depositors as $depositor) {
            $cash[(int) $depositor->customer_id] = [
                'deposits' => (int) $depositor->deposits,
                'amount' => round((float) $depositor->amount, 2),
                'is_first' => (bool) $depositor->is_first,
            ];
        }
        foreach ($payments['withdrawals'] as $customerId => $paid) {
            $cash[$customerId] = ($cash[$customerId] ?? ['deposits' => 0, 'amount' => 0.0, 'is_first' => false]) + $paid;
        }
        foreach ($cash as $customerId => $figures) {
            $rows['stats_daily_depositors'][] = [
                'date' => $date,
                'customer_id' => $customerId,
                'is_test' => isset($testIds[$customerId]),
                'deposits' => $figures['deposits'],
                'amount' => $figures['amount'],
                'is_first' => $figures['is_first'],
                'withdrawals' => $figures['withdrawals'] ?? 0,
                'withdrawal_amount' => round($figures['withdrawal_amount'] ?? 0.0, 2),
            ];
        }

        return $rows;
    }

    /**
     * Who played what on the day: wagers placed (tombstones are not bets) and Kadi stakes taken from player
     * wallets, per customer and product, and the same per hour.
     *
     * @return array{players: list<array{product_id: int, customer_id: int, entries: int, stakes: float}>, hourly: list<object{hour: int, product_id: int, customer_id: int, stakes: string}>}
     */
    private function activityRows(CarbonImmutable $day): array
    {
        $window = [$day->startOfDay(), $day->endOfDay()];

        $wagers = DB::table('wagers')
            ->where('is_tombstone', false)
            ->whereNotNull('customer_id')
            ->whereBetween('placed_at', $window)
            ->selectRaw("HOUR(placed_at) as hour, product_id, customer_id, COALESCE(NULLIF(channel, ''), 'unspecified') as channel, COUNT(*) as entries, SUM(stake) as stakes")
            ->groupByRaw("HOUR(placed_at), product_id, customer_id, COALESCE(NULLIF(channel, ''), 'unspecified')");

        $kadiChannel = "CASE WHEN gt.bet_shop_id IS NOT NULL THEN 'bet_shop' ELSE 'game_server' END";
        $kadi = DB::table('ledger_entries as l')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('game_transactions as gt', fn ($join) => $join->on('gt.id', '=', 'l.referenceable_id')->where('l.referenceable_type', GameTransaction::class))
            ->where('p.revenue_model', Product::REVENUE_RAKE)
            ->where('l.wallet_type', LedgerEntry::WALLET_TYPE_WALLET)
            ->whereIn('l.entry_type', [...self::RAKE_STAKE_TYPES, 'refund'])
            ->whereNotNull('l.customer_id')
            ->whereBetween('l.created_at', $window)
            ->selectRaw("HOUR(l.created_at) as hour, l.product_id, l.customer_id, {$kadiChannel} as channel, SUM(CASE WHEN l.entry_type = 'refund' THEN -1 ELSE 1 END) as entries, SUM(l.debit - l.credit) as stakes")
            ->groupByRaw("HOUR(l.created_at), l.product_id, l.customer_id, {$kadiChannel}");

        $hourly = $wagers->unionAll($kadi)->get();

        $players = [];
        foreach ($hourly as $row) {
            $key = $row->product_id.':'.$row->customer_id;
            $players[$key] ??= ['product_id' => (int) $row->product_id, 'customer_id' => (int) $row->customer_id, 'entries' => 0, 'stakes' => 0.0];
            $players[$key]['entries'] += (int) $row->entries;
            $players[$key]['stakes'] += (float) $row->stakes;
        }

        return ['players' => array_values($players), 'hourly' => $hourly->all()];
    }

    /**
     * Entries, stakes and distinct players per product and channel: the wager channel the game sent
     * (unspecified when none), and for Kadi bet_shop (a stake made at a shop) or game_server.
     *
     * @param  list<object>  $hourly
     * @param  array<int, int>  $excluded  Customer ids to leave out (flipped).
     * @return list<array{product_id: int, channel: string, entries: int, stakes: float, players: int}>
     */
    private function channelRows(array $hourly, array $excluded): array
    {
        $cells = [];
        foreach ($hourly as $row) {
            if (isset($excluded[(int) $row->customer_id])) {
                continue;
            }

            $key = $row->product_id.':'.$row->channel;
            $cells[$key] ??= ['product_id' => (int) $row->product_id, 'channel' => mb_substr((string) $row->channel, 0, 20), 'entries' => 0, 'stakes' => 0.0, 'players' => []];
            $cells[$key]['entries'] += (int) $row->entries;
            $cells[$key]['stakes'] += (float) $row->stakes;
            $cells[$key]['players'][(int) $row->customer_id] = true;
        }

        return array_values(array_map(fn (array $cell) => [...$cell, 'stakes' => round($cell['stakes'], 2), 'players' => count($cell['players'])], $cells));
    }

    /**
     * Distinct players of the day for the whole platform, each game and each product.
     *
     * @param  list<array{product_id: int, customer_id: int, entries: int, stakes: float}>  $players
     * @param  Collection<int, Product>  $products
     * @param  array<int, int>  $excluded  Customer ids to leave out (flipped).
     * @return list<array{scope: string, scope_id: int, players: int}>
     */
    private function dailyPlayerRows(array $players, $products, array $excluded): array
    {
        $sets = [];
        foreach ($players as $player) {
            if (isset($excluded[$player['customer_id']])) {
                continue;
            }

            $sets['all:0'][$player['customer_id']] = true;
            $sets['game:'.$products[$player['product_id']]->game_id][$player['customer_id']] = true;
            $sets['product:'.$player['product_id']][$player['customer_id']] = true;
        }

        $rows = [];
        foreach ($sets as $key => $customers) {
            [$scope, $scopeId] = explode(':', $key);
            $rows[] = ['scope' => $scope, 'scope_id' => (int) $scopeId, 'players' => count($customers)];
        }

        return $rows;
    }

    /**
     * Stakes and distinct players per hour for the whole platform, each game and each product.
     *
     * @param  list<object>  $hourly
     * @param  Collection<int, Product>  $products
     * @param  array<int, int>  $excluded  Customer ids to leave out (flipped).
     * @return list<array{hour: int, scope: string, scope_id: int, stakes: float, players: int}>
     */
    private function hourlyRows(array $hourly, $products, array $excluded): array
    {
        $cells = [];

        foreach ($hourly as $row) {
            $customerId = (int) $row->customer_id;
            if (isset($excluded[$customerId])) {
                continue;
            }

            $scopes = ['all' => 0, 'game' => (int) $products[$row->product_id]->game_id, 'product' => (int) $row->product_id];
            foreach ($scopes as $scope => $scopeId) {
                $key = "{$row->hour}:{$scope}:{$scopeId}";
                $cells[$key] ??= ['hour' => (int) $row->hour, 'scope' => $scope, 'scope_id' => $scopeId, 'stakes' => 0.0, 'players' => []];
                $cells[$key]['stakes'] += (float) $row->stakes;
                $cells[$key]['players'][$customerId] = true;
            }
        }

        return array_values(array_map(fn (array $cell) => [...$cell, 'stakes' => round($cell['stakes'], 2), 'players' => count($cell['players'])], $cells));
    }

    /**
     * Cash in and out as the cash-flow report has them, and sign-ups and first deposits.
     *
     * @param  list<object>  $depositors
     * @param  array<int, int>  $excluded  Customer ids to leave out (flipped).
     * @return array{deposits: float, withdrawals: float, net_cash: float, new_players: int, first_depositors: int}
     */
    private function platformFigures(FinanceDateRange $range, array $depositors, array $excluded): array
    {
        $cash = $this->finance->cashFlow($range)['totals'];

        $newPlayers = DB::table('customers')
            ->whereBetween('created_at', [$range->from, $range->to])
            ->where('id', '!=', (int) config('wallets.house_wallet_id', 1))
            ->when($excluded !== [], fn ($query) => $query->whereNotIn('id', array_keys($excluded)))
            ->count();

        $firstDepositors = collect($depositors)
            ->filter(fn ($row) => $row->is_first && ! isset($excluded[(int) $row->customer_id]))
            ->count();

        return [
            'deposits' => round($cash['cash_in']['wallet_deposit'] + $cash['cash_in']['load'], 2),
            'withdrawals' => round($cash['cash_out']['paid'], 2),
            'net_cash' => round($cash['net_cash'], 2),
            'new_players' => $newPlayers,
            'first_depositors' => $firstDepositors,
        ];
    }

    /**
     * Customers whose wallet received a deposit or a bet shop load on the day, and whether it was their first.
     *
     * @return list<object{customer_id: int, deposits: int, amount: string, is_first: int}>
     */
    private function depositorRows(CarbonImmutable $day): array
    {
        return DB::table('ledger_entries as l')
            ->where('l.wallet_type', LedgerEntry::WALLET_TYPE_WALLET)
            ->whereIn('l.entry_type', self::DEPOSIT_TYPES)
            ->where('l.credit', '>', 0)
            ->whereNotNull('l.customer_id')
            ->whereBetween('l.created_at', [$day->startOfDay(), $day->endOfDay()])
            ->selectRaw('l.customer_id, COUNT(*) as deposits, SUM(l.credit) as amount')
            ->selectRaw('NOT EXISTS (SELECT 1 FROM ledger_entries e WHERE e.customer_id = l.customer_id AND e.wallet_type = ? AND e.entry_type IN (?, ?) AND e.credit > 0 AND e.created_at < ?) as is_first', [
                LedgerEntry::WALLET_TYPE_WALLET, ...self::DEPOSIT_TYPES, $day->startOfDay(),
            ])
            ->groupBy('l.customer_id')
            ->get()
            ->all();
    }
}
