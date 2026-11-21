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

    /**
     * @return list<string>
     */
    public static function playersNotes(): array
    {
        return [
            'registrations are customers who signed up in the range (platform-wide: a product or game filter does not change them).',
            'first_deposit_conversion: of the customers registered in the range, those whose first deposit or bet shop load came within 1, 7 or 30 days, the registration day being day 1. Only customers registered long enough ago to have had the whole window are eligible.',
            'active: distinct players per day (daily), per calendar week from Monday (weekly) and per calendar month (monthly), in all games or the game or product filtered.',
            'retention_cohorts: customers registered in the range, grouped by registration week (Monday). week_N is the percentage of the cohort that played in the Nth week after the registration week; null until that week is over.',
            'churn: players active in the 30 days before the last 30 days ending on `to` (previous) who did not play in those last 30 days (current).',
            'stake_bands group active players by the cash they staked in the range when placing (Kadi stakes less refunds); free bets are not stakes.',
            'channel is the channel a game sent with each wager (unspecified when it sent none); Kadi stakes are bet_shop (made at a shop) or game_server. player_days adds up distinct players per product and day, so a player counts once per day and product.',
        ];
    }

    /**
     * @return list<string>
     */
    public static function topPlayersNotes(): array
    {
        return [
            'turnover is cash stakes: settled to the house for house-banked products, staked into games and competitions (less refunds) for Kadi. payouts are cash winnings (before winnings tax) and net_loss = turnover - payouts.',
            'ggr is what the house kept from the player on house-banked products (turnover - payouts). Kadi house cuts cannot be tied to one player, so Kadi play adds nothing to ggr.',
            'wins counts won house-banked bets (net of reversals) and Kadi payouts received.',
            'deposits (deposits and bet shop loads credited) and withdrawals (B2C withdrawals paid) are the player\'s whole wallet, whatever the product filter; with a filter only players who played that product or game are listed.',
            'games_played counts entries in the range (wagers placed and Kadi entries). last_active is the last day the player played on or before `to`, in any product.',
            'display_name follows the public display rule (first name and last initial, or "Player" and the last four digits of the player number).',
        ];
    }

    /**
     * @return list<string>
     */
    public static function promotionNotes(bool $filtered): array
    {
        return [
            'Promotion costs are dated when they were made and counted net of reversals: signup_bonus (and any other promotion credit) at its gross cost, including excise duty; free_bet_winnings and wager_bonus (the bonus on a winning wager, such as a multi-bet bonus) as paid by the house; referral_payout when a referral withdrawal completed.',
            'free_bet_value (face value of free bets settled won or lost) and referral_bonus (bonuses earned in referral wallets) are shown for information and are not a cost: a free bet costs what it wins, and a referral bonus costs the house only when it is paid out.',
            'cost equals the income statement promotions + wager_promotions + referral_payouts for the same range; per product, free_bet_winnings + wager_bonus equal the product\'s promotions in /stats/dashboard.',
            'players are distinct customers who received the promotion; players_reached counts them over every cost type. cost_per_active_player is cost / active players.',
            ...($filtered ? ['With a product or game filter only free bets and wager bonuses of those products are included: platform promotions (signup bonus, referrals) belong to no product.'] : []),
        ];
    }

    /**
     * @return list<string>
     */
    public static function paymentNotes(): array
    {
        return [
            'Platform-wide: a product or game filter is refused (product_filter_not_supported).',
            'deposits are paybill payments credited to a wallet (wallet deposits and loads, as in /stats/dashboard), by when they were received; unmatched are payments not credited to anyone. House funding, bet shop float and gift or emoji purchases are left out.',
            'stk_push counts STK pushes sent, by when they were sent: completed when the deposit arrived (within responsible_gambling.stk_pending_minutes), not_completed otherwise. M-Pesa does not tell us why a push was not completed, so there is no reason breakdown.',
            'withdrawals: requested by when they were asked for; paid and failed by when M-Pesa answered. failed.by_reason groups the failure text M-Pesa sent (insufficient_funds, invalid_account, timeout, declined, other): the result code itself is not stored. avg_seconds_to_complete is from request to answer. pending_now is withdrawals still waiting for an answer, right now.',
            'held_withdrawals are withdrawals at or above the approval threshold, held for staff: requested by request time, decided by decision time with the average wait; awaiting_now is right now.',
            'bet_shops: loads by load time (gross), loads_reversed by reversal time, cash_outs (cash withdrawals paid at a shop) by payment time with the average time from request to payment.',
            'by_hour is Nairobi time.',
        ];
    }
}
