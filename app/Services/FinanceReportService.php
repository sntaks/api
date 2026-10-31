<?php

namespace App\Services;

use App\Models\CompetitionTransaction;
use App\Models\Deposit;
use App\Models\DepositResolution;
use App\Models\ExciseDutyCharge;
use App\Models\FinancialSnapshot;
use App\Models\GameTransaction;
use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FinanceReportService
{
    private const CASH_IN_KINDS = ['wallet_deposit', 'load', 'gift', 'emoji', 'unmatched', 'house_funding', 'shop_float', 'other'];

    private const REVENUE_LINES = ['games', 'tournaments', 'jackpots', 'competitions_unattributed', 'gift_emoji_sales', 'other'];

    public function __construct(
        private ChartOfAccounts $chart,
        private FinanceSnapshotService $snapshots,
        private FinanceExpenseService $expenses,
        private ExciseDutyReportService $exciseDuty,
        private WinningsTaxReportService $winningsTax,
        private FinanceReferralReportService $referrals,
        private FinancePromotionReportService $promotions,
    ) {}

    /**
     * Describes the window a report covers and warns about parts the ledger cannot answer for.
     *
     * @return array{period: array<string, mixed>, ledger_started_at: string, warnings: list<string>}
     */
    public function meta(FinanceDateRange $range): array
    {
        $timezone = config('app.timezone');
        $ledgerStart = CarbonImmutable::parse(config('finance.ledger_started_at'), $timezone)->startOfDay();
        $reliableFrom = CarbonImmutable::parse(config('finance.ledger_reliable_from'), $timezone)->startOfDay();

        $warnings = [];

        if ($range->from->lessThan($ledgerStart)) {
            $warnings[] = "The range starts before the ledger began on {$ledgerStart->toDateString()}. Ledger-based figures (revenue, stakes, payouts) are zero before that date.";
        }

        if ($range->from->lessThan($reliableFrom)) {
            $warnings[] = "Escrow releases were only ledgered from {$reliableFrom->toDateString()}. Trial balance and escrow figures before then may not balance.";
        }

        return [
            'period' => [
                'from' => $range->from->toDateString(),
                'to' => $range->to->toDateString(),
                'group_by' => $range->groupBy,
                'exclude_test' => $range->excludeTest,
            ],
            'ledger_started_at' => $ledgerStart->toDateString(),
            'warnings' => $warnings,
        ];
    }

    /**
     * Dashboard tiles: the main figures for today, this week, month and year, plus the current position.
     *
     * @return array<string, mixed>
     */
    public function summary(bool $excludeTest = true): array
    {
        $timezone = config('app.timezone');
        $now = CarbonImmutable::now($timezone);
        $today = $now->startOfDay();

        $windows = [
            'today' => $today,
            'week' => $today->startOfWeek(),
            'month' => $today->startOfMonth(),
            'year' => $today->startOfYear(),
            'all_time' => CarbonImmutable::parse(config('finance.ledger_started_at'), $timezone)->startOfDay(),
        ];

        $figures = [];
        foreach ($windows as $name => $from) {
            $window = new FinanceDateRange($from, $now->endOfDay(), FinanceDateRange::GROUP_BY_MONTH, $excludeTest);

            $cash = $this->cashFlow($window)['totals'];
            $statement = $this->incomeStatement($window);
            $revenue = $statement['revenue'];
            $flows = $this->flows($window);

            $figures[$name] = [
                'from' => $from->toDateString(),
                'cash_in' => $cash['cash_in']['total'],
                'cash_out' => $cash['cash_out']['paid'],
                'net_cash' => $cash['net_cash'],
                'revenue' => [
                    'games' => $revenue['games'],
                    'tournaments' => $revenue['tournaments']['total'],
                    'jackpots' => $revenue['jackpots']['total'],
                    'gift_emoji_sales' => $revenue['gift_emoji_sales']['total'],
                    'total' => $revenue['total'],
                ],
                'expenses' => $statement['expenses']['total'],
                'referral_payouts' => $statement['referral_payouts'],
                'promotions' => $statement['promotions'],
                'net_income' => $statement['net_income'],
                'stakes' => $flows['stakes'],
                'payouts' => $flows['payouts'],
                'refunds' => $flows['refunds'],
                'adjustments' => $flows['adjustments'],
            ];
        }

        return [
            'exclude_test' => $excludeTest,
            'all_time_from' => $windows['all_time']->toDateString(),
            'windows' => $figures,
            'position' => $this->balanceSheet(),
        ];
    }

    /**
     * Money in and out through M-Pesa. Cash in comes from incoming payments (what was actually paid),
     * split by what the payment bought; cash out from outgoing payments by disbursement status.
     * Excise duty withheld from deposits is shown apart; only what was paid to KRA leaves net cash. Winnings tax
     * withheld from house-banked wins (net of reversal credits) is shown the same way.
     * Referral payouts leave from the referral shortcode and are shown apart from main withdrawals.
     * deposit_refunds are unmatched deposits sent back to the payer, by the day the refund was recorded.
     * house_funding is owner money paid into the paybill and credited to the house wallet (capital).
     * shop_float.redeemed is float paid back to bet shop owners (recorded by GMS, paid outside the API) and
     * leaves net cash. shop_cash is cash that changed hands at shop counters (loaded = paid in by players,
     * paid_out = paid out to them); it never reaches M-Pesa, so it is shown apart and not in net cash.
     *
     * @return array{totals: array<string, mixed>, series: list<array<string, mixed>>}
     */
    public function cashFlow(FinanceDateRange $range): array
    {
        $template = fn () => [
            'cash_in' => array_fill_keys(self::CASH_IN_KINDS, 0.0) + ['total' => 0.0],
            'cash_out' => ['paid' => 0.0, 'pending' => 0.0, 'failed' => 0.0],
            'excise_duty' => ['withheld' => 0.0, 'remitted' => 0.0],
            'winnings_tax' => ['withheld' => 0.0, 'remitted' => 0.0],
            'referral_payouts' => ['paid' => 0.0, 'pending' => 0.0, 'failed' => 0.0],
            'deposit_refunds' => ['refunded' => 0.0],
            'shop_float' => ['redeemed' => 0.0],
            'shop_cash' => ['loaded' => 0.0, 'paid_out' => 0.0],
        ];

        $series = [];
        foreach ($range->buckets() as $bucket) {
            $series[$bucket] = $template();
        }

        foreach ($this->incomingPaymentRows($range) as $row) {
            $bucket = $this->bucket($range, $row->day);
            $kind = in_array($row->kind, self::CASH_IN_KINDS, true) ? $row->kind : 'other';

            $series[$bucket]['cash_in'][$kind] += (float) $row->amount;
            $series[$bucket]['cash_in']['total'] += (float) $row->amount;
        }

        foreach ($this->outgoingPaymentRows($range) as $row) {
            $bucket = $this->bucket($range, $row->day);
            $status = match ((int) $row->disburse) {
                2 => 'paid',
                3 => 'failed',
                default => 'pending',
            };

            $series[$bucket]['cash_out'][$status] += (float) $row->amount;
        }

        foreach ($this->exciseDuty->chargeRows($range) as $row) {
            if ($row->status === ExciseDutyCharge::STATUS_CHARGED) {
                $series[$this->bucket($range, $row->day)]['excise_duty']['withheld'] += (float) $row->excise;
            }
        }

        foreach ($this->exciseDuty->remittanceRows($range) as $row) {
            $series[$this->bucket($range, $row->day)]['excise_duty']['remitted'] += (float) $row->amount;
        }

        foreach ($this->winningsTax->chargeRows($range) as $row) {
            $series[$this->bucket($range, $row->day)]['winnings_tax']['withheld'] += (float) $row->amount;
        }

        foreach ($this->winningsTax->remittanceRows($range) as $row) {
            $series[$this->bucket($range, $row->day)]['winnings_tax']['remitted'] += (float) $row->amount;
        }

        foreach ($this->referrals->payoutRows($range) as $row) {
            $series[$this->bucket($range, $row->day)]['referral_payouts'][$row->status] += $row->amount;
        }

        foreach ($this->depositRefundRows($range) as $row) {
            $series[$this->bucket($range, $row->day)]['deposit_refunds']['refunded'] += (float) $row->amount;
        }

        foreach ($this->shopCashRows($range) as $row) {
            $series[$this->bucket($range, $row->day)][$row->block][$row->kind] += (float) $row->amount;
        }

        $totals = $template();
        $list = [];
        foreach ($series as $bucket => $figures) {
            foreach ($figures['cash_in'] as $key => $value) {
                $totals['cash_in'][$key] += $value;
            }
            foreach ($figures['cash_out'] as $key => $value) {
                $totals['cash_out'][$key] += $value;
            }
            foreach ($figures['excise_duty'] as $key => $value) {
                $totals['excise_duty'][$key] += $value;
            }
            foreach ($figures['referral_payouts'] as $key => $value) {
                $totals['referral_payouts'][$key] += $value;
            }
            foreach (['winnings_tax', 'deposit_refunds', 'shop_float', 'shop_cash'] as $block) {
                foreach ($figures[$block] as $key => $value) {
                    $totals[$block][$key] += $value;
                }
            }

            $list[] = ['period' => $bucket] + $figures + ['net_cash' => $this->netCash($figures)];
        }

        return $this->rounded([
            'totals' => $totals + ['net_cash' => $this->netCash($totals)],
            'series' => $list,
        ]);
    }

    /**
     * Cash received less withdrawals paid out, excise duty and winnings tax paid to KRA, referral payouts and
     * unmatched deposits refunded to the payer.
     *
     * @param  array{cash_in: array<string, float>, cash_out: array<string, float>, excise_duty: array<string, float>, winnings_tax: array<string, float>, referral_payouts: array<string, float>, deposit_refunds: array<string, float>}  $figures
     */
    private function netCash(array $figures): float
    {
        return $figures['cash_in']['total'] - $figures['cash_out']['paid'] - $figures['excise_duty']['remitted'] - $figures['winnings_tax']['remitted']
            - $figures['referral_payouts']['paid'] - $figures['deposit_refunds']['refunded'] - $figures['shop_float']['redeemed'];
    }

    /**
     * Float paid back to bet shop owners (not voided) and cash that changed hands at shop counters: completed
     * loads by load day, paid cash withdrawals by payment day. Test customers' shop cash is left out.
     *
     * @return Collection<int, object{day: string, block: string, kind: string, amount: string}>
     */
    private function shopCashRows(FinanceDateRange $range)
    {
        $in = [$range->from, $range->to];
        $excluded = $this->excludedCustomerIds($range);

        $redeemed = DB::table('bet_shop_float_redemptions')->whereNull('voided_at')->whereBetween('created_at', $in)
            ->selectRaw("DATE(created_at) as day, 'shop_float' as block, 'redeemed' as kind, SUM(amount) as amount")->groupByRaw('DATE(created_at)');

        $loaded = DB::table('bet_shop_loads')->where('status', 'completed')->whereBetween('created_at', $in)
            ->when($excluded !== [], fn (Builder $query) => $query->whereNotIn('customer_id', $excluded))
            ->selectRaw("DATE(created_at) as day, 'shop_cash' as block, 'loaded' as kind, SUM(gross_amount) as amount")->groupByRaw('DATE(created_at)');

        $paidOut = DB::table('shop_withdrawals')->where('status', 'paid')->whereBetween('closed_at', $in)
            ->when($excluded !== [], fn (Builder $query) => $query->whereNotIn('customer_id', $excluded))
            ->selectRaw("DATE(closed_at) as day, 'shop_cash' as block, 'paid_out' as kind, SUM(amount) as amount")->groupByRaw('DATE(closed_at)');

        return $redeemed->unionAll($loaded)->unionAll($paidOut)->get();
    }

    /**
     * Unmatched deposits recorded as refunded to the payer, per refund day.
     *
     * @return Collection<int, object{day: string, amount: string}>
     */
    private function depositRefundRows(FinanceDateRange $range)
    {
        return DB::table('deposit_resolutions as r')
            ->join('incoming_payments as i', 'i.id', '=', 'r.deposit_id')
            ->where('r.action', DepositResolution::ACTION_REFUNDED)
            ->whereBetween('r.created_at', [$range->from, $range->to])
            ->selectRaw('DATE(r.created_at) as day, SUM(i.trans_amount) as amount')
            ->groupByRaw('DATE(r.created_at)')
            ->get();
    }

    /**
     * Revenue by stream, less recorded expenses. House cuts come from the ledger; gift and emoji
     * sales are cash the house received directly.
     *
     * @return array<string, mixed>
     */
    public function incomeStatement(FinanceDateRange $range): array
    {
        $template = fn () => array_fill_keys(self::REVENUE_LINES, 0.0) + ['total' => 0.0];

        $series = [];
        foreach ($range->buckets() as $bucket) {
            $series[$bucket] = $template();
        }

        $gamesBySource = [];
        $roundsBy = ['tournaments' => [], 'jackpots' => []];
        $giftEmoji = ['gift' => 0.0, 'emoji' => 0.0];

        foreach ($this->houseCutRows($range) as $row) {
            $bucket = $this->bucket($range, $row->day);
            $line = $this->revenueLine((string) $row->source, $row->game_type === null ? null : (int) $row->game_type);
            $amount = (float) $row->amount;

            $series[$bucket][$line] += $amount;
            $series[$bucket]['total'] += $amount;

            if ($line === 'games') {
                $gamesBySource[$row->source] = ($gamesBySource[$row->source] ?? 0.0) + $amount;
            } elseif (isset($roundsBy[$line])) {
                $rounds = (string) ($row->jp_rounds ?? 0);
                $roundsBy[$line][$rounds] = ($roundsBy[$line][$rounds] ?? 0.0) + $amount;
            }
        }

        foreach ($this->giftEmojiRows($range) as $row) {
            $bucket = $this->bucket($range, $row->day);
            $amount = (float) $row->amount;

            $series[$bucket]['gift_emoji_sales'] += $amount;
            $series[$bucket]['total'] += $amount;
            $giftEmoji[$row->purchase_type] += $amount;
        }

        $referralPayouts = [];
        foreach ($this->referrals->payoutRows($range) as $row) {
            if ($row->status === 'paid') {
                $bucket = $this->bucket($range, $row->day);
                $referralPayouts[$bucket] = ($referralPayouts[$bucket] ?? 0.0) + $row->amount;
            }
        }

        $promotionCosts = [];
        foreach ($this->promotions->costRows($range) as $row) {
            $bucket = $this->bucket($range, $row->day);
            $promotionCosts[$bucket] = ($promotionCosts[$bucket] ?? 0.0) + (float) $row->amount;
        }

        $referralBonuses = ['signup' => 0.0, 'first_deposit' => 0.0, 'total' => 0.0];
        foreach ($this->referrals->bonusRows($range) as $row) {
            $referralBonuses[$row->milestone] = ($referralBonuses[$row->milestone] ?? 0.0) + (float) $row->amount;
            $referralBonuses['total'] += (float) $row->amount;
        }

        $expenses = $this->expenses->totals($range);
        foreach ($series as $bucket => $figures) {
            $series[$bucket]['expenses'] = $expenses['by_bucket'][$bucket] ?? 0.0;
            $series[$bucket]['referral_payouts'] = $referralPayouts[$bucket] ?? 0.0;
            $series[$bucket]['promotions'] = $promotionCosts[$bucket] ?? 0.0;
            $series[$bucket]['net_income'] = $figures['total'] - $series[$bucket]['expenses'] - $series[$bucket]['referral_payouts'] - $series[$bucket]['promotions'];
        }

        $totals = $template() + ['expenses' => 0.0, 'referral_payouts' => 0.0, 'promotions' => 0.0, 'net_income' => 0.0];
        $list = [];
        foreach ($series as $bucket => $figures) {
            foreach ($figures as $key => $value) {
                $totals[$key] += $value;
            }
            $list[] = ['period' => $bucket] + $figures;
        }

        ksort($roundsBy['tournaments']);
        ksort($roundsBy['jackpots']);

        $load = $this->loadMargin($range);

        return $this->rounded([
            'revenue' => [
                'games' => $totals['games'],
                'games_by_source' => $gamesBySource,
                'tournaments' => ['total' => $totals['tournaments'], 'by_rounds' => $roundsBy['tournaments']],
                'jackpots' => ['total' => $totals['jackpots'], 'by_rounds' => $roundsBy['jackpots']],
                'competitions_unattributed' => $totals['competitions_unattributed'],
                'gift_emoji_sales' => ['total' => $totals['gift_emoji_sales']] + $giftEmoji,
                'other' => $totals['other'],
                'total' => $totals['total'],
            ],
            'expenses' => ['tracked' => true, 'total' => $totals['expenses'], 'by_category' => $expenses['by_category']],
            'referral_payouts' => $totals['referral_payouts'],
            'promotions' => $totals['promotions'],
            'net_income' => $totals['net_income'],
            'memo' => [
                'load_margin' => $load,
                'referral_bonuses_earned' => $referralBonuses,
            ],
            'notes' => [
                'Revenue is house cuts as they are booked in the ledger plus gift and emoji sales at cash received.',
                'competitions_unattributed are competition cuts booked before they were linked to their transaction, so tournament and jackpot cannot be told apart.',
                'load_margin is cash received for wallet loads minus the wallet credit given. It is shown for information and is not part of revenue.',
                'Expenses are the entries recorded through /finance/expenses (voided ones excluded), dated by expense_date. net_income is before tax; see /finance/taxes.',
                'referral_payouts are referral withdrawals completed in the period (the referral programme expense), dated by completion.',
                'promotions is the gross cost of promotion credits granted in the period (the signup bonus): what the house wallet paid, including the excise duty owed on it.',
                'net_income is revenue minus expenses, referral_payouts and promotions.',
                'referral_bonuses_earned is shown for information: bonuses credited to referral wallets are only an expense once they are paid out.',
                'house cuts cannot always be tied to a player, so exclude_test only removes cuts that are linked to a test customer or game.',
            ],
            'series' => $list,
        ]);
    }

    /**
     * The position at a point in time: cash held against what is owed. Live when no date is given,
     * otherwise read from that day's snapshot. Returns null when there is no snapshot for the date.
     *
     * @return array<string, mixed>|null
     */
    public function balanceSheet(?string $asOf = null): ?array
    {
        if ($asOf === null) {
            $position = $this->snapshots->currentPosition();
            $source = 'live';
            $date = CarbonImmutable::now(config('app.timezone'))->toDateString();
        } else {
            $snapshot = FinancialSnapshot::whereDate('snapshot_date', $asOf)->first();

            if (! $snapshot) {
                return null;
            }

            $position = $snapshot->only([
                'customer_wallets_total', 'house_wallet_balance', 'game_escrow_total', 'competition_escrow_total',
                'stuck_escrow_total', 'coin_liability', 'pending_holds_total', 'unmatched_deposits_total', 'excise_duty_payable', 'winnings_tax_payable', 'disputed_funds_total', 'referral_wallets_total', 'bet_shop_floats_total', 'shop_withdrawals_held_total', 'mpesa_balances',
            ]);
            $source = 'snapshot';
            $date = $asOf;
        }

        $accounts = [];
        foreach ($position['mpesa_balances'] ?? [] as $type => $named) {
            foreach ($named as $name => $balance) {
                $accounts[] = ['type' => $type, 'account' => $name, 'amount' => (float) $balance['amount'], 'as_of' => $balance['as_of']];
            }
        }
        $cash = array_sum(array_column($accounts, 'amount'));

        $liabilities = [
            'customer_wallets' => (float) $position['customer_wallets_total'],
            'game_escrow' => (float) $position['game_escrow_total'],
            'competition_escrow' => (float) $position['competition_escrow_total'],
            'stuck_escrow' => (float) $position['stuck_escrow_total'],
            'coin_liability' => (float) $position['coin_liability'],
            'pending_holds' => (float) $position['pending_holds_total'],
            'unmatched_deposits' => (float) $position['unmatched_deposits_total'],
            'excise_duty_payable' => (float) $position['excise_duty_payable'],
            'winnings_tax_payable' => (float) $position['winnings_tax_payable'],
            'disputed_funds' => (float) $position['disputed_funds_total'],
            'referral_wallets' => (float) $position['referral_wallets_total'],
            'bet_shop_floats' => (float) $position['bet_shop_floats_total'],
            'shop_withdrawals' => (float) $position['shop_withdrawals_held_total'],
        ];
        $totalLiabilities = array_sum($liabilities);
        $house = (float) $position['house_wallet_balance'];

        return $this->rounded([
            'as_of' => $date,
            'source' => $source,
            'assets' => ['cash' => ['accounts' => $accounts, 'total' => $cash], 'total' => $cash],
            'liabilities' => $liabilities + ['total' => $totalLiabilities],
            'house_wallet' => $house,
            'difference' => $cash - $totalLiabilities - $house,
            'notes' => [
                'difference is cash minus everything owed to customers minus the house wallet. It is not zero by design: it also holds gift and emoji sales not yet moved to the house wallet, M-Pesa charges, and timing between the hourly balance fetch and wallet movements.',
                'Test customers are excluded from customer wallets and coin liability.',
                'excise_duty_payable is duty taken from deposits and owed to KRA until a remittance is recorded. Snapshots taken before it was tracked show 0.',
                'winnings_tax_payable is tax withheld from house-banked wins, less credits for reversed wins, and owed to KRA until a remittance is recorded. It is negative when credits exceed charges. Snapshots taken before it was tracked show 0.',
                'disputed_funds is winnings held in dispute escrow while a complaint is pending. It is still owed to a customer, so it is a liability. Snapshots taken before complaints existed show 0.',
                'referral_wallets is referral bonuses not yet withdrawn. Customers can withdraw them at any time, so they are a liability. Snapshots taken before referrals existed show 0.',
                'bet_shop_floats is prepaid float bet shop owners paid in and have not yet loaded to players. It is owed to the shops, so it is a liability. Snapshots taken before bet shops existed show 0.',
                'shop_withdrawals is cash players asked to collect at a bet shop and have not collected yet. It has left their wallets but is still owed to them, so it is a liability. Snapshots taken before shop withdrawals existed show 0.',
            ],
        ]);
    }

    /**
     * Ledger movement by account and entry type. Deposits, withdrawals and manual adjustments have no
     * offsetting ledger entry, so every other entry must net to zero; anything left over is reported.
     *
     * @return array<string, mixed>
     */
    public function trialBalance(FinanceDateRange $range): array
    {
        $houseWalletId = (int) config('wallets.house_wallet_id', 1);

        $rows = DB::table('ledger_entries')
            ->whereBetween('created_at', [$range->from, $range->to])
            ->selectRaw('wallet_type, entry_type, (wallet_type = ? AND wallet_id = ?) as is_house, SUM(debit) as debit, SUM(credit) as credit, COUNT(*) as entries', [
                LedgerEntry::WALLET_TYPE_WALLET, $houseWalletId,
            ])
            ->groupBy('wallet_type', 'entry_type', 'is_house')
            ->orderBy('wallet_type')
            ->orderBy('entry_type')
            ->get();

        $lines = [];
        $totalDebit = $totalCredit = 0.0;
        $internalNet = 0.0;
        $internalByCategory = [];

        foreach ($rows as $row) {
            $category = $this->chart->categoryFor($row->entry_type);
            $account = $row->wallet_type === null
                ? 'unclassified'
                : $this->chart->accountFor($row->wallet_type, $row->is_house ? $houseWalletId : 0);
            $isCoin = $category === 'coin';

            $lines[] = [
                'account' => $account,
                'entry_type' => $row->entry_type,
                'category' => $category,
                'unit' => $row->wallet_type === LedgerEntry::WALLET_TYPE_COIN ? 'coins' : 'KES',
                'debit' => (float) $row->debit,
                'credit' => (float) $row->credit,
                'entries' => (int) $row->entries,
            ];

            if ($isCoin) {
                continue;
            }

            $totalDebit += (float) $row->debit;
            $totalCredit += (float) $row->credit;

            if (! in_array($category, ['cash_in', 'cash_out', 'adjustment', 'tax_withheld', 'referral_bonus', 'referral_payout', 'capital', 'shop_float'], true)) {
                $net = (float) $row->credit - (float) $row->debit;
                $internalNet += $net;
                $internalByCategory[$category] = ($internalByCategory[$category] ?? 0.0) + $net;
            }
        }

        $tolerance = (float) config('finance.reconciliation.tolerance');

        return $this->rounded([
            'lines' => $lines,
            'totals' => ['debit' => $totalDebit, 'credit' => $totalCredit],
            'check' => [
                'balanced' => abs($internalNet) <= $tolerance,
                'imbalance' => $internalNet,
                'imbalance_by_category' => array_filter($internalByCategory, fn (float $net) => abs($net) > $tolerance),
            ],
            'notes' => [
                'Deposits, withdrawals, adjustments, excise duty, referral bonuses, referral payouts, house funding (capital) and shop float purchases (shop_float) are single-sided in the ledger (the cash side is M-Pesa, excise duty is owed to KRA, and referral bonuses cost the house nothing until they are paid out from the referral shortcode). All other entries are paired and must net to zero, which is what check.imbalance measures. Promotion credits (promo_credit) are paired: the house wallet pays what the customer wallet receives.',
                'Coin entries are in coins, not KES, and are left out of the totals and the check.',
                'exclude_test does not apply here: the check only holds over the whole ledger.',
            ],
        ]);
    }

    /**
     * Customer money movements from the ledger over a window, for the dashboard summary.
     *
     * @return array{stakes: float, payouts: float, refunds: float, adjustments: float}
     */
    public function flows(FinanceDateRange $range): array
    {
        $houseWalletId = (int) config('wallets.house_wallet_id', 1);
        $excluded = $this->excludedCustomerIds($range);

        $rows = DB::table('ledger_entries')
            ->where('wallet_type', LedgerEntry::WALLET_TYPE_WALLET)
            ->where('wallet_id', '!=', $houseWalletId)
            ->whereBetween('created_at', [$range->from, $range->to])
            ->when($excluded !== [], fn (Builder $query) => $query->whereNotIn('customer_id', $excluded))
            ->selectRaw('entry_type, SUM(debit) as debit, SUM(credit) as credit')
            ->groupBy('entry_type')
            ->get();

        $totals = ['stakes' => 0.0, 'payouts' => 0.0, 'refunds' => 0.0, 'adjustments' => 0.0];

        foreach ($rows as $row) {
            $debit = (float) $row->debit;
            $credit = (float) $row->credit;

            match ($this->chart->categoryFor($row->entry_type)) {
                'stake' => $totals['stakes'] += $debit - $credit,
                'payout' => $totals['payouts'] += $credit - $debit,
                'refund' => $totals['refunds'] += $credit - $debit,
                'adjustment' => $totals['adjustments'] += $credit - $debit,
                default => null,
            };
        }

        return $this->rounded($totals);
    }

    /**
     * @return Collection<int, object{day: string, kind: string, amount: string}>
     */
    private function incomingPaymentRows(FinanceDateRange $range)
    {
        $customer = 'COALESCE(p.customer_id, l.customer_id)';
        $excluded = $this->excludedCustomerIds($range);

        return DB::table('incoming_payments as i')
            ->leftJoin('purchases as p', 'p.deposit_id', '=', 'i.id')
            ->leftJoin('ledger_entries as l', function ($join) {
                $join->on('l.referenceable_id', '=', 'i.id')
                    ->where('l.referenceable_type', Deposit::class)
                    ->where('l.entry_type', 'deposit');
            })
            ->leftJoin('house_fundings as h', fn ($join) => $join->on('h.deposit_id', '=', 'i.id')->whereNull('h.voided_at'))
            ->leftJoin('bet_shop_float_purchases as sf', fn ($join) => $join->on('sf.deposit_id', '=', 'i.id')->whereNull('sf.voided_at'))
            ->whereBetween('i.created_at', [$range->from, $range->to])
            ->when($excluded !== [], fn (Builder $query) => $query->whereRaw(
                "({$customer} IS NULL OR {$customer} NOT IN (".$this->placeholders($excluded).'))',
                $excluded
            ))
            ->selectRaw("DATE(i.created_at) as day, CASE WHEN h.id IS NOT NULL THEN 'house_funding' WHEN sf.id IS NOT NULL THEN 'shop_float' WHEN i.status IN (0, 4) THEN 'unmatched' WHEN p.purchase_type IS NOT NULL THEN p.purchase_type ELSE 'wallet_deposit' END as kind, SUM(i.trans_amount) as amount")
            ->groupBy('day', 'kind')
            ->get();
    }

    /**
     * @return Collection<int, object{day: string, disburse: int, amount: string}>
     */
    private function outgoingPaymentRows(FinanceDateRange $range)
    {
        $excluded = $this->excludedCustomerIds($range);

        return DB::table('outgoing_payments as o')
            ->leftJoin('transactions as t', 't.id', '=', 'o.transaction_id')
            ->leftJoin('wallets as w', 'w.id', '=', 't.wallet_id')
            ->whereBetween('o.created_at', [$range->from, $range->to])
            ->when($excluded !== [], fn (Builder $query) => $query->whereRaw(
                '(w.customer_id IS NULL OR w.customer_id NOT IN ('.$this->placeholders($excluded).'))',
                $excluded
            ))
            ->selectRaw('DATE(o.created_at) as day, o.disburse, SUM(o.amount) as amount')
            ->groupBy('day', 'o.disburse')
            ->get();
    }

    /**
     * @return Collection<int, object{day: string, source: ?string, game_type: ?int, jp_rounds: ?int, amount: string}>
     */
    private function houseCutRows(FinanceDateRange $range)
    {
        $excluded = $this->excludedCustomerIds($range);
        $source = "JSON_UNQUOTE(JSON_EXTRACT(l.metadata, '$.source'))";
        $player = "CASE {$source} WHEN 'game_credit' THEN gt.customer_id WHEN 'competition_bet' THEN ct.customer_id END";
        $marks = $this->placeholders($excluded);

        return DB::table('ledger_entries as l')
            ->leftJoin('game_transactions as gt', function ($join) {
                $join->on('gt.id', '=', 'l.referenceable_id')->where('l.referenceable_type', GameTransaction::class);
            })
            ->leftJoin('competition_transactions as ct', function ($join) {
                $join->on('ct.id', '=', 'l.referenceable_id')->where('l.referenceable_type', CompetitionTransaction::class);
            })
            ->leftJoin('competition_wallets as cw', 'cw.id', '=', 'ct.competition_wallet_id')
            ->whereIn('l.entry_type', ['house_cut', 'house_cut_reversal'])
            ->whereBetween('l.created_at', [$range->from, $range->to])
            ->when($excluded !== [], function (Builder $query) use ($excluded, $source, $player, $marks) {
                $query->whereRaw("({$player} IS NULL OR {$player} NOT IN ({$marks}))", $excluded)
                    ->whereRaw(
                        "NOT (COALESCE({$source}, '') IN ('game_withdrawal', 'game_drop_payout') AND EXISTS ("
                        ."SELECT 1 FROM game_transactions g2 WHERE g2.game_wallet_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(l.metadata, '$.game_wallet_id')) AS UNSIGNED) "
                        ."AND g2.payment_type = 'deposit' AND g2.customer_id IN ({$marks})))",
                        $excluded
                    );
            })
            ->selectRaw("DATE(l.created_at) as day, {$source} as source, cw.game_type as game_type, cw.jp_rounds as jp_rounds, SUM(l.credit - l.debit) as amount")
            ->groupByRaw("DATE(l.created_at), {$source}, cw.game_type, cw.jp_rounds")
            ->get();
    }

    /**
     * @return Collection<int, object{day: string, purchase_type: string, amount: string}>
     */
    private function giftEmojiRows(FinanceDateRange $range)
    {
        $excluded = $this->excludedCustomerIds($range);

        return DB::table('purchases')
            ->whereIn('purchase_type', ['gift', 'emoji'])
            ->where('test', 0)
            ->whereBetween('created_at', [$range->from, $range->to])
            ->when($excluded !== [], fn (Builder $query) => $query->whereNotIn('customer_id', $excluded))
            ->selectRaw('DATE(created_at) as day, purchase_type, SUM(amount) as amount')
            ->groupBy('day', 'purchase_type')
            ->get();
    }

    /**
     * @return array{cash_received: float, wallet_credited: float, margin: float}
     */
    private function loadMargin(FinanceDateRange $range): array
    {
        $excluded = $this->excludedCustomerIds($range);

        $row = DB::table('purchases')
            ->where('purchase_type', 'load')
            ->where('test', 0)
            ->whereBetween('created_at', [$range->from, $range->to])
            ->when($excluded !== [], fn (Builder $query) => $query->whereNotIn('customer_id', $excluded))
            ->selectRaw('COALESCE(SUM(amount), 0) as cash, COALESCE(SUM(value), 0) as credited')
            ->first();

        return [
            'cash_received' => (float) $row->cash,
            'wallet_credited' => (float) $row->credited,
            'margin' => (float) $row->cash - (float) $row->credited,
        ];
    }

    private function revenueLine(string $source, ?int $gameType): string
    {
        return match ($source) {
            'game_credit', 'game_withdrawal', 'game_drop_payout' => 'games',
            'competition_bet' => match ($gameType) {
                1 => 'tournaments',
                2 => 'jackpots',
                default => 'competitions_unattributed',
            },
            default => 'other',
        };
    }

    /**
     * @return list<int>
     */
    private function excludedCustomerIds(FinanceDateRange $range): array
    {
        return $range->excludeTest ? array_values(config('finance.test_customer_ids')) : [];
    }

    /**
     * @param  list<int>  $values
     */
    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, max(count($values), 1), '?'));
    }

    private function bucket(FinanceDateRange $range, string $day): string
    {
        return $range->bucketFor(CarbonImmutable::parse($day, config('app.timezone')));
    }

    /**
     * Round every float to cents so results are stable and JSON friendly.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function rounded(array $values): array
    {
        return array_map(function ($value) {
            return match (true) {
                is_array($value) => $this->rounded($value),
                is_float($value) => round($value, 2),
                default => $value,
            };
        }, $values);
    }
}
