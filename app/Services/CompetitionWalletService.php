<?php

namespace App\Services;

use App\Models\CompetitionTransaction;
use App\Models\CompetitionWallet;
use App\Models\Customer;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CompetitionWalletService
{
    public function __construct(private LedgerService $ledgerService) {}

    /**
     * Every competition wallet, or one page of them (newest first) when a page size is given.
     */
    public function listCompetitionWallets(?int $perPage = null): Collection|LengthAwarePaginator
    {
        return $perPage === null ? CompetitionWallet::all() : CompetitionWallet::latest('id')->paginate($perPage);
    }

    public function createCompetitionWallet(array $data): CompetitionWallet
    {
        return CompetitionWallet::create($data);
    }

    public function getCompetitionWallet($identifier): ?CompetitionWallet
    {
        return CompetitionWallet::where('competition_id', $identifier)
            ->first();
    }

    public function getCompetitionsByCmpUid($cmpUid): Collection
    {
        return CompetitionWallet::where('cmp_uid', $cmpUid)->get();
    }

    public function getCompetitionIncome(string $gameType, ?string $startDate = null, ?string $endDate = null): array
    {
        return DB::table('competition_wallets as C')
            ->join('wallet_transactions as W', fn ($join) => $join->on('W.sender_id', '=', 'C.id')->where('W.transaction_type', 'c2w'))
            ->select('C.jp_rounds', DB::raw('SUM(W.amount) as total_income'))
            ->selectRaw('COUNT(DISTINCT C.id) as games_played')
            ->where('C.game_type', $gameType)
            ->where('C.jp_rounds', '>', 0)
            ->whereBetween('C.created_at', [$startDate, $endDate])
            ->groupBy('C.jp_rounds')
            ->orderBy('C.jp_rounds')
            ->get()
            ->toArray();
    }

    /**
     * Every round result of a game type's competitions: win or loss per round, and payout when the
     * customer won the competition. `level` is the level at that transaction; `current_level` is the
     * wallet's level now. `income` is the house share taken from the competition wallet (c2w).
     */
    public function getCompetitionResults(string $gameType): array
    {
        $houseIncome = DB::table('wallet_transactions')
            ->where('transaction_type', 'c2w')
            ->groupBy('sender_id')
            ->select('sender_id', DB::raw('SUM(amount) as income'));

        return DB::table('competition_wallets as CW')
            ->join('competition_transactions as CT', 'CT.competition_wallet_id', '=', 'CW.id')
            ->leftJoinSub($houseIncome, 'WT', 'WT.sender_id', '=', 'CW.id')
            ->leftJoin('customers as C', 'CW.customer_id', '=', 'C.id')
            ->where('CW.game_type', $gameType)
            ->where('CW.jp_rounds', '>', 0)
            ->whereIn('CT.payment_type', ['win', 'loss', 'payout'])
            ->select(
                'CW.id',
                'CW.competition_id',
                'CW.cmp_uid',
                'CW.customer_id',
                'C.name',
                'CT.level',
                'CW.level as current_level',
                'CW.jp_rounds',
                'CW.status',
                'CT.payment_type',
                'CT.amount',
                DB::raw('COALESCE(WT.income, 0) as income'),
                'CT.created_at'
            )
            ->orderBy('CT.id')
            ->get()
            ->toArray();
    }

    public function getCompetitionAwards(string $gameType): array
    {
        $sub = DB::table('competition_transactions as CT')
            ->join('competition_wallets as CW', 'CT.competition_wallet_id', '=', 'CW.id')
            ->whereColumn('CW.level', '>=', 'CW.jp_rounds')
            ->where('CW.game_type', $gameType)
            ->where('CT.payment_type', 'win')
            ->select(DB::raw('MAX(CT.id) as latest_transaction_id'))
            ->groupBy('CW.competition_id');

        return DB::table('competition_transactions as CT')
            ->join('competition_wallets as CW', 'CT.competition_wallet_id', '=', 'CW.id')
            ->join('customers as C', 'CW.customer_id', '=', 'C.id')
            ->whereColumn('CW.level', '>=', 'CW.jp_rounds')
            ->where('CW.game_type', $gameType)
            ->where('CT.payment_type', 'win')
            ->whereIn('CT.id', $sub)
            ->select(
                'CT.id as transaction_id',
                'CW.competition_id',
                'CW.game_type',
                'C.name',
                'CW.cmp_uid',
                'CW.level',
                'CW.jp_rounds',
                'CT.payment_type',
                'CT.amount',
                'CT.created_at'
            )
            ->orderByDesc('CW.id')
            ->get()
            ->toArray();
    }

    public function updateCompetitionWallet($id, array $data): ?CompetitionWallet
    {
        $competitionWallet = CompetitionWallet::find($id);
        if (! $competitionWallet) {
            return null;
        }

        $competitionWallet->update($data);

        return $competitionWallet;
    }

    public function deleteCompetitionWallet($id): bool
    {
        $competitionWallet = CompetitionWallet::find($id);
        if (! $competitionWallet) {
            return false;
        }

        $competitionWallet->delete();

        return true;
    }

    public function processWithdrawal(int $competitionWalletId, int $customerId): array
    {
        $competitionWallet = CompetitionWallet::find($competitionWalletId);
        if (! $competitionWallet) {
            throw new \InvalidArgumentException('Competition Wallet not found');
        }

        if ($competitionWallet->status !== 1) {
            throw new \InvalidArgumentException('Competition Wallet is not open for withdrawal');
        }

        if ($competitionWallet->isUnderDispute()) {
            throw new \InvalidArgumentException('Competition Wallet is frozen by a pending complaint');
        }

        $customer = Customer::find($customerId);
        if (! $customer) {
            throw new \InvalidArgumentException('Customer not found');
        }

        $wallet = $customer->wallet;
        if (! $wallet) {
            throw new \InvalidArgumentException('Customer wallet not found');
        }

        DB::transaction(function () use ($competitionWallet, $wallet) {
            $totalBalance = $competitionWallet->balance;

            $competitionTransaction = CompetitionTransaction::create([
                'competition_wallet_id' => $competitionWallet->id,
                'customer_id' => $competitionWallet->customer_id,
                'amount' => $totalBalance,
                'payment_type' => 'payout',
                'level' => $competitionWallet->level,
                'status' => 2,
            ]);

            $ledgerEntry = $this->ledgerService->recordCompetitionPayout(
                $competitionTransaction,
                $wallet,
                (float) $totalBalance
            );

            $competitionTransaction->update([
                'wallet_balance_before' => $ledgerEntry->balance_before,
                'wallet_balance_after' => $ledgerEntry->balance_after,
                'competition_wallet_balance_before' => $competitionWallet->balance,
                'competition_wallet_balance_after' => 0,
            ]);

            $this->ledgerService->recordEscrowRelease($competitionTransaction, $competitionWallet, (float) $totalBalance, 'competition_payout');

            $competitionWallet->status = 3;
            $competitionWallet->balance = 0;
            $competitionWallet->save();
        });

        return ['status' => 'success'];
    }

    /**
     * Every competition transaction, or one page of them (newest first) when a page size is given.
     */
    public function listCompetitionTransactions(?int $perPage = null): Collection|LengthAwarePaginator
    {
        $query = CompetitionTransaction::with('betShop:id,shop_no');

        return $perPage === null ? $query->get() : $query->latest('id')->paginate($perPage);
    }

    public function createCompetitionTransaction(array $data): CompetitionTransaction
    {
        $competitionWallet = CompetitionWallet::find($data['competition_wallet_id']);
        if (! $competitionWallet) {
            throw new \InvalidArgumentException('Competition Wallet Not Found!');
        }
        if ($competitionWallet->customer_id != $data['customer_id']) {
            throw new \InvalidArgumentException('Customer ID do not match');
        }

        $customer = Customer::with('wallet')->find($data['customer_id']);
        if (! $customer || ! $customer->wallet || $customer->wallet->balance < $data['amount']) {
            throw new \InvalidArgumentException('Insufficient balance or Wallet not found.');
        }

        $competitionTransaction = null;

        DB::transaction(function () use ($competitionWallet, $customer, $data, &$competitionTransaction) {
            // Lock order: the competition wallet, the player's wallet, then the house wallet. Every balance changes
            // on these locked rows and the stake is checked under the lock, so two stakes at once can neither spend
            // the same money nor overwrite each other's balances.
            $competitionWallet = CompetitionWallet::lockForUpdate()->find($competitionWallet->id);
            $wallet = Wallet::where('customer_id', $customer->id)->lockForUpdate()->first();

            if ($wallet->balance < $data['amount']) {
                throw new \InvalidArgumentException('Insufficient balance or Wallet not found.');
            }

            $totalBalance = $data['amount'];
            $competitionType = $competitionWallet->game_type;

            $houseCompetitionShare = 0;
            if ($competitionType == 1) {
                $houseCompetitionShare = round($totalBalance * config('finance.fees.tournament'), 2);
            } elseif ($competitionType == 2) {
                $houseCompetitionShare = round($totalBalance * config('finance.fees.jackpot'), 2);
            }

            $competitionTransaction = CompetitionTransaction::create($data);

            [$walletEntry, $cwEntry] = $this->ledgerService->recordCompetitionBet(
                $competitionTransaction,
                $wallet,
                $competitionWallet,
                (float) $data['amount'],
                (float) $houseCompetitionShare
            );

            $competitionTransaction->update([
                'wallet_balance_before' => $walletEntry->balance_before,
                'wallet_balance_after' => $walletEntry->balance_after,
                'competition_wallet_balance_before' => $cwEntry->balance_before,
                'competition_wallet_balance_after' => $cwEntry->balance_after,
            ]);

            $competitionWallet->level += 1;
            $competitionWallet->save();

            if ($houseCompetitionShare > 0) {
                $houseWallet = Wallet::lockForUpdate()->find(config('wallets.house_wallet_id', 1));
                $this->ledgerService->recordHouseCut(
                    $houseWallet,
                    (float) $houseCompetitionShare,
                    'competition_bet',
                    $competitionTransaction,
                    ['competition_wallet_id' => $competitionWallet->id]
                );
            }

            WalletTransaction::create([
                'transaction_type' => 'c2w',
                'sender_id' => $competitionWallet->id,
                'receiver_id' => 1,
                'initiator_id' => 1,
                'amount' => $houseCompetitionShare,
            ]);
        });

        return $competitionTransaction;
    }

    public function getCompetitionTransaction(int $id): ?CompetitionTransaction
    {
        return CompetitionTransaction::find($id);
    }

    public function updateCompetitionTransaction(int $id, array $data): ?CompetitionTransaction
    {
        $competitionTransaction = CompetitionTransaction::find($id);
        if (! $competitionTransaction) {
            return null;
        }

        $competitionTransaction->update($data);

        return $competitionTransaction;
    }

    public function deleteCompetitionTransaction(int $id): bool
    {
        $competitionTransaction = CompetitionTransaction::find($id);
        if (! $competitionTransaction) {
            return false;
        }

        $competitionTransaction->delete();

        return true;
    }
}
