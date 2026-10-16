<?php

namespace App\Services;

use App\Models\CompetitionTransaction;
use App\Models\Complaint;
use App\Models\Deposit;
use App\Models\DepositResolution;
use App\Models\DisputedTransaction;
use App\Models\GameTransaction;
use App\Models\LedgerEntry;
use App\Models\MpesaBalance;
use App\Models\ReferralWithdrawal;
use App\Models\Withdraw;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FinanceReconciliationService
{
    private const SAMPLE_SIZE = 10;

    public function __construct(
        private FinanceReportService $reports,
        private FinanceSnapshotService $snapshots,
        private ExciseDutyReportService $exciseDuty,
    ) {}

    /**
     * Run every control. Balance checks look at current state; checks that scan entries or payments
     * use the range.
     *
     * @return array{status: string, counts: array<string, int>, checks: list<array<string, mixed>>}
     */
    public function run(FinanceDateRange $range): array
    {
        $checks = [
            $this->ledgerDrift('customer_wallet_drift', 'Customer wallet balances match the ledger', 'wallets', LedgerEntry::WALLET_TYPE_WALLET, false),
            $this->ledgerDrift('game_wallet_drift', 'Game wallet balances match the ledger', 'game_wallets', LedgerEntry::WALLET_TYPE_GAME, true),
            $this->ledgerDrift('competition_wallet_drift', 'Competition wallet balances match the ledger', 'competition_wallets', LedgerEntry::WALLET_TYPE_COMPETITION, true),
            $this->ledgerDrift('dispute_escrow_drift', 'Dispute escrow balances match the ledger', 'disputed_transactions', LedgerEntry::WALLET_TYPE_DISPUTE, false),
            $this->ledgerDrift('referral_wallet_drift', 'Referral wallet balances match the ledger', 'referral_wallets', LedgerEntry::WALLET_TYPE_REFERRAL, false),
            $this->ledgerDrift('bet_shop_float_drift', 'Bet shop float balances match the ledger', 'bet_shop_wallets', LedgerEntry::WALLET_TYPE_BET_SHOP, false),
            $this->ledgerChain($range),
            $this->ledgerIdentity($range),
            $this->unclassifiedEntries(),
            $this->unmatchedDeposits(),
            $this->depositResolutions(),
            $this->depositsWithoutLedger($range),
            $this->depositsWithoutExciseDuty($range),
            $this->exciseDutyAmounts($range),
            $this->exciseDutyLedger($range),
            $this->overdueExciseDuty(),
            $this->stuckWithdrawals(),
            $this->failedWithdrawalsNotReversed(),
            $this->stuckEscrow(),
            $this->agedEscrow(),
            $this->negativeBalances(),
            $this->heldOnClosedComplaints(),
            $this->agedDisputes(),
            $this->stuckReferralWithdrawals(),
            $this->failedReferralWithdrawalsNotReversed(),
            $this->referralBonusesWithoutVerification(),
            $this->promotionCreditsLedger($range),
            $this->houseFundingLedger($range),
            $this->shopFloatLedger($range),
            $this->shopLoadsLedger($range),
            $this->houseCutRates($range),
            $this->mpesaBalanceFreshness(),
            $this->cashCoverage(),
        ];

        $counts = array_count_values(array_column($checks, 'status')) + ['pass' => 0, 'warn' => 0, 'fail' => 0];

        return [
            'status' => $counts['fail'] > 0 ? 'fail' : ($counts['warn'] > 0 ? 'warn' : 'pass'),
            'counts' => ['pass' => $counts['pass'], 'warn' => $counts['warn'], 'fail' => $counts['fail']],
            'checks' => $checks,
        ];
    }

    /**
     * Wallet balance against opening balance plus ledger credits minus debits. Game and competition
     * wallets are only checked from the day escrow releases were ledgered.
     *
     * @return array<string, mixed>
     */
    private function ledgerDrift(string $key, string $title, string $table, string $walletType, bool $sinceReliable): array
    {
        $net = DB::table('ledger_entries')
            ->where('wallet_type', $walletType)
            ->whereIn('status', ['settled', 'reversed'])
            ->selectRaw('wallet_id, MIN(id) as first_id, SUM(credit) - SUM(debit) as net')
            ->groupBy('wallet_id');

        $drifting = DB::table("{$table} as w")
            ->joinSub($net, 'n', 'n.wallet_id', '=', 'w.id')
            ->join('ledger_entries as f', 'f.id', '=', 'n.first_id')
            ->whereRaw('ABS(w.balance - (f.balance_before + n.net)) > ?', [$this->tolerance()])
            ->when($sinceReliable, fn ($query) => $query->where('w.created_at', '>=', $this->reliableFrom()));

        $totals = (clone $drifting)
            ->selectRaw('COUNT(*) as wallets, COALESCE(SUM(ABS(w.balance - (f.balance_before + n.net))), 0) as amount')
            ->first();

        $samples = (clone $drifting)
            ->selectRaw('w.id, w.balance, ROUND(f.balance_before + n.net, 2) as expected, ROUND(w.balance - (f.balance_before + n.net), 2) as drift')
            ->orderByRaw('ABS(w.balance - (f.balance_before + n.net)) DESC')
            ->limit(self::SAMPLE_SIZE)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'balance' => (float) $row->balance,
                'expected' => (float) $row->expected,
                'drift' => (float) $row->drift,
            ])->all();

        return $this->result($key, $title, 'fail', (int) $totals->wallets, (float) $totals->amount, $samples,
            'Balance differs from the ledger. Manual changes made before adjustments were ledgered show up here.');
    }

    /**
     * Every ledger row must satisfy balance_before + credit - debit = balance_after.
     *
     * @return array<string, mixed>
     */
    private function ledgerChain(FinanceDateRange $range): array
    {
        $broken = DB::table('ledger_entries')
            ->whereBetween('created_at', [$range->from, $range->to])
            ->whereRaw('ABS(balance_before + credit - debit - balance_after) > ?', [$this->tolerance()]);

        $totals = (clone $broken)
            ->selectRaw('COUNT(*) as entries, COALESCE(SUM(ABS(balance_before + credit - debit - balance_after)), 0) as amount')
            ->first();

        $samples = (clone $broken)
            ->select('id', 'entry_id', 'entry_type', 'wallet_type', 'wallet_id', 'balance_before', 'debit', 'credit', 'balance_after')
            ->limit(self::SAMPLE_SIZE)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        return $this->result('ledger_arithmetic', 'Ledger entries add up (before + credit - debit = after)', 'fail', (int) $totals->entries, (float) $totals->amount, $samples);
    }

    /**
     * Paired ledger entries must net to zero (see the trial balance), from the day escrow releases were ledgered.
     *
     * @return array<string, mixed>
     */
    private function ledgerIdentity(FinanceDateRange $range): array
    {
        $title = 'Paired ledger entries net to zero';
        $reliableFrom = CarbonImmutable::parse($this->reliableFrom(), config('app.timezone'))->startOfDay();
        $from = $range->from->greaterThan($reliableFrom) ? $range->from : $reliableFrom;

        if ($from->greaterThan($range->to)) {
            return $this->result('ledger_balance', $title, 'fail', 0, 0.0, [], 'The range is entirely before escrow releases were ledgered, so it was not checked.');
        }

        $trial = $this->reports->trialBalance(new FinanceDateRange($from, $range->to, $range->groupBy, false));
        $check = $trial['check'];

        $samples = [];
        foreach ($check['imbalance_by_category'] as $category => $net) {
            $samples[] = ['category' => $category, 'net' => $net];
        }

        return $this->result('ledger_balance', $title, 'fail', $check['balanced'] ? 0 : 1, abs((float) $check['imbalance']), $samples,
            "Checked from {$from->toDateString()}. A positive net means money was credited without a matching debit.");
    }

    /**
     * @return array<string, mixed>
     */
    private function unclassifiedEntries(): array
    {
        $unclassified = LedgerEntry::whereNull('wallet_type');

        return $this->result(
            'unclassified_entries',
            'Ledger entries have a wallet type',
            'warn',
            $unclassified->count(),
            0.0,
            $unclassified->limit(self::SAMPLE_SIZE)->get(['id', 'entry_type', 'wallet_id'])->toArray(),
            'Run ledger:backfill-wallet-type.'
        );
    }

    /**
     * Each resolved unmatched deposit agrees with its resolution: assigned ones are completed (status 2) and
     * point at a ledger credit, refunded ones are status 4, and no deposit is marked refunded without a record.
     *
     * @return array<string, mixed>
     */
    private function depositResolutions(): array
    {
        $assignedWrong = DB::table('deposit_resolutions as r')
            ->join('incoming_payments as i', 'i.id', '=', 'r.deposit_id')
            ->leftJoin('ledger_entries as l', 'l.id', '=', 'r.ledger_entry_id')
            ->where('r.action', DepositResolution::ACTION_ASSIGNED)
            ->where(fn ($query) => $query->where('i.status', '!=', Deposit::STATUS_COMPLETED)->orWhereNull('l.id'))
            ->get(['i.id', 'i.trans_id', 'i.trans_amount', 'i.status', 'r.action']);

        $refundedWrong = DB::table('deposit_resolutions as r')
            ->join('incoming_payments as i', 'i.id', '=', 'r.deposit_id')
            ->where('r.action', DepositResolution::ACTION_REFUNDED)
            ->where('i.status', '!=', Deposit::STATUS_REFUNDED)
            ->get(['i.id', 'i.trans_id', 'i.trans_amount', 'i.status', 'r.action']);

        $houseFundedWrong = DB::table('deposit_resolutions as r')
            ->join('incoming_payments as i', 'i.id', '=', 'r.deposit_id')
            ->leftJoin('ledger_entries as l', 'l.id', '=', 'r.ledger_entry_id')
            ->where('r.action', DepositResolution::ACTION_HOUSE_FUNDED)
            ->where(fn ($query) => $query->where('i.status', '!=', Deposit::STATUS_COMPLETED)->orWhereNull('l.id')->orWhere('l.entry_type', '!=', 'house_funding'))
            ->get(['i.id', 'i.trans_id', 'i.trans_amount', 'i.status', 'r.action']);

        $refundedWithoutRecord = DB::table('incoming_payments as i')
            ->leftJoin('deposit_resolutions as r', 'r.deposit_id', '=', 'i.id')
            ->where('i.status', Deposit::STATUS_REFUNDED)
            ->whereNull('r.id')
            ->get(['i.id', 'i.trans_id', 'i.trans_amount', 'i.status']);

        $shopFloatWrong = DB::table('deposit_resolutions as r')
            ->join('incoming_payments as i', 'i.id', '=', 'r.deposit_id')
            ->leftJoin('ledger_entries as l', 'l.id', '=', 'r.ledger_entry_id')
            ->where('r.action', DepositResolution::ACTION_SHOP_FLOAT)
            ->where(fn ($query) => $query->where('i.status', '!=', Deposit::STATUS_COMPLETED)->orWhereNull('l.id')->orWhere('l.entry_type', '!=', 'shop_float_purchase'))
            ->get(['i.id', 'i.trans_id', 'i.trans_amount', 'i.status', 'r.action']);

        $rows = $assignedWrong->concat($refundedWrong)->concat($houseFundedWrong)->concat($shopFloatWrong)->concat($refundedWithoutRecord);

        return $this->result(
            'deposit_resolutions',
            'Resolved unmatched deposits agree with their records',
            'fail',
            $rows->count(),
            (float) $rows->sum('trans_amount'),
            $rows->take(self::SAMPLE_SIZE)->map(fn ($row) => (array) $row)->values()->all(),
            'An assigned deposit must be completed with a ledger credit; a house-funded deposit must be completed with a house_funding credit; a shop float deposit must be completed with a shop_float_purchase credit; a refunded deposit must have status 4 and a refund record.'
        );
    }

    /**
     * Money received from customers that were not found, so it was never credited.
     *
     * @return array<string, mixed>
     */
    private function unmatchedDeposits(): array
    {
        $deposits = DB::table('incoming_payments')->where('status', 0);

        return $this->result(
            'unmatched_deposits',
            'Deposits from unknown customers',
            'warn',
            (clone $deposits)->count(),
            (float) (clone $deposits)->sum('trans_amount'),
            (clone $deposits)->latest('id')->limit(self::SAMPLE_SIZE)->get(['id', 'trans_id', 'msisdn', 'bill_ref_no', 'trans_amount', 'created_at'])->map(fn ($row) => (array) $row)->all(),
            'Cash is held but not credited to anyone. Assign it to the right customer or record the refund (POST /deposits/{id}/assign or /refund), or run deposits:match-unmatched.'
        );
    }

    /**
     * Processed deposits that should have credited a wallet but have no ledger entry.
     *
     * @return array<string, mixed>
     */
    private function depositsWithoutLedger(FinanceDateRange $range): array
    {
        $missing = DB::table('incoming_payments as i')
            ->leftJoin('ledger_entries as l', function ($join) {
                $join->on('l.referenceable_id', '=', 'i.id')
                    ->where('l.referenceable_type', Deposit::class)
                    ->whereIn('l.entry_type', ['deposit', 'house_funding', 'shop_float_purchase']);
            })
            ->leftJoin('purchases as p', 'p.deposit_id', '=', 'i.id')
            ->where('i.status', 2)
            ->whereNull('l.id')
            ->where(fn ($query) => $query->whereNull('p.purchase_type')->orWhereNotIn('p.purchase_type', ['gift', 'emoji']))
            ->where('i.created_at', '>=', config('finance.ledger_started_at'))
            ->whereBetween('i.created_at', [$range->from, $range->to]);

        return $this->result(
            'deposits_without_ledger',
            'Processed deposits are in the ledger',
            'fail',
            (clone $missing)->count(),
            (float) (clone $missing)->sum('i.trans_amount'),
            (clone $missing)->limit(self::SAMPLE_SIZE)->get(['i.id', 'i.trans_id', 'i.trans_amount', 'i.created_at'])->map(fn ($row) => (array) $row)->all()
        );
    }

    /**
     * Plain wallet deposits credited while excise duty was on, with no excise duty charge.
     *
     * @return array<string, mixed>
     */
    private function depositsWithoutExciseDuty(FinanceDateRange $range): array
    {
        $title = 'Wallet deposits have their excise duty charged';

        if (! config('finance.excise_duty.enabled')) {
            return $this->result('deposits_without_excise_duty', $title, 'fail', 0, 0.0, [], 'Excise duty is switched off, so it was not checked.');
        }

        $effectiveFrom = config('finance.excise_duty.effective_from');

        $missing = DB::table('incoming_payments as i')
            ->join('ledger_entries as l', function ($join) {
                $join->on('l.referenceable_id', '=', 'i.id')
                    ->where('l.referenceable_type', Deposit::class)
                    ->where('l.entry_type', 'deposit');
            })
            ->leftJoin('purchases as p', 'p.deposit_id', '=', 'i.id')
            ->leftJoin('excise_duty_charges as x', 'x.deposit_id', '=', 'i.id')
            ->where('i.status', 2)
            ->where('i.trans_amount', '>', 0)
            ->whereNull('p.id')
            ->whereNull('x.id')
            ->when(filled($effectiveFrom), fn ($query) => $query->where('i.created_at', '>=', CarbonImmutable::parse($effectiveFrom, config('app.timezone'))->startOfDay()))
            ->whereBetween('i.created_at', [$range->from, $range->to]);

        return $this->result(
            'deposits_without_excise_duty',
            $title,
            'fail',
            (clone $missing)->count(),
            (float) (clone $missing)->sum('i.trans_amount'),
            (clone $missing)->limit(self::SAMPLE_SIZE)->get(['i.id', 'i.trans_id', 'i.trans_amount', 'i.created_at'])->map(fn ($row) => (array) $row)->all(),
            'amount is the gross of the deposits that were credited in full.'
        );
    }

    /**
     * Each charge is round(gross x rate, 2), and gross = excise + net.
     *
     * @return array<string, mixed>
     */
    private function exciseDutyAmounts(FinanceDateRange $range): array
    {
        $wrong = DB::table('excise_duty_charges')
            ->whereBetween('charged_at', [$range->from, $range->to])
            ->where(fn ($query) => $query
                ->whereRaw('ABS(excise_amount - ROUND(gross_amount * rate, 2)) > ?', [$this->tolerance()])
                ->orWhereRaw('ABS(gross_amount - excise_amount - net_amount) > ?', [$this->tolerance()]));

        return $this->result(
            'excise_duty_amounts',
            'Excise duty charges are worked out correctly',
            'fail',
            (clone $wrong)->count(),
            (float) (clone $wrong)->selectRaw('COALESCE(SUM(ABS(excise_amount - ROUND(gross_amount * rate, 2))), 0) as amount')->value('amount'),
            (clone $wrong)->limit(self::SAMPLE_SIZE)->get(['id', 'deposit_id', 'gross_amount', 'rate', 'excise_amount', 'net_amount'])->map(fn ($row) => (array) $row)->all()
        );
    }

    /**
     * Each charge points at its excise_duty ledger entry, for the same amount, in the matching state.
     *
     * @return array<string, mixed>
     */
    private function exciseDutyLedger(FinanceDateRange $range): array
    {
        $mismatched = DB::table('excise_duty_charges as x')
            ->leftJoin('ledger_entries as l', 'l.id', '=', 'x.ledger_entry_id')
            ->whereBetween('x.charged_at', [$range->from, $range->to])
            ->where(fn ($query) => $query
                ->whereNull('l.id')
                ->orWhere('l.entry_type', '!=', 'excise_duty')
                ->orWhereRaw('ABS(l.debit - x.excise_amount) > ?', [$this->tolerance()])
                ->orWhereRaw("(x.status = 'charged' AND l.status != 'settled')")
                ->orWhereRaw("(x.status = 'reversed' AND l.status != 'reversed')"));

        return $this->result(
            'excise_duty_ledger',
            'Excise duty charges match the ledger',
            'fail',
            (clone $mismatched)->count(),
            (float) (clone $mismatched)->sum('x.excise_amount'),
            (clone $mismatched)->limit(self::SAMPLE_SIZE)->get(['x.id', 'x.deposit_id', 'x.excise_amount', 'x.status', 'l.id as ledger_entry_id', 'l.debit', 'l.status as ledger_status'])->map(fn ($row) => (array) $row)->all()
        );
    }

    /**
     * Months whose excise duty is still unpaid after the filing day of the next month.
     *
     * @return array<string, mixed>
     */
    private function overdueExciseDuty(): array
    {
        $overdue = $this->exciseDuty->overdueReturns();

        return $this->result(
            'overdue_excise_duty',
            'Excise duty is paid to KRA by the due date',
            'warn',
            count($overdue),
            array_sum(array_column($overdue, 'outstanding')),
            array_slice($overdue, 0, self::SAMPLE_SIZE),
            'Pay KRA, then record it with POST /finance/excise-duty/remittances.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function stuckWithdrawals(): array
    {
        $hours = (int) config('finance.reconciliation.stuck_withdrawal_hours');
        $stuck = DB::table('outgoing_payments')
            ->where('disburse', 1)
            ->where('created_at', '<=', now()->subHours($hours));

        return $this->result(
            'stuck_withdrawals',
            "Withdrawals pending over {$hours} hours",
            'warn',
            (clone $stuck)->count(),
            (float) (clone $stuck)->sum('amount'),
            (clone $stuck)->oldest('id')->limit(self::SAMPLE_SIZE)->get(['id', 'transaction_id', 'amount', 'created_at'])->map(fn ($row) => (array) $row)->all()
        );
    }

    /**
     * A failed payout whose wallet debit was never returned.
     *
     * @return array<string, mixed>
     */
    private function failedWithdrawalsNotReversed(): array
    {
        $lost = DB::table('outgoing_payments as o')
            ->join('ledger_entries as l', function ($join) {
                $join->on('l.referenceable_id', '=', 'o.id')
                    ->where('l.referenceable_type', Withdraw::class)
                    ->where('l.entry_type', 'withdrawal')
                    ->where('l.status', 'settled');
            })
            ->where('o.disburse', 3);

        return $this->result(
            'failed_withdrawals_not_reversed',
            'Failed withdrawals were returned to the wallet',
            'fail',
            (clone $lost)->count(),
            (float) (clone $lost)->sum('o.amount'),
            (clone $lost)->limit(self::SAMPLE_SIZE)->get(['o.id', 'o.amount', 'o.error_message'])->map(fn ($row) => (array) $row)->all(),
            'The customer was debited but the payout failed.'
        );
    }

    /**
     * Money left in a game or competition wallet that is no longer open.
     *
     * @return array<string, mixed>
     */
    private function stuckEscrow(): array
    {
        $rows = collect(['game_wallets' => 'game', 'competition_wallets' => 'competition'])
            ->flatMap(fn (string $kind, string $table) => DB::table($table)
                ->where('status', '!=', 1)->where('balance', '>', 0)
                ->get(['id', 'status', 'balance'])
                ->map(fn ($row) => ['kind' => $kind, 'id' => (int) $row->id, 'status' => (int) $row->status, 'balance' => (float) $row->balance]));

        return $this->result(
            'stuck_escrow',
            'Closed game and competition wallets hold no money',
            'warn',
            $rows->count(),
            (float) $rows->sum('balance'),
            $rows->sortByDesc('balance')->take(self::SAMPLE_SIZE)->values()->all(),
            'Money is still recorded against wallets that are finished.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function agedEscrow(): array
    {
        $hours = (int) config('finance.reconciliation.stuck_game_hours');
        $cutoff = now()->subHours($hours);

        $rows = collect(['game_wallets' => 'game', 'competition_wallets' => 'competition'])
            ->flatMap(fn (string $kind, string $table) => DB::table($table)
                ->where('status', 1)->where('balance', '>', 0)->where('updated_at', '<=', $cutoff)
                ->get(['id', 'balance', 'updated_at'])
                ->map(fn ($row) => ['kind' => $kind, 'id' => (int) $row->id, 'balance' => (float) $row->balance, 'updated_at' => $row->updated_at]));

        return $this->result(
            'aged_escrow',
            "Open game and competition wallets untouched over {$hours} hours",
            'warn',
            $rows->count(),
            (float) $rows->sum('balance'),
            $rows->sortByDesc('balance')->take(self::SAMPLE_SIZE)->values()->all()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function negativeBalances(): array
    {
        $rows = collect(['wallets' => 'customer', 'game_wallets' => 'game', 'competition_wallets' => 'competition', 'referral_wallets' => 'referral', 'bet_shop_wallets' => 'bet_shop'])
            ->flatMap(fn (string $kind, string $table) => DB::table($table)
                ->where('balance', '<', 0)
                ->get(['id', 'balance'])
                ->map(fn ($row) => ['kind' => $kind, 'id' => (int) $row->id, 'balance' => (float) $row->balance]));

        return $this->result(
            'negative_balances',
            'No wallet has a negative balance',
            'fail',
            $rows->count(),
            abs((float) $rows->sum('balance')),
            $rows->sortBy('balance')->take(self::SAMPLE_SIZE)->values()->all()
        );
    }

    /**
     * Money still in dispute escrow for a complaint that is no longer pending.
     *
     * @return array<string, mixed>
     */
    private function heldOnClosedComplaints(): array
    {
        $rows = DB::table('disputed_transactions as d')
            ->join('complaints as c', 'c.id', '=', 'd.complaint_id')
            ->where('c.status', '!=', Complaint::STATUS_PENDING)
            ->where(fn ($query) => $query->where('d.balance', '>', 0)->orWhere('d.status', DisputedTransaction::STATUS_HELD))
            ->get(['d.id', 'd.complaint_id', 'c.status as complaint_status', 'd.status', 'd.balance']);

        return $this->result(
            'held_on_closed_complaints',
            'Closed complaints hold no money',
            'fail',
            $rows->count(),
            (float) $rows->sum('balance'),
            $rows->take(self::SAMPLE_SIZE)->map(fn ($row) => [
                'disputed_transaction_id' => (int) $row->id,
                'complaint_id' => (int) $row->complaint_id,
                'complaint_status' => $row->complaint_status,
                'status' => $row->status,
                'balance' => (float) $row->balance,
            ])->all()
        );
    }

    /**
     * Complaints left pending too long, with the money they hold.
     *
     * @return array<string, mixed>
     */
    private function agedDisputes(): array
    {
        $days = (int) config('finance.reconciliation.aged_dispute_days');

        $rows = Complaint::pending()
            ->where('created_at', '<=', now()->subDays($days))
            ->withSum(['disputedTransactions as held' => fn ($query) => $query->where('status', DisputedTransaction::STATUS_HELD)], 'balance')
            ->orderBy('created_at')
            ->get(['id', 'subject_type', 'created_at']);

        return $this->result(
            'aged_disputes',
            "Complaints pending over {$days} days",
            'warn',
            $rows->count(),
            (float) $rows->sum('held'),
            $rows->take(self::SAMPLE_SIZE)->map(fn (Complaint $complaint) => [
                'complaint_id' => $complaint->id,
                'subject_type' => $complaint->subject_type,
                'held' => round((float) $complaint->held, 2),
                'filed_at' => $complaint->created_at->toIso8601String(),
            ])->all(),
            'Resolve, reject or cancel them so the held money reaches the right players.'
        );
    }

    /**
     * Referral withdrawals still pending or waiting for Safaricom's result. They are never refunded
     * automatically, because Safaricom may still have paid them: check the payout on the referral shortcode.
     *
     * @return array<string, mixed>
     */
    private function stuckReferralWithdrawals(): array
    {
        $hours = (int) config('finance.reconciliation.stuck_withdrawal_hours');
        $stuck = ReferralWithdrawal::open()->where('created_at', '<=', now()->subHours($hours));

        return $this->result(
            'stuck_referral_withdrawals',
            "Referral withdrawals open over {$hours} hours",
            'warn',
            (clone $stuck)->count(),
            (float) (clone $stuck)->sum('amount'),
            (clone $stuck)->oldest('id')->limit(self::SAMPLE_SIZE)->get(['id', 'customer_id', 'amount', 'status', 'conversation_id', 'created_at'])->toArray(),
            'Look the payout up on the referral shortcode (M-Pesa org portal) before doing anything: a missing result does not mean it was not paid.'
        );
    }

    /**
     * A failed referral withdrawal whose referral wallet debit was never given back.
     *
     * @return array<string, mixed>
     */
    private function failedReferralWithdrawalsNotReversed(): array
    {
        $rows = ReferralWithdrawal::where('status', ReferralWithdrawal::STATUS_FAILED)->whereNull('reversal_entry_id');

        return $this->result(
            'failed_referral_withdrawals_not_reversed',
            'Failed referral withdrawals were refunded to the referral wallet',
            'fail',
            (clone $rows)->count(),
            (float) (clone $rows)->sum('amount'),
            (clone $rows)->limit(self::SAMPLE_SIZE)->get(['id', 'customer_id', 'amount', 'failed_at'])->toArray()
        );
    }

    /**
     * Bonuses are only paid for verified referrals; a bonus on an unverified referral means the rule was bypassed.
     *
     * @return array<string, mixed>
     */
    private function referralBonusesWithoutVerification(): array
    {
        $rows = DB::table('referral_bonuses as b')
            ->join('referrals as r', 'r.id', '=', 'b.referral_id')
            ->whereNull('r.verified_at');

        return $this->result(
            'referral_bonuses_unverified',
            'Referral bonuses were only paid for verified referrals',
            'fail',
            (clone $rows)->count(),
            (float) (clone $rows)->sum('b.amount'),
            (clone $rows)->limit(self::SAMPLE_SIZE)->get(['b.id', 'b.referral_id', 'b.customer_id', 'b.milestone', 'b.amount'])->map(fn ($row) => (array) $row)->all()
        );
    }

    /**
     * Each promotion credit has its customer credit and house debit in the ledger for the gross amount, and
     * an excise duty charge when duty was taken.
     *
     * @return array<string, mixed>
     */
    private function promotionCreditsLedger(FinanceDateRange $range): array
    {
        $tolerance = $this->tolerance();

        $mismatched = DB::table('promotion_credits as p')
            ->leftJoin('ledger_entries as c', 'c.id', '=', 'p.ledger_entry_id')
            ->leftJoin('ledger_entries as h', 'h.id', '=', 'p.house_ledger_entry_id')
            ->leftJoin('excise_duty_charges as x', 'x.promotion_credit_id', '=', 'p.id')
            ->whereBetween('p.created_at', [$range->from, $range->to])
            ->where(fn ($query) => $query
                ->whereNull('c.id')
                ->orWhereNull('h.id')
                ->orWhere('c.entry_type', '!=', 'promo_credit')
                ->orWhere('h.entry_type', '!=', 'promo_credit')
                ->orWhereRaw('ABS(c.credit - p.gross_amount) > ?', [$tolerance])
                ->orWhereRaw('ABS(h.debit - p.gross_amount) > ?', [$tolerance])
                ->orWhereRaw('(p.excise_amount > 0 AND x.id IS NULL)')
                ->orWhereRaw('ABS(p.gross_amount - p.excise_amount - p.net_amount) > ?', [$tolerance]));

        return $this->result(
            'promotion_credits_ledger',
            'Promotion credits match the ledger',
            'fail',
            (clone $mismatched)->count(),
            (float) (clone $mismatched)->sum('p.gross_amount'),
            (clone $mismatched)->limit(self::SAMPLE_SIZE)->get(['p.id', 'p.customer_id', 'p.gross_amount', 'p.excise_amount', 'p.net_amount', 'c.id as ledger_entry_id', 'h.id as house_ledger_entry_id', 'x.id as excise_charge_id'])->map(fn ($row) => (array) $row)->all()
        );
    }

    /**
     * Each shop float purchase has its credit to the shop's float for the full deposit, and each voided one
     * has its reversal.
     *
     * @return array<string, mixed>
     */
    private function shopFloatLedger(FinanceDateRange $range): array
    {
        $tolerance = $this->tolerance();

        $mismatched = DB::table('bet_shop_float_purchases as f')
            ->join('incoming_payments as i', 'i.id', '=', 'f.deposit_id')
            ->join('bet_shop_wallets as w', 'w.bet_shop_id', '=', 'f.bet_shop_id')
            ->leftJoin('ledger_entries as l', 'l.id', '=', 'f.ledger_entry_id')
            ->leftJoin('ledger_entries as v', 'v.id', '=', 'f.void_ledger_entry_id')
            ->whereBetween('f.created_at', [$range->from, $range->to])
            ->where(fn ($query) => $query
                ->whereNull('l.id')
                ->orWhere('l.entry_type', '!=', 'shop_float_purchase')
                ->orWhere('l.wallet_type', '!=', LedgerEntry::WALLET_TYPE_BET_SHOP)
                ->orWhereColumn('l.wallet_id', '!=', 'w.id')
                ->orWhereRaw('ABS(l.credit - f.amount) > ?', [$tolerance])
                ->orWhereRaw('ABS(f.amount - i.trans_amount) > ?', [$tolerance])
                ->orWhereRaw('(f.voided_at IS NULL AND i.status <> ?)', [Deposit::STATUS_COMPLETED])
                ->orWhereRaw("(f.voided_at IS NOT NULL AND (v.id IS NULL OR v.entry_type <> 'shop_float_purchase_reversal' OR ABS(v.debit - f.amount) > ?))", [$tolerance]));

        return $this->result(
            'shop_float_ledger',
            'Bet shop float purchases match the ledger',
            'fail',
            (clone $mismatched)->count(),
            (float) (clone $mismatched)->sum('f.amount'),
            (clone $mismatched)->limit(self::SAMPLE_SIZE)->get(['f.id', 'f.bet_shop_id', 'f.deposit_id', 'i.trans_id', 'f.amount', 'f.voided_at', 'l.id as ledger_entry_id', 'v.id as void_ledger_entry_id'])->map(fn ($row) => (array) $row)->all()
        );
    }

    /**
     * Each shop load moved its gross amount from the shop float to the player's wallet (a matching pair of
     * shop_load entries), and gross = excise + net.
     *
     * @return array<string, mixed>
     */
    private function shopLoadsLedger(FinanceDateRange $range): array
    {
        $tolerance = $this->tolerance();

        $mismatched = DB::table('bet_shop_loads as s')
            ->leftJoin('ledger_entries as f', 'f.id', '=', 's.shop_ledger_entry_id')
            ->leftJoin('ledger_entries as c', 'c.id', '=', 's.customer_ledger_entry_id')
            ->whereBetween('s.created_at', [$range->from, $range->to])
            ->where(fn ($query) => $query
                ->whereNull('f.id')
                ->orWhereNull('c.id')
                ->orWhere('f.entry_type', '!=', 'shop_load')
                ->orWhere('c.entry_type', '!=', 'shop_load')
                ->orWhere('f.wallet_type', '!=', LedgerEntry::WALLET_TYPE_BET_SHOP)
                ->orWhere('c.wallet_type', '!=', LedgerEntry::WALLET_TYPE_WALLET)
                ->orWhereColumn('c.wallet_id', '!=', 's.wallet_id')
                ->orWhereRaw('ABS(f.debit - s.gross_amount) > ?', [$tolerance])
                ->orWhereRaw('ABS(c.credit - s.gross_amount) > ?', [$tolerance])
                ->orWhereRaw('ABS(s.gross_amount - s.excise_amount - s.net_amount) > ?', [$tolerance]));

        return $this->result(
            'shop_loads_ledger',
            'Bet shop loads match the ledger',
            'fail',
            (clone $mismatched)->count(),
            (float) (clone $mismatched)->sum('s.gross_amount'),
            (clone $mismatched)->limit(self::SAMPLE_SIZE)->get(['s.id', 's.load_id', 's.bet_shop_id', 's.customer_id', 's.gross_amount', 's.excise_amount', 's.net_amount', 'f.id as shop_ledger_entry_id', 'c.id as customer_ledger_entry_id'])->map(fn ($row) => (array) $row)->all()
        );
    }

    /**
     * Each house funding has its credit to the house wallet for the full deposit, and each voided one has its
     * reversal.
     *
     * @return array<string, mixed>
     */
    private function houseFundingLedger(FinanceDateRange $range): array
    {
        $tolerance = $this->tolerance();

        $mismatched = DB::table('house_fundings as h')
            ->join('incoming_payments as i', 'i.id', '=', 'h.deposit_id')
            ->leftJoin('ledger_entries as l', 'l.id', '=', 'h.ledger_entry_id')
            ->leftJoin('ledger_entries as v', 'v.id', '=', 'h.void_ledger_entry_id')
            ->whereBetween('h.created_at', [$range->from, $range->to])
            ->where(fn ($query) => $query
                ->whereNull('l.id')
                ->orWhere('l.entry_type', '!=', 'house_funding')
                ->orWhere('l.wallet_id', '!=', (int) config('wallets.house_wallet_id', 1))
                ->orWhereRaw('ABS(l.credit - h.amount) > ?', [$tolerance])
                ->orWhereRaw('ABS(h.amount - i.trans_amount) > ?', [$tolerance])
                ->orWhereRaw('(h.voided_at IS NULL AND i.status <> ?)', [Deposit::STATUS_COMPLETED])
                ->orWhereRaw("(h.voided_at IS NOT NULL AND (v.id IS NULL OR v.entry_type <> 'house_funding_reversal' OR ABS(v.debit - h.amount) > ?))", [$tolerance]));

        return $this->result(
            'house_funding_ledger',
            'House funding matches the ledger',
            'fail',
            (clone $mismatched)->count(),
            (float) (clone $mismatched)->sum('h.amount'),
            (clone $mismatched)->limit(self::SAMPLE_SIZE)->get(['h.id', 'h.deposit_id', 'i.trans_id', 'h.amount', 'h.voided_at', 'l.id as ledger_entry_id', 'v.id as void_ledger_entry_id'])->map(fn ($row) => (array) $row)->all()
        );
    }

    /**
     * House cuts taken against the fee schedule, for cuts linked to their transaction.
     *
     * @return array<string, mixed>
     */
    private function houseCutRates(FinanceDateRange $range): array
    {
        $tolerance = $this->tolerance();
        $source = "JSON_UNQUOTE(JSON_EXTRACT(l.metadata, '$.source'))";

        $game = DB::table('ledger_entries as l')
            ->join('game_transactions as gt', function ($join) {
                $join->on('gt.id', '=', 'l.referenceable_id')->where('l.referenceable_type', GameTransaction::class);
            })
            ->where('l.entry_type', 'house_cut')
            ->whereRaw("{$source} = 'game_credit'")
            ->whereBetween('l.created_at', [$range->from, $range->to])
            ->whereRaw('ABS(l.credit - ROUND(gt.amount * ?, 2)) > ?', [config('finance.fees.game_credit'), $tolerance])
            ->selectRaw("l.id, 'game_credit' as source, l.credit as actual, ROUND(gt.amount * ?, 2) as expected", [config('finance.fees.game_credit')]);

        $competition = DB::table('ledger_entries as l')
            ->join('competition_transactions as ct', function ($join) {
                $join->on('ct.id', '=', 'l.referenceable_id')->where('l.referenceable_type', CompetitionTransaction::class);
            })
            ->join('competition_wallets as cw', 'cw.id', '=', 'ct.competition_wallet_id')
            ->where('l.entry_type', 'house_cut')
            ->whereRaw("{$source} = 'competition_bet'")
            ->whereBetween('l.created_at', [$range->from, $range->to])
            ->whereRaw('ABS(l.credit - ROUND(ct.amount * CASE cw.game_type WHEN 1 THEN ? ELSE ? END, 2)) > ?', [config('finance.fees.tournament'), config('finance.fees.jackpot'), $tolerance])
            ->selectRaw("l.id, 'competition_bet' as source, l.credit as actual, ROUND(ct.amount * CASE cw.game_type WHEN 1 THEN ? ELSE ? END, 2) as expected", [config('finance.fees.tournament'), config('finance.fees.jackpot')]);

        $rows = $game->get()->concat($competition->get());

        return $this->result(
            'house_cut_rates',
            'House cuts match the fee schedule',
            'warn',
            $rows->count(),
            (float) $rows->sum(fn ($row) => abs((float) $row->actual - (float) $row->expected)),
            $rows->take(self::SAMPLE_SIZE)->map(fn ($row) => (array) $row)->values()->all(),
            'Only cuts linked to their transaction can be checked.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mpesaBalanceFreshness(): array
    {
        $hours = (int) config('finance.reconciliation.stale_mpesa_balance_hours');
        $latest = MpesaBalance::max('created_at');
        $stale = $latest === null || CarbonImmutable::parse($latest)->lessThan(now()->subHours($hours));

        return $this->result(
            'mpesa_balance_freshness',
            "M-Pesa balances fetched in the last {$hours} hours",
            'warn',
            $stale ? 1 : 0,
            0.0,
            [['latest_balance_at' => $latest]],
            'The hourly mpesa:fetch-balances job may not be running, or the callback failed.'
        );
    }

    /**
     * M-Pesa cash against everything owed to customers.
     *
     * @return array<string, mixed>
     */
    private function cashCoverage(): array
    {
        $position = $this->snapshots->currentPosition();
        $title = 'M-Pesa cash covers what is owed to customers and KRA';

        if ($position['mpesa_balances'] === null) {
            return $this->result('cash_coverage', $title, 'warn', 1, 0.0, [], 'No M-Pesa balance has been fetched yet.');
        }

        $cash = 0.0;
        foreach ($position['mpesa_balances'] as $accounts) {
            $cash += array_sum(array_column($accounts, 'amount'));
        }

        $owed = $position['customer_wallets_total'] + $position['game_escrow_total'] + $position['competition_escrow_total']
            + $position['stuck_escrow_total'] + $position['coin_liability'] + $position['pending_holds_total']
            + $position['unmatched_deposits_total'] + $position['excise_duty_payable'] + $position['disputed_funds_total']
            + $position['referral_wallets_total'] + $position['bet_shop_floats_total'];

        $shortfall = round($owed - $cash, 2);

        return $this->result(
            'cash_coverage',
            $title,
            'fail',
            $shortfall > $this->tolerance() ? 1 : 0,
            max($shortfall, 0.0),
            [['cash' => round($cash, 2), 'owed' => round($owed, 2), 'surplus' => round($cash - $owed, 2)]]
        );
    }

    /**
     * @param  list<array<string, mixed>>  $samples
     * @return array<string, mixed>
     */
    private function result(string $key, string $title, string $failStatus, int $count, float $amount, array $samples = [], ?string $detail = null): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'status' => $count > 0 ? $failStatus : 'pass',
            'count' => $count,
            'amount' => round($amount, 2),
            'detail' => $detail,
            'samples' => $count > 0 ? $samples : [],
        ];
    }

    private function tolerance(): float
    {
        return (float) config('finance.reconciliation.tolerance');
    }

    private function reliableFrom(): string
    {
        return (string) config('finance.ledger_reliable_from');
    }
}
