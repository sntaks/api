<?php

namespace App\Services\Stats;

/**
 * The definitions every stats answer shares, so GMS reads the same words the same way everywhere.
 */
final class StatsVocabulary
{
    /**
     * @return list<string>
     */
    public static function notes(): array
    {
        return [
            'Days are Nairobi days (Africa/Nairobi); amounts are KES. exclude_test (default true) leaves out the customers in FINANCE_TEST_CUSTOMER_IDS, as the finance reports do.',
            'bets are wagers settled won or lost; voids are wagers settled void, counted apart. For the Kadi rake products (kadi, kadi_tournament, kadi_jackpot) bets are game and competition entries.',
            'turnover is cash stakes settled to the house; free bets are not turnover (free_bet_value is their face value). For Kadi rake products turnover is the stakes players put into games and competitions, less refunds.',
            'payouts are cash winnings paid by the house before winnings tax. Rake products have no payouts and no rtp (null).',
            'ggr is turnover - payouts for house-banked products and the house cut for rake products. It equals the product\'s revenue in /finance/income-statement by_product, and for house-banked products the ggr of /finance/house-banked.',
            'hold is ggr as a percentage of turnover and rtp is payouts as a percentage of house-banked turnover; both are null when nothing was staked.',
            'promotions are bonuses and free-bet winnings paid by the house on wagers; net is ggr - promotions (the by_product net of the income statement). Platform promotions such as the signup bonus are not allocated to products.',
            'avg_stake is turnover / bets.',
            'Everything is net of reversals: a reversal is counted, with the opposite sign, on the day it was made, so a day can show a negative figure.',
            'players and active_players are distinct customers with at least one wager placed or Kadi game or competition entry in the range (all games, or the game or product of the row).',
        ];
    }

    /**
     * @return list<string>
     */
    public static function dashboardNotes(bool $filtered): array
    {
        return [
            'totals add up the by_product rows. Revenue with no product (gift and emoji sales, untagged house cuts) is in /finance/income-statement only.',
            'deposits are wallet deposits and bet shop loads received (cash-flow cash_in wallet_deposit + load); withdrawals are withdrawals paid out (cash_out paid); net_cash is the cash-flow net_cash.',
            'new_players registered in the range; first_depositors made their first deposit or bet shop load in the range; depositing_players made at least one.',
            'players.new are active players who registered in the range, players.returning active players who registered before it. players.active counts the last day, the last 7 days and the last 30 days ending on `to`.',
            'cross_game counts active players by how many games (not products) they played in the range.',
            'Each block carries `previous` (the compare window) and `delta_pct` (change in percent; null when the previous figure is 0). previous_series is the compare window\'s series.',
            'liability is open exposure now, not in the range; see /stats/liability.',
            ...($filtered ? ['With a product or game filter the platform-wide figures (deposits, withdrawals, net_cash, new_players, first_depositors, depositing_players) are null.'] : []),
        ];
    }

    /**
     * @return list<string>
     */
    public static function gamesNotes(): array
    {
        return [
            'hourly_heatmap has 7 x 24 cells (weekday mon-sun, hour 0-23, Nairobi time) for the range: stakes placed in that hour (wager stakes and Kadi stakes when placed, not when settled), players as the sum over the days of distinct players in that hour, and avg_players per day.',
        ];
    }
}
