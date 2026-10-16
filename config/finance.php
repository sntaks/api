<?php

$testCustomerIds = env('FINANCE_TEST_CUSTOMER_IDS');

return [

    /*
    |--------------------------------------------------------------------------
    | Test Customer IDs
    |--------------------------------------------------------------------------
    |
    | Customers listed here are excluded from customer stats and financial
    | reports. Set FINANCE_TEST_CUSTOMER_IDS to a comma separated list (for
    | example on local or staging). Production has no test customers, so the
    | default is none. The house wallet is kept apart by wallets.house_wallet_id,
    | not by this list.
    |
    */

    'test_customer_ids' => $testCustomerIds === null || $testCustomerIds === ''
        ? []
        : array_values(array_filter(array_map('intval', explode(',', $testCustomerIds)))),

    /*
    |--------------------------------------------------------------------------
    | Fee Schedule
    |--------------------------------------------------------------------------
    |
    | House cut rates as fractions of the amount they apply to. The game and
    | competition services read these, and finance reports use them to check
    | that the cuts actually taken match the schedule.
    |
    | game_credit      taken from each stake when it is credited to a game wallet
    | game_withdrawal  taken from the pool when a game is paid out (rounded down,
    |                  so it never exceeds the rate)
    | game_drop        taken from a dropped player's stake pool (rounded up)
    | tournament       taken from each tournament entry (competition game_type 1)
    | jackpot          taken from each jackpot entry (competition game_type 2)
    |
    */

    'fees' => [
        'game_credit' => 0.05,
        'game_withdrawal' => 0.05,
        'game_drop' => 0.10,
        'tournament' => 0.10,
        'jackpot' => 0.20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Coin Exchange Rate
    |--------------------------------------------------------------------------
    |
    | Value of one coin in KES.
    |
    */

    'coin_rate' => 0.04,

    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    |
    | max_range_days  longest date range a single report request may cover.
    | cache_ttl       seconds to cache report windows that include today.
    |
    */

    'reports' => [
        'max_range_days' => 366,
        'cache_ttl' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Expense Categories
    |--------------------------------------------------------------------------
    |
    | The categories an expense can be recorded under. Reports group by these.
    | Add a category here to allow it; existing expenses keep theirs.
    |
    */

    'expense_categories' => [
        'mpesa_charges',
        'hosting',
        'sms',
        'salaries',
        'marketing',
        'licences_and_fees',
        'other',
    ],

    /*
    |--------------------------------------------------------------------------
    | Taxes
    |--------------------------------------------------------------------------
    |
    | Estimated tax lines for the tax report. Every rate is a fraction of the
    | base (0.15 is 15%) and is off (0) until you set it, because the right
    | rates and bases depend on how your games are classed. Confirm them with
    | your accountant or tax adviser. These are estimates for planning, not
    | tax returns. Excise duty is not listed here: it is charged on deposits
    | (see excise_duty below) and the tax report shows the actual amounts.
    |
    | base   stakes      customer stakes in the period
    |        winnings    winnings paid to customers in the period
    |        revenue     total revenue
    |        net_income  revenue minus expenses, never below zero
    | kind   expense       a cost to the house: reduces net income after tax
    |        pass_through  collected or withheld and passed on: not a cost
    |
    */

    'taxes' => [
        'withholding_tax' => [
            'label' => 'Withholding tax on winnings',
            'base' => 'winnings',
            'kind' => 'pass_through',
            'rate' => (float) env('FINANCE_TAX_WITHHOLDING_RATE', 0),
        ],
        'income_tax' => [
            'label' => 'Income tax on net income',
            'base' => 'net_income',
            'kind' => 'expense',
            'rate' => (float) env('FINANCE_TAX_INCOME_RATE', 0),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Excise Duty on Deposits
    |--------------------------------------------------------------------------
    |
    | Excise duty taken from each M-Pesa deposit into a customer wallet. The
    | wallet is credited with the gross amount, then debited with the duty, so
    | the customer keeps gross - round(gross * rate, 2). The duty is held as a
    | liability (excise duty payable) until it is paid to KRA, never as revenue.
    |
    | enabled         off until finance switches it on.
    | rate            fraction of the gross deposit (0.05 is 5%). Saved on each charge.
    | effective_from  first day (app timezone, Y-m-d) deposits are charged.
    |                 Deposits made before it are never charged.
    | applies_to      deposit kinds that are charged. wallet_deposit is a plain
    |                 deposit to the wallet. Coin loads, gifts and emoji are not.
    | filing_day      day of the next month the return and payment are due.
    |
    */

    'excise_duty' => [
        'enabled' => (bool) env('FINANCE_EXCISE_DUTY_ENABLED', false),
        'rate' => (float) env('FINANCE_EXCISE_DUTY_RATE', 0.05),
        'effective_from' => env('FINANCE_EXCISE_DUTY_EFFECTIVE_FROM'),
        'applies_to' => ['wallet_deposit'],
        'filing_day' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | List Reports
    |--------------------------------------------------------------------------
    |
    | Page size for the paginated drill-down reports. CSV exports stream every
    | row in the range and ignore these.
    |
    */

    'lists' => [
        'default_per_page' => 50,
        'max_per_page' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Open wallets report
    |--------------------------------------------------------------------------
    |
    | An open game or competition wallet counts as untouched once nothing has
    | happened on it (no stake, entry or round) for this many minutes. Single
    | games normally finish within minutes; competitions run over many rounds.
    | GET /finance/open-wallets?idle_minutes= overrides both for one request.
    |
    */

    'open_wallets' => [
        'game_idle_minutes' => (int) env('FINANCE_OPEN_GAME_IDLE_MINUTES', 30),
        'competition_idle_minutes' => (int) env('FINANCE_OPEN_COMPETITION_IDLE_MINUTES', 1440),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ledger Dates
    |--------------------------------------------------------------------------
    |
    | ledger_started_at    first day the ledger recorded money movements. Reports
    |                      warn when a range starts before it, because earlier
    |                      activity is not in the ledger.
    | ledger_reliable_from first day every movement, including escrow releases,
    |                      was ledgered (the day the Phase 0 finance fixes went
    |                      live). Balance and escrow checks ignore older data.
    |                      Set FINANCE_LEDGER_RELIABLE_FROM after deploying.
    |
    */

    'ledger_started_at' => env('FINANCE_LEDGER_STARTED_AT', '2026-09-01'),

    'ledger_reliable_from' => env('FINANCE_LEDGER_RELIABLE_FROM', env('FINANCE_LEDGER_STARTED_AT', '2026-09-01')),

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | tolerance                money differences up to this are ignored (KES).
    | stuck_game_hours         an open game or competition wallet holding a
    |                          balance and untouched this long is flagged.
    | stuck_withdrawal_hours   a withdrawal pending this long is flagged.
    | stale_mpesa_balance_hours  the M-Pesa balance fetch is overdue after this.
    | aged_dispute_days        a complaint still pending after this many days
    |                          is flagged.
    |
    */

    'reconciliation' => [
        'tolerance' => 0.01,
        'stuck_game_hours' => 24,
        'stuck_withdrawal_hours' => 24,
        'stale_mpesa_balance_hours' => 3,
        'aged_dispute_days' => (int) env('FINANCE_AGED_DISPUTE_DAYS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily Snapshot
    |--------------------------------------------------------------------------
    |
    | Time (in the app timezone) the `finance:snapshot` command runs each day.
    |
    */

    'snapshot_time' => '23:59',

    /*
    |--------------------------------------------------------------------------
    | Chart of Accounts
    |--------------------------------------------------------------------------
    |
    | accounts       where money sits, by the kind of wallet a ledger entry
    |                points at. The house wallet is split from customer wallets.
    | entry_types    how each ledger entry_type is reported. An entry type ending
    |                in "_reversal" takes the category of the entry it reverses.
    |
    | Categories: cash_in, cash_out, stake, payout, house_revenue, refund,
    | adjustment, transfer, escrow_movement, coin, tax_withheld,
    | referral_bonus, referral_payout, promotion, capital.
    |
    | capital entries (house_funding) are owner money paid into the paybill and
    | credited to the house wallet. Single-sided like deposits (the cash side
    | is M-Pesa), but not customer deposits: no excise duty, not revenue.
    |
    | promotion entries (promo_credit) move a promotion's gross amount from the
    | house wallet to a customer wallet. They are paired and net to zero; the
    | cost is reported as the promotions expense in the income statement.
    |
    | referral_wallets hold referral bonuses that cannot be spent yet. They are
    | a contingent amount, not a liability: a bonus becomes an expense only
    | when it is paid out (referral_payout), from the referral shortcode.
    |
    */

    'accounts' => [
        'customer_wallets' => ['label' => 'Customer wallets', 'type' => 'liability'],
        'house_wallet' => ['label' => 'House wallet', 'type' => 'equity'],
        'game_escrow' => ['label' => 'Game wallets (escrow)', 'type' => 'liability'],
        'competition_escrow' => ['label' => 'Competition wallets (escrow)', 'type' => 'liability'],
        'coin_wallets' => ['label' => 'Coin wallets', 'type' => 'liability'],
        'disputed_funds' => ['label' => 'Disputed funds (complaints)', 'type' => 'liability'],
        'referral_wallets' => ['label' => 'Referral wallets (unspent bonuses)', 'type' => 'contingent'],
    ],

    'entry_types' => [
        'deposit' => 'cash_in',
        'excise_duty' => 'tax_withheld',
        'withdrawal' => 'cash_out',
        'game_bet' => 'stake',
        'competition_bet' => 'stake',
        'game_payout' => 'payout',
        'competition_payout' => 'payout',
        'house_cut' => 'house_revenue',
        'refund' => 'refund',
        'adjustment' => 'adjustment',
        'wallet_transfer' => 'transfer',
        'escrow_release' => 'escrow_movement',
        'escrow_transfer' => 'escrow_movement',
        'dispute_hold' => 'escrow_movement',
        'dispute_release' => 'escrow_movement',
        'dispute_refund' => 'refund',
        'coin_purchase' => 'coin',
        'coin_exchange' => 'coin',
        'coin_transfer' => 'coin',
        'referral_bonus' => 'referral_bonus',
        'referral_withdrawal' => 'referral_payout',
        'promo_credit' => 'promotion',
        'house_funding' => 'capital',
    ],

];
