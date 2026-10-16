<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Services\Concerns\BuildsFinanceQueries;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Drill-downs into the ledger: every entry, manual adjustments, one customer's statement, and the
 * customers who deposit, play and win the most.
 */
class FinanceLedgerReportService
{
    use BuildsFinanceQueries;

    private const JSON_REASON = "JSON_UNQUOTE(JSON_EXTRACT(l.metadata, '$.reason'))";

    private const JSON_ACTOR = "JSON_UNQUOTE(JSON_EXTRACT(l.metadata, '$.actor'))";

    private const JSON_OPERATION = "JSON_UNQUOTE(JSON_EXTRACT(l.metadata, '$.operation'))";

    public function __construct(private ChartOfAccounts $chart, private FinanceMasker $masker) {}

    /**
     * Every ledger entry in the range.
     *
     * Filters: entry_type, category, wallet_type, wallet_id, customer_id, status.
     *
     * @param  array<string, string>  $filters
     */
    public function ledger(FinanceDateRange $range, array $filters): FinanceListing
    {
        $base = fn () => $this->ledgerBase($range, $filters);

        $query = $base()
            ->selectRaw('l.id, l.entry_id, l.created_at, l.entry_type, l.wallet_type, l.wallet_id, l.customer_id, l.debit, l.credit, l.balance_before, l.balance_after, l.status, l.referenceable_type, l.referenceable_id, l.metadata')
            ->orderByDesc('l.id');

        $summary = function () use ($base) {
            $byType = $base()
                ->selectRaw('l.entry_type, COUNT(*) as entries, SUM(l.debit) as debit, SUM(l.credit) as credit')
                ->groupBy('l.entry_type')
                ->orderBy('l.entry_type')
                ->get();

            return [
                'entries' => (int) $byType->sum('entries'),
                'debit' => $this->money($byType->sum('debit')),
                'credit' => $this->money($byType->sum('credit')),
                'by_entry_type' => $byType->map(fn ($row) => [
                    'entry_type' => $row->entry_type,
                    'category' => $this->chart->categoryFor($row->entry_type),
                    'entries' => (int) $row->entries,
                    'debit' => $this->money($row->debit),
                    'credit' => $this->money($row->credit),
                ])->all(),
            ];
        };

        return new FinanceListing(
            $query,
            fn (object $row) => [
                'id' => (int) $row->id,
                'entry_id' => $row->entry_id,
                'created_at' => $this->moment($row->created_at),
                'entry_type' => $row->entry_type,
                'category' => $this->chart->categoryFor($row->entry_type),
                'account' => $row->wallet_type === null ? 'unclassified' : $this->chart->accountFor($row->wallet_type, (int) $row->wallet_id),
                'wallet_type' => $row->wallet_type,
                'wallet_id' => (int) $row->wallet_id,
                'customer_id' => $row->customer_id === null ? null : (int) $row->customer_id,
                'debit' => $this->money($row->debit),
                'credit' => $this->money($row->credit),
                'balance_before' => $this->money($row->balance_before),
                'balance_after' => $this->money($row->balance_after),
                'status' => $row->status,
                'reference' => $row->referenceable_type === null ? null : class_basename($row->referenceable_type).'#'.$row->referenceable_id,
                'metadata' => $row->metadata === null ? null : json_decode($row->metadata, true),
            ],
            $summary,
            ['id', 'entry_id', 'created_at', 'entry_type', 'category', 'account', 'wallet_type', 'wallet_id', 'customer_id', 'debit', 'credit', 'balance_before', 'balance_after', 'status', 'reference', 'metadata'],
        );
    }

    /**
     * Manual balance changes (and their reversals), with who made them and why.
     *
     * Filters: customer_id.
     *
     * @param  array<string, string>  $filters
     */
    public function adjustments(FinanceDateRange $range, array $filters): FinanceListing
    {
        $base = fn () => $this->ledgerBase($range, $filters + ['category' => 'adjustment'])
            ->leftJoin('customers as c', 'c.id', '=', 'l.customer_id');

        $reason = self::JSON_REASON;
        $actor = self::JSON_ACTOR;

        $query = $base()
            ->selectRaw("l.id, l.created_at, l.entry_type, l.customer_id, c.name as customer_name, l.debit, l.credit, l.balance_before, l.balance_after, l.status, {$reason} as reason, {$actor} as actor, ".self::JSON_OPERATION.' as operation')
            ->orderByDesc('l.id');

        $summary = function () use ($base, $reason, $actor) {
            $totals = $base()
                ->selectRaw("COUNT(*) as entries, COALESCE(SUM(l.credit), 0) as credited, COALESCE(SUM(l.debit), 0) as debited, COALESCE(SUM(CASE WHEN COALESCE({$reason}, 'unspecified') = 'unspecified' THEN 1 ELSE 0 END), 0) as unspecified")
                ->first();

            $byReason = $base()
                ->selectRaw("COALESCE({$reason}, 'unspecified') as reason, COUNT(*) as entries, SUM(l.credit - l.debit) as net")
                ->groupByRaw("COALESCE({$reason}, 'unspecified')")
                ->orderByDesc('entries')
                ->limit(10)
                ->get();

            $byActor = $base()
                ->selectRaw("COALESCE({$actor}, 'unknown') as actor, COUNT(*) as entries, SUM(l.credit - l.debit) as net")
                ->groupByRaw("COALESCE({$actor}, 'unknown')")
                ->orderByDesc('entries')
                ->limit(10)
                ->get();

            return [
                'entries' => (int) $totals->entries,
                'credited' => $this->money($totals->credited),
                'debited' => $this->money($totals->debited),
                'net' => $this->money($totals->credited - $totals->debited),
                'without_reason' => (int) $totals->unspecified,
                'by_reason' => $byReason->map(fn ($row) => ['reason' => $row->reason, 'entries' => (int) $row->entries, 'net' => $this->money($row->net)])->all(),
                'by_actor' => $byActor->map(fn ($row) => ['actor' => $row->actor, 'entries' => (int) $row->entries, 'net' => $this->money($row->net)])->all(),
            ];
        };

        return new FinanceListing(
            $query,
            fn (object $row) => [
                'id' => (int) $row->id,
                'created_at' => $this->moment($row->created_at),
                'entry_type' => $row->entry_type,
                'customer_id' => $row->customer_id === null ? null : (int) $row->customer_id,
                'customer_name' => $row->customer_name,
                'direction' => (float) $row->credit > 0 ? 'credit' : 'debit',
                'amount' => $this->money((float) $row->credit > 0 ? $row->credit : $row->debit),
                'balance_before' => $this->money($row->balance_before),
                'balance_after' => $this->money($row->balance_after),
                'reason' => $row->reason ?? 'unspecified',
                'actor' => $row->actor,
                'operation' => $row->operation,
                'status' => $row->status,
            ],
            $summary,
            ['id', 'created_at', 'entry_type', 'customer_id', 'customer_name', 'direction', 'amount', 'balance_before', 'balance_after', 'reason', 'actor', 'operation', 'status'],
        );
    }

    /**
     * One customer's wallet statement for a range: opening balance, movements, closing balance.
     * Returns null when the customer has no wallet.
     *
     * @return array<string, mixed>|null
     */
    public function customerStatement(Customer $customer, FinanceDateRange $range, int $page, int $perPage): ?array
    {
        $wallet = Wallet::where('customer_id', $customer->id)->first();

        if (! $wallet) {
            return null;
        }

        $entries = fn () => DB::table('ledger_entries')
            ->where('wallet_type', LedgerEntry::WALLET_TYPE_WALLET)
            ->where('wallet_id', $wallet->id)
            ->whereIn('status', ['settled', 'reversed']);

        $inRange = fn () => $entries()->whereBetween('created_at', [$range->from, $range->to]);

        $before = $entries()->where('created_at', '<', $range->from)->orderByDesc('id')->value('balance_after');
        $first = $inRange()->orderBy('id')->value('balance_before');
        $last = $inRange()->orderByDesc('id')->value('balance_after');
        $totals = $inRange()->selectRaw('COUNT(*) as entries, COALESCE(SUM(credit), 0) as credits, COALESCE(SUM(debit), 0) as debits')->first();

        $opening = (float) ($before ?? $first ?? $wallet->balance);
        $closing = (float) ($last ?? $opening);

        $page = $inRange()
            ->selectRaw('id, created_at, entry_type, debit, credit, balance_after, status, metadata')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'account_no' => $customer->account_no,
                'phone_no' => $this->masker->phone($customer->phone_no),
            ],
            'wallet' => ['id' => $wallet->id, 'balance_now' => $this->money($wallet->balance)],
            'statement' => [
                'opening_balance' => $this->money($opening),
                'credits' => $this->money($totals->credits),
                'debits' => $this->money($totals->debits),
                'closing_balance' => $this->money($closing),
                'entries' => (int) $totals->entries,
                'reconciles' => abs($opening + (float) $totals->credits - (float) $totals->debits - $closing) <= (float) config('finance.reconciliation.tolerance'),
            ],
            'items' => collect($page->items())->map(fn (object $row) => [
                'id' => (int) $row->id,
                'created_at' => $this->moment($row->created_at),
                'entry_type' => $row->entry_type,
                'category' => $this->chart->categoryFor($row->entry_type),
                'debit' => $this->money($row->debit),
                'credit' => $this->money($row->credit),
                'balance_after' => $this->money($row->balance_after),
                'status' => $row->status,
                'reason' => $row->metadata === null ? null : (json_decode($row->metadata, true)['reason'] ?? null),
            ])->values()->all(),
            'pagination' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ];
    }

    /**
     * The customers with the most activity in the range, and how concentrated wallet balances are.
     *
     * Filters: sort (net_gaming, deposited, staked, won, withdrawn, balance, shop_loaded, shop_withdrawn), limit (default 10).
     * net_gaming is winnings plus refunds minus stakes: positive means the customer is up.
     * deposited is everything paid in, gross: M-Pesa deposits plus cash loaded at bet shops (shop_loaded,
     * net of reversed loads); excise_paid is the duty taken from it. withdrawn is everything taken out:
     * M-Pesa withdrawals plus shop cash withdrawals (shop_withdrawn: collected or waiting to be collected).
     *
     * @param  array<string, string>  $filters
     */
    public function topCustomers(FinanceDateRange $range, array $filters): FinanceListing
    {
        $houseWalletId = (int) config('wallets.house_wallet_id', 1);
        $excluded = $this->excludedCustomerIds($range);
        $sort = $filters['sort'] ?? 'deposited';
        $limit = (int) ($filters['limit'] ?? 10);

        $sum = function (string $category, string $direction) {
            $types = $this->chart->entryTypesFor($category);
            $marks = implode(',', array_map(fn (string $type) => "'{$type}'", $types));
            $amount = $direction === 'credit' ? 'l.credit - l.debit' : 'l.debit - l.credit';

            return "COALESCE(SUM(CASE WHEN l.entry_type IN ({$marks}) THEN {$amount} ELSE 0 END), 0)";
        };

        $shopLoaded = $sum('shop_load', 'credit');
        $shopWithdrawn = "COALESCE(SUM(CASE WHEN l.entry_type IN ('shop_withdrawal_hold', 'shop_withdrawal_release') THEN l.debit - l.credit ELSE 0 END), 0)";
        $deposited = '('.$sum('cash_in', 'credit')." + {$shopLoaded})";
        $withdrawn = '('.$sum('cash_out', 'debit')." + {$shopWithdrawn})";
        $staked = $sum('stake', 'debit');
        $won = $sum('payout', 'credit');
        $refunded = $sum('refund', 'credit');
        $excisePaid = $sum('tax_withheld', 'debit');

        $rows = DB::table('ledger_entries as l')
            ->join('wallets as w', 'w.id', '=', 'l.wallet_id')
            ->leftJoin('customers as c', 'c.id', '=', 'l.customer_id')
            ->where('l.wallet_type', LedgerEntry::WALLET_TYPE_WALLET)
            ->where('l.wallet_id', '!=', $houseWalletId)
            ->whereNotNull('l.customer_id')
            ->whereBetween('l.created_at', [$range->from, $range->to])
            ->when($excluded !== [], fn (Builder $query) => $query->whereNotIn('l.customer_id', $excluded))
            ->selectRaw("l.customer_id, c.name as customer_name, w.balance as balance, {$deposited} as deposited, {$shopLoaded} as shop_loaded, {$excisePaid} as excise_paid, {$withdrawn} as withdrawn, {$shopWithdrawn} as shop_withdrawn, {$staked} as staked, {$won} as won, {$refunded} as refunded, ({$won} + {$refunded} - {$staked}) as net_gaming")
            ->groupBy('l.customer_id', 'c.name', 'w.balance')
            ->orderByDesc($sort)
            ->orderBy('l.customer_id')
            ->limit($limit);

        $summary = function () use ($houseWalletId, $excluded) {
            $wallets = fn () => DB::table('wallets')
                ->where('id', '!=', $houseWalletId)
                ->when($excluded !== [], fn (Builder $query) => $query->whereNotIn('customer_id', $excluded));

            $total = (float) $wallets()->sum('balance');
            $topTen = (float) DB::query()->fromSub($wallets()->orderByDesc('balance')->limit(10)->select('balance'), 't')->sum('balance');

            return [
                'customer_wallets_total' => $this->money($total),
                'concentration' => [
                    'top_wallets' => 10,
                    'balance' => $this->money($topTen),
                    'share_percent' => $total > 0 ? round($topTen / $total * 100, 1) : 0.0,
                ],
            ];
        };

        return new FinanceListing(
            $rows,
            fn (object $row) => [
                'customer_id' => (int) $row->customer_id,
                'customer_name' => $row->customer_name,
                'deposited' => $this->money($row->deposited),
                'shop_loaded' => $this->money($row->shop_loaded),
                'excise_paid' => $this->money($row->excise_paid),
                'withdrawn' => $this->money($row->withdrawn),
                'shop_withdrawn' => $this->money($row->shop_withdrawn),
                'staked' => $this->money($row->staked),
                'won' => $this->money($row->won),
                'refunded' => $this->money($row->refunded),
                'net_gaming' => $this->money($row->net_gaming),
                'balance' => $this->money($row->balance),
            ],
            $summary,
            ['customer_id', 'customer_name', 'deposited', 'shop_loaded', 'excise_paid', 'withdrawn', 'shop_withdrawn', 'staked', 'won', 'refunded', 'net_gaming', 'balance'],
        );
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function ledgerBase(FinanceDateRange $range, array $filters): Builder
    {
        // The house customer (id 1) is in the test list but its entries are real money, so keep them.
        $excluded = array_values(array_diff($this->excludedCustomerIds($range), [1]));
        $categoryTypes = isset($filters['category']) ? $this->chart->entryTypesFor($filters['category']) : null;

        return DB::table('ledger_entries as l')
            ->whereBetween('l.created_at', [$range->from, $range->to])
            ->when($excluded !== [], fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner->whereNull('l.customer_id')->orWhereNotIn('l.customer_id', $excluded)
            ))
            ->when(isset($filters['entry_type']), fn (Builder $query) => $query->where('l.entry_type', $filters['entry_type']))
            ->when($categoryTypes !== null, fn (Builder $query) => $query->whereIn('l.entry_type', $categoryTypes ?: ['']))
            ->when(isset($filters['wallet_type']), fn (Builder $query) => $query->where('l.wallet_type', $filters['wallet_type']))
            ->when(isset($filters['wallet_id']), fn (Builder $query) => $query->where('l.wallet_id', (int) $filters['wallet_id']))
            ->when(isset($filters['customer_id']), fn (Builder $query) => $query->where('l.customer_id', (int) $filters['customer_id']))
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('l.status', $filters['status']));
    }
}
