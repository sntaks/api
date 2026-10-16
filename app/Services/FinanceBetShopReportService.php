<?php

namespace App\Services;

use App\Models\BetShopLoad;
use App\Models\ShopWithdrawal;
use App\Services\Concerns\BuildsFinanceQueries;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Finance reports for bet shops: what each shop did in a range (float bought and paid back, cash loads,
 * cash withdrawals, play attributed to it and the gross gaming revenue from it), and the loads and
 * withdrawals behind it.
 *
 * Play is attributed per player and game: a player's stakes in a game (or entries in a competition wallet)
 * count at the shop of their first stake in it, together with what they won back from it. Gross gaming
 * revenue (GGR) is stakes minus what those players won or were refunded from the same settled games,
 * plus competition entries minus prizes paid and what their competition wallets still hold. Stakes in
 * games still open are shown apart and left out of GGR until the game is settled.
 */
class FinanceBetShopReportService
{
    use BuildsFinanceQueries;

    /** Money columns of a shop row, in report order. */
    private const MONEY_COLUMNS = [
        'float_balance', 'float_purchased', 'float_redeemed',
        'loads_gross', 'loads_excise', 'loads_net', 'cash_out_amount', 'withdrawals_pending_amount',
        'game_stakes', 'game_stakes_open', 'game_winnings', 'competition_entries', 'competition_prizes', 'competition_held',
    ];

    private const COUNT_COLUMNS = ['loads', 'loads_reversed', 'cash_outs', 'withdrawals_pending'];

    /**
     * One row per shop. Float, loads, cash withdrawals and play are for the range; float_balance and the
     * pending withdrawals are as of now.
     *
     * Filters: status (shop status), bet_shop_id (one shop; the summary then totals just that shop).
     *
     * @param  array<string, string>  $filters
     */
    public function shops(FinanceDateRange $range, array $filters): FinanceListing
    {
        $base = fn () => $this->shopBase($range, $filters);

        $summary = function () use ($base) {
            $totals = DB::query()->fromSub($base(), 'r')->selectRaw('COUNT(*) as shops, '.implode(', ', array_map(
                fn (string $column) => "COALESCE(SUM(r.{$column}), 0) as {$column}",
                array_merge(self::MONEY_COLUMNS, self::COUNT_COLUMNS)
            )))->first();

            return ['shops' => (int) $totals->shops] + $this->shapeTotals($totals);
        };

        return new FinanceListing(
            $base()->orderBy('shop_no'),
            fn (object $row) => [
                'bet_shop_id' => (int) $row->bet_shop_id,
                'shop_no' => $row->shop_no,
                'name' => $row->name,
                'status' => $row->status,
            ] + $this->shapeTotals($row),
            $summary,
            array_merge(['bet_shop_id', 'shop_no', 'name', 'status'], array_keys($this->shapeTotals((object) array_fill_keys(array_merge(self::MONEY_COLUMNS, self::COUNT_COLUMNS), 0)))),
        );
    }

    /**
     * Cash loads made in the range, newest first, with the player.
     *
     * Filters: status (completed, reversed), customer_id, bet_shop_id.
     *
     * @param  array<string, string>  $filters
     */
    public function loads(FinanceDateRange $range, array $filters): FinanceListing
    {
        $base = fn () => DB::table('bet_shop_loads as x')
            ->join('bet_shops as s', 's.id', '=', 'x.bet_shop_id')
            ->leftJoin('customers as c', 'c.id', '=', 'x.customer_id')
            ->whereBetween('x.created_at', [$range->from, $range->to])
            ->when($this->excludedCustomerIds($range) !== [], fn (Builder $query) => $query->whereNotIn('x.customer_id', $this->excludedCustomerIds($range)))
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('x.status', $filters['status']))
            ->when(isset($filters['customer_id']), fn (Builder $query) => $query->where('x.customer_id', (int) $filters['customer_id']))
            ->when(isset($filters['bet_shop_id']), fn (Builder $query) => $query->where('x.bet_shop_id', (int) $filters['bet_shop_id']));

        $summary = function () use ($base) {
            $rows = $base()->selectRaw('x.status, COUNT(*) as loads, COALESCE(SUM(x.gross_amount), 0) as gross, COALESCE(SUM(x.excise_amount), 0) as excise, COALESCE(SUM(x.net_amount), 0) as net')->groupBy('x.status')->get()->keyBy('status');
            $shape = fn (?object $row) => [
                'loads' => (int) ($row->loads ?? 0),
                'gross_amount' => $this->money($row->gross ?? 0),
                'excise_amount' => $this->money($row->excise ?? 0),
                'net_amount' => $this->money($row->net ?? 0),
            ];

            return [
                'completed' => $shape($rows->get(BetShopLoad::STATUS_COMPLETED)),
                'reversed' => $shape($rows->get(BetShopLoad::STATUS_REVERSED)),
            ];
        };

        return new FinanceListing(
            $base()->select('x.*', 's.shop_no', 's.name as shop_name', 'c.name as customer_name', 'c.player_no')->orderByDesc('x.id'),
            fn (object $row) => [
                'id' => (int) $row->id,
                'load_id' => $row->load_id,
                'bet_shop_id' => (int) $row->bet_shop_id,
                'shop_no' => $row->shop_no,
                'shop_name' => $row->shop_name,
                'customer_id' => (int) $row->customer_id,
                'customer_name' => $row->customer_name,
                'player_no' => $row->player_no,
                'gross_amount' => $this->money($row->gross_amount),
                'excise_amount' => $this->money($row->excise_amount),
                'net_amount' => $this->money($row->net_amount),
                'status' => $row->status,
                'loaded_by' => $row->loaded_by,
                'loaded_at' => $this->moment($row->created_at),
                'reversed_at' => $this->moment($row->reversed_at),
                'reversal_reason' => $row->reversal_reason,
            ],
            $summary,
            ['id', 'load_id', 'bet_shop_id', 'shop_no', 'shop_name', 'customer_id', 'customer_name', 'player_no', 'gross_amount', 'excise_amount', 'net_amount', 'status', 'loaded_by', 'loaded_at', 'reversed_at', 'reversal_reason'],
        );
    }

    /**
     * Cash withdrawals asked for in the range, newest first, with the player.
     *
     * Filters: status (pending, paid, cancelled, rejected, expired), customer_id, bet_shop_id.
     *
     * @param  array<string, string>  $filters
     */
    public function withdrawals(FinanceDateRange $range, array $filters): FinanceListing
    {
        $base = fn () => DB::table('shop_withdrawals as x')
            ->join('bet_shops as s', 's.id', '=', 'x.bet_shop_id')
            ->leftJoin('customers as c', 'c.id', '=', 'x.customer_id')
            ->whereBetween('x.created_at', [$range->from, $range->to])
            ->when($this->excludedCustomerIds($range) !== [], fn (Builder $query) => $query->whereNotIn('x.customer_id', $this->excludedCustomerIds($range)))
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('x.status', $filters['status']))
            ->when(isset($filters['customer_id']), fn (Builder $query) => $query->where('x.customer_id', (int) $filters['customer_id']))
            ->when(isset($filters['bet_shop_id']), fn (Builder $query) => $query->where('x.bet_shop_id', (int) $filters['bet_shop_id']));

        $summary = function () use ($base) {
            $rows = $base()->selectRaw('x.status, COUNT(*) as withdrawals, COALESCE(SUM(x.amount), 0) as amount')->groupBy('x.status')->get()->keyBy('status');

            return collect(ShopWithdrawal::STATUSES)->mapWithKeys(fn (string $status) => [$status => [
                'withdrawals' => (int) ($rows->get($status)->withdrawals ?? 0),
                'amount' => $this->money($rows->get($status)->amount ?? 0),
            ]])->all();
        };

        return new FinanceListing(
            $base()->select('x.*', 's.shop_no', 's.name as shop_name', 'c.name as customer_name', 'c.player_no')->orderByDesc('x.id'),
            fn (object $row) => [
                'id' => (int) $row->id,
                'withdrawal_id' => $row->withdrawal_id,
                'bet_shop_id' => (int) $row->bet_shop_id,
                'shop_no' => $row->shop_no,
                'shop_name' => $row->shop_name,
                'customer_id' => (int) $row->customer_id,
                'customer_name' => $row->customer_name,
                'player_no' => $row->player_no,
                'amount' => $this->money($row->amount),
                'status' => $row->status,
                'failed_attempts' => (int) $row->failed_attempts,
                'requested_at' => $this->moment($row->created_at),
                'expires_at' => $this->moment($row->expires_at),
                'closed_at' => $this->moment($row->closed_at),
                'closed_by' => $row->closed_by,
                'close_reason' => $row->close_reason,
            ],
            $summary,
            ['id', 'withdrawal_id', 'bet_shop_id', 'shop_no', 'shop_name', 'customer_id', 'customer_name', 'player_no', 'amount', 'status', 'failed_attempts', 'requested_at', 'expires_at', 'closed_at', 'closed_by', 'close_reason'],
        );
    }

    /**
     * A shop row's figures, with GGR worked out from them.
     *
     * @return array<string, int|float>
     */
    private function shapeTotals(object $row): array
    {
        $money = fn (string $column) => $this->money($row->{$column} ?? 0);
        $settledStakes = $money('game_stakes') - $money('game_stakes_open');

        return [
            'float_balance' => $money('float_balance'),
            'float_purchased' => $money('float_purchased'),
            'float_redeemed' => $money('float_redeemed'),
            'loads' => (int) ($row->loads ?? 0),
            'loads_gross' => $money('loads_gross'),
            'loads_excise' => $money('loads_excise'),
            'loads_net' => $money('loads_net'),
            'loads_reversed' => (int) ($row->loads_reversed ?? 0),
            'cash_outs' => (int) ($row->cash_outs ?? 0),
            'cash_out_amount' => $money('cash_out_amount'),
            'withdrawals_pending' => (int) ($row->withdrawals_pending ?? 0),
            'withdrawals_pending_amount' => $money('withdrawals_pending_amount'),
            'game_stakes' => $money('game_stakes'),
            'game_stakes_open' => $money('game_stakes_open'),
            'game_winnings' => $money('game_winnings'),
            'game_ggr' => round($settledStakes - $money('game_winnings'), 2),
            'competition_entries' => $money('competition_entries'),
            'competition_prizes' => $money('competition_prizes'),
            'competition_held' => $money('competition_held'),
            'competition_ggr' => round($money('competition_entries') - $money('competition_prizes') - $money('competition_held'), 2),
            'ggr' => round($settledStakes - $money('game_winnings') + $money('competition_entries') - $money('competition_prizes') - $money('competition_held'), 2),
        ];
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function shopBase(FinanceDateRange $range, array $filters): Builder
    {
        $in = [$range->from, $range->to];
        $excluded = $this->excludedCustomerIds($range);
        $notExcluded = fn (Builder $query, string $column) => $query->when($excluded !== [], fn (Builder $inner) => $inner->whereNotIn($column, $excluded));

        $purchased = DB::table('bet_shop_float_purchases')->whereNull('voided_at')->whereBetween('created_at', $in)
            ->selectRaw('bet_shop_id, SUM(amount) as float_purchased')->groupBy('bet_shop_id');

        $redeemed = DB::table('bet_shop_float_redemptions')->whereNull('voided_at')->whereBetween('created_at', $in)
            ->selectRaw('bet_shop_id, SUM(amount) as float_redeemed')->groupBy('bet_shop_id');

        $loads = $notExcluded(DB::table('bet_shop_loads')->whereBetween('created_at', $in), 'customer_id')
            ->selectRaw("bet_shop_id,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as loads,
                SUM(CASE WHEN status = 'completed' THEN gross_amount ELSE 0 END) as loads_gross,
                SUM(CASE WHEN status = 'completed' THEN excise_amount ELSE 0 END) as loads_excise,
                SUM(CASE WHEN status = 'completed' THEN net_amount ELSE 0 END) as loads_net,
                SUM(CASE WHEN status = 'reversed' THEN 1 ELSE 0 END) as loads_reversed")
            ->groupBy('bet_shop_id');

        $cashOuts = $notExcluded(DB::table('shop_withdrawals')->where('status', ShopWithdrawal::STATUS_PAID)->whereBetween('closed_at', $in), 'customer_id')
            ->selectRaw('bet_shop_id, COUNT(*) as cash_outs, SUM(amount) as cash_out_amount')->groupBy('bet_shop_id');

        $pending = $notExcluded(DB::table('shop_withdrawals')->where('status', ShopWithdrawal::STATUS_PENDING), 'customer_id')
            ->selectRaw('bet_shop_id, COUNT(*) as withdrawals_pending, SUM(balance) as withdrawals_pending_amount')->groupBy('bet_shop_id');

        // Each player's stakes in each game, counted at the shop of their first stake in it.
        $gamePlayers = $notExcluded(DB::table('game_transactions')->where('payment_type', 'deposit')->whereNotNull('customer_id'), 'customer_id')
            ->selectRaw('game_wallet_id, customer_id, MIN(id) as first_id, SUM(amount) as stakes')
            ->groupBy('game_wallet_id', 'customer_id');

        $gameWinnings = DB::table('game_transactions')
            ->where(fn (Builder $query) => $query->whereIn('payment_type', ['payout', 'payout|dropped'])->orWhere('payment_type', 'like', 'refund%'))
            ->selectRaw('game_wallet_id, customer_id, SUM(amount) as won')
            ->groupBy('game_wallet_id', 'customer_id');

        $games = DB::query()->fromSub($gamePlayers, 'p')
            ->join('game_transactions as f', 'f.id', '=', 'p.first_id')
            ->join('game_wallets as gw', 'gw.id', '=', 'p.game_wallet_id')
            ->leftJoinSub($gameWinnings, 'w', fn ($join) => $join->on('w.game_wallet_id', '=', 'p.game_wallet_id')->on('w.customer_id', '=', 'p.customer_id'))
            ->whereNotNull('f.bet_shop_id')
            ->whereBetween('f.created_at', $in)
            ->selectRaw('f.bet_shop_id,
                SUM(p.stakes) as game_stakes,
                SUM(CASE WHEN gw.status = 1 THEN p.stakes ELSE 0 END) as game_stakes_open,
                SUM(CASE WHEN gw.status = 1 THEN 0 ELSE COALESCE(w.won, 0) END) as game_winnings')
            ->groupBy('f.bet_shop_id');

        // Each competition wallet (one player in one competition), counted at the shop of its first entry.
        $competitionEntries = DB::table('competition_transactions')
            ->whereNotIn('payment_type', ['payout', 'win', 'loss'])
            ->selectRaw('competition_wallet_id, MIN(id) as first_id, SUM(amount) as entries')
            ->groupBy('competition_wallet_id');

        $competitionPrizes = DB::table('competition_transactions')->where('payment_type', 'payout')
            ->selectRaw('competition_wallet_id, SUM(amount) as prizes')->groupBy('competition_wallet_id');

        $competitions = $notExcluded(DB::query()->fromSub($competitionEntries, 'e')
            ->join('competition_transactions as f', 'f.id', '=', 'e.first_id')
            ->join('competition_wallets as cw', 'cw.id', '=', 'e.competition_wallet_id')
            ->leftJoinSub($competitionPrizes, 'z', 'z.competition_wallet_id', '=', 'e.competition_wallet_id')
            ->whereNotNull('f.bet_shop_id')
            ->whereBetween('f.created_at', $in), 'cw.customer_id')
            ->selectRaw('f.bet_shop_id, SUM(e.entries) as competition_entries, SUM(COALESCE(z.prizes, 0)) as competition_prizes, SUM(cw.balance) as competition_held')
            ->groupBy('f.bet_shop_id');

        return DB::table('bet_shops as s')
            ->leftJoin('bet_shop_wallets as fw', 'fw.bet_shop_id', '=', 's.id')
            ->leftJoinSub($purchased, 'fp', 'fp.bet_shop_id', '=', 's.id')
            ->leftJoinSub($redeemed, 'fr', 'fr.bet_shop_id', '=', 's.id')
            ->leftJoinSub($loads, 'ld', 'ld.bet_shop_id', '=', 's.id')
            ->leftJoinSub($cashOuts, 'co', 'co.bet_shop_id', '=', 's.id')
            ->leftJoinSub($pending, 'pw', 'pw.bet_shop_id', '=', 's.id')
            ->leftJoinSub($games, 'gm', 'gm.bet_shop_id', '=', 's.id')
            ->leftJoinSub($competitions, 'cm', 'cm.bet_shop_id', '=', 's.id')
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('s.status', $filters['status']))
            ->when(isset($filters['bet_shop_id']), fn (Builder $query) => $query->where('s.id', (int) $filters['bet_shop_id']))
            ->selectRaw('s.id as bet_shop_id, s.shop_no, s.name, s.status,
                COALESCE(fw.balance, 0) as float_balance, fp.float_purchased, fr.float_redeemed,
                ld.loads, ld.loads_gross, ld.loads_excise, ld.loads_net, ld.loads_reversed,
                co.cash_outs, co.cash_out_amount, pw.withdrawals_pending, pw.withdrawals_pending_amount,
                gm.game_stakes, gm.game_stakes_open, gm.game_winnings,
                cm.competition_entries, cm.competition_prizes, cm.competition_held');
    }
}
