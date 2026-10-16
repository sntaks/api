<?php

namespace App\Services;

use App\Models\BetShopLoad;
use App\Models\CompetitionTransaction;
use App\Models\CompetitionWallet;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DisputedTransaction;
use App\Models\GameTransaction;
use App\Models\GameWallet;
use App\Models\PendingBalance;
use App\Models\ReferralWithdrawal;
use App\Models\Wallet;
use App\Models\Withdraw;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerService
{
    public function __construct(
        private LedgerService $ledgerService,
        private ReferralService $referralService,
        private PromoCodeService $promoCodes,
    ) {}

    public function listActiveCustomers(): Collection
    {
        return Customer::query()
            ->where('status', 1)
            ->where('id', '!=', 1)
            ->whereDate('created_at', '>=', '2026-03-01 00:00:00')
            ->get();
    }

    public function searchCustomers(string $query): Collection
    {
        return Customer::query()
            ->where('status', 1)
            ->where('id', '!=', 1)
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('account_no', 'like', "%{$query}%")
                    ->orWhere('phone_no', 'like', "%{$query}%")
                    ->orWhere('email', 'like', "%{$query}%");
            })
            ->get();
    }

    public function getCustomersByReferralCodes(string $referralCodes): Collection
    {
        $codes = Str::contains($referralCodes, ',')
            ? explode(',', $referralCodes)
            : [$referralCodes];

        return Customer::query()
            ->where('status', 1)
            ->where('id', '!=', 1)
            ->whereIn('referral_code', $codes)
            ->get();
    }

    /**
     * Create the customer and their wallet. A `referral_code` that belongs to another customer makes
     * this customer that customer's referral. A usable `promo_code` is recorded for the signup bonus;
     * an unknown or expired one is ignored.
     */
    public function createCustomer(array $data): Customer
    {
        return DB::transaction(function () use ($data) {
            $customer = Customer::create(Arr::except($data, ['promo_code']));
            Wallet::create(['customer_id' => $customer->id, 'balance' => 0]);

            $this->referralService->attachAtSignup($customer, $data['referral_code'] ?? null);
            $this->promoCodes->attachAtSignup($customer, $data['promo_code'] ?? null);

            return $customer;
        });
    }

    public function getCustomer($identifier): ?Customer
    {
        return Customer::where('id', $identifier)
            ->orWhere('account_no', $identifier)
            ->first();
    }

    public function getCustomerTransactions($identifier, string $paymentType): array
    {
        $customer = Customer::query()
            ->where('id', $identifier)
            ->orWhere('account_no', $identifier)
            ->first();

        if (! $customer) {
            return ['customer' => null, 'total' => 0, 'transactions' => collect()];
        }

        $wallet = $customer->wallet;

        if (! $wallet) {
            return ['customer' => $customer, 'wallet' => null, 'total' => 0, 'transactions' => collect()];
        }

        // Cash loads at bet shops count as money in; a reversed one (status 3) is left out.
        $shopLoads = fn ($query) => $query->where('payment_type', BetShopLoad::class)->where('status', 2);

        if ($paymentType === 'all') {
            $history = fn () => $wallet->transactions()
                ->where(fn ($query) => $query->whereIn('payment_type', [Deposit::class, Withdraw::class])->orWhere($shopLoads));

            $transactions = $history()->limit(10)->latest()->get();
            $total = $history()->sum('amount');

            return ['customer' => $customer, 'wallet' => $wallet, 'total' => $total, 'transactions' => $transactions];
        }

        $type = match ($paymentType) {
            'deposit' => Deposit::class,
            'withdraw' => Withdraw::class,
            'shop_load' => BetShopLoad::class,
            default => null,
        };

        if (! $type) {
            return ['customer' => $customer, 'wallet' => $wallet, 'invalid_type' => true, 'total' => 0, 'transactions' => collect()];
        }

        $history = fn () => $type === BetShopLoad::class
            ? $wallet->transactions()->where($shopLoads)
            : $wallet->transactions()->where('payment_type', $type);

        $transactions = $history()->limit(10)->latest()->get();
        $total = $history()->sum('amount');

        return ['customer' => $customer, 'wallet' => $wallet, 'total' => $total, 'transactions' => $transactions];
    }

    public function getCustomerPlayedGames($identifier): array
    {
        $customer = Customer::query()
            ->where('id', $identifier)
            ->orWhere('account_no', $identifier)
            ->with([
                'gameWalletTransactions' => function ($query) {
                    $query
                        ->where('payment_type', '!=', 'payout')
                        ->whereBetween('created_at', [Carbon::now()->subMonths(3)->startOfMonth(), Carbon::now()->endOfMonth()])
                        ->with('gameWallet');
                },
                'allCompetitionTransactions' => function ($query) {
                    $query
                        ->where('payment_type', '!=', 'payout')
                        ->where('payment_type', '!=', 'deposit')
                        ->where('competition_transactions.created_at', '>=', Carbon::now()->subMonths(3)->startOfMonth())
                        ->where('competition_transactions.created_at', '<=', Carbon::now()->endOfMonth())
                        ->with('wallet');
                },
            ])
            ->first();

        if (! $customer) {
            return ['customer' => null];
        }

        $gameWalletIds = $customer->gameWalletTransactions
            ->pluck('game_wallet_id')
            ->unique()
            ->values();

        $payouts = GameTransaction::query()
            ->whereIn('game_wallet_id', $gameWalletIds)
            ->where('payment_type', 'payout')
            ->pluck('game_wallet_id')
            ->flip();

        $playerCounts = GameTransaction::query()
            ->selectRaw('game_wallet_id, COUNT(*) as total')
            ->whereIn('game_wallet_id', $gameWalletIds)
            ->where('payment_type', 'deposit')
            ->groupBy('game_wallet_id')
            ->pluck('total', 'game_wallet_id');

        $singleGames = $customer->gameWalletTransactions->map(function ($transaction) use ($payouts, $playerCounts) {
            return [
                'game_wallet_id' => $transaction->game_wallet_id,
                'game_id' => $transaction->gameWallet->game_id ?? null,
                'game_type' => 'Single Game',
                'players' => $playerCounts[$transaction->game_wallet_id] ?? 0,
                'amount' => $transaction->amount,
                'payment_type' => $transaction->payment_type,
                'state' => isset($payouts[$transaction->game_wallet_id]) ? 'win' : 'loss',
                'created_at' => $transaction->created_at->toDateTimeString(),
            ];
        });

        $tournamentGames = collect();
        $jackpotGames = collect();

        foreach ($customer->allCompetitionTransactions as $transaction) {
            $gameData = [
                'competition_id' => $transaction->wallet->competition_id ?? null,
                'type' => $transaction->wallet->game_type ?? null,
                'amount' => $transaction->amount,
                'level' => $transaction->wallet->level ?? null,
                'payment_type' => $transaction->payment_type,
                'created_at' => $transaction->created_at->toDateTimeString(),
            ];

            if (($transaction->wallet->game_type ?? null) == 1) {
                $tournamentGames->push($gameData);
            }
            if (($transaction->wallet->game_type ?? null) == 2) {
                $jackpotGames->push($gameData);
            }
        }

        $singleGames = paginate(
            $singleGames,
            request()->integer('single_per_page', 10),
            request()->integer('single_page', 1),
            'single_page'
        );

        $tournamentGames = paginate(
            $tournamentGames,
            request()->integer('tournament_per_page', 10),
            request()->integer('tournament_page', 1),
            'tournament_page'
        );

        $jackpotGames = paginate(
            $jackpotGames,
            request()->integer('jackpot_per_page', 10),
            request()->integer('jackpot_page', 1),
            'jackpot_page'
        );

        return [
            'customer' => $customer,
            'single_games' => $singleGames,
            'tournament_games' => $tournamentGames,
            'jackpot_games' => $jackpotGames,
        ];
    }

    /**
     * The customer's latest games, tournaments and jackpots, one row per game or competition wallet, newest first.
     * Competition rows carry the other players' wallets in the same competition, which a complaint is filed against.
     *
     * @return array{customer: ?Customer, single_games?: Collection, tournament_games?: Collection, jackpot_games?: Collection}
     */
    public function getCustomerRecentPlayedGames($identifier, int $limit = 10): array
    {
        $customer = Customer::query()
            ->where('id', $identifier)
            ->orWhere('account_no', $identifier)
            ->first();

        if (! $customer) {
            return ['customer' => null];
        }

        return [
            'customer' => $customer,
            'single_games' => $this->recentSingleGames($customer->id, $limit),
            'tournament_games' => $this->recentCompetitions($customer->id, 1, $limit),
            'jackpot_games' => $this->recentCompetitions($customer->id, 2, $limit),
        ];
    }

    /**
     * @return Collection<int, array{game_wallet_id: int, game_id: ?string, game_type: string, players: int, amount: float, state: string, created_at: string}>
     */
    private function recentSingleGames(int $customerId, int $limit): Collection
    {
        $stakes = GameTransaction::query()
            ->selectRaw('game_wallet_id, MAX(id) as last_id, SUM(amount) as amount, MIN(created_at) as played_at')
            ->where('customer_id', $customerId)
            ->where('payment_type', 'deposit')
            ->groupBy('game_wallet_id')
            ->orderByDesc('last_id')
            ->limit($limit)
            ->get();

        $gameWalletIds = $stakes->pluck('game_wallet_id');

        $gameIds = GameWallet::query()
            ->whereIn('id', $gameWalletIds)
            ->pluck('game_id', 'id');

        $wonGameWalletIds = GameTransaction::query()
            ->whereIn('game_wallet_id', $gameWalletIds)
            ->where('customer_id', $customerId)
            ->whereIn('payment_type', ['payout', 'payout|dropped'])
            ->pluck('game_wallet_id')
            ->flip();

        $playerCounts = GameTransaction::query()
            ->selectRaw('game_wallet_id, COUNT(*) as total')
            ->whereIn('game_wallet_id', $gameWalletIds)
            ->where('payment_type', 'deposit')
            ->groupBy('game_wallet_id')
            ->pluck('total', 'game_wallet_id');

        return $stakes->map(fn (GameTransaction $stake) => [
            'game_wallet_id' => (int) $stake->game_wallet_id,
            'game_id' => $gameIds[$stake->game_wallet_id] ?? null,
            'game_type' => 'Single Game',
            'players' => (int) ($playerCounts[$stake->game_wallet_id] ?? 0),
            'amount' => (float) $stake->amount,
            'state' => isset($wonGameWalletIds[$stake->game_wallet_id]) ? 'win' : 'loss',
            'created_at' => Carbon::parse($stake->played_at)->toDateTimeString(),
        ])->values();
    }

    /**
     * Each win/loss transaction on the customer's own competition wallet is one round (game) they played.
     * Every round is paired with the opponent's side of it: the loser's `loss` and the winner's `win` are
     * written together, the win straight after the loss, for the same amount in the same competition.
     *
     * @return Collection<int, array{competition_wallet_id: int, competition_id: ?string, cmp_uid: ?string, game_type: int, level: ?int, balance: float, status: int, created_at: string, wins: int, losses: int, games: list<array{transaction_id: int, payment_type: string, amount: float, level: ?int, created_at: string, opponent: ?array{competition_wallet_id: int, customer_id: ?int, wallet_status: int, transaction_id: int}}>}>
     */
    private function recentCompetitions(int $customerId, int $gameType, int $limit): Collection
    {
        $wallets = CompetitionWallet::query()
            ->select(['id', 'competition_id', 'cmp_uid', 'game_type', 'level', 'balance', 'status', 'created_at'])
            ->where('customer_id', $customerId)
            ->where('game_type', $gameType)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $games = CompetitionTransaction::query()
            ->select(['id', 'competition_wallet_id', 'payment_type', 'amount', 'level', 'created_at'])
            ->whereIn('competition_wallet_id', $wallets->pluck('id'))
            ->whereIn('payment_type', ['win', 'loss'])
            ->orderByDesc('id')
            ->get();

        $opponentRounds = $this->opponentRounds($games, $wallets);
        $games = $games->groupBy('competition_wallet_id');

        return $wallets->map(fn (CompetitionWallet $wallet) => [
            'competition_wallet_id' => $wallet->id,
            'competition_id' => $wallet->competition_id,
            'cmp_uid' => $wallet->cmp_uid,
            'game_type' => (int) $wallet->game_type,
            'level' => $wallet->level,
            'balance' => (float) $wallet->balance,
            'status' => (int) $wallet->status,
            'created_at' => $wallet->created_at?->toDateTimeString(),
            'wins' => ($games[$wallet->id] ?? collect())->where('payment_type', 'win')->count(),
            'losses' => ($games[$wallet->id] ?? collect())->where('payment_type', 'loss')->count(),
            'games' => ($games[$wallet->id] ?? collect())->map(fn (CompetitionTransaction $game) => [
                'transaction_id' => $game->id,
                'payment_type' => $game->payment_type,
                'amount' => (float) $game->amount,
                'level' => $game->level,
                'created_at' => $game->created_at?->toDateTimeString(),
                'opponent' => $opponentRounds[$game->id] ?? null,
            ])->values()->all(),
        ])->values();
    }

    /**
     * The other side of each round, keyed by the customer's transaction id: the `win` written just after a
     * `loss`, or the `loss` written just before a `win`, for the same amount in the same competition.
     *
     * @param  Collection<int, CompetitionTransaction>  $games
     * @param  Collection<int, CompetitionWallet>  $wallets
     * @return array<int, array{competition_wallet_id: int, customer_id: ?int, wallet_status: int, transaction_id: int}>
     */
    private function opponentRounds(Collection $games, Collection $wallets): array
    {
        if ($games->isEmpty()) {
            return [];
        }

        $nearbyIds = $games->flatMap(fn (CompetitionTransaction $game) => range($game->id - 5, $game->id + 5))->unique()->values();

        $candidates = CompetitionTransaction::query()
            ->select(['id', 'competition_wallet_id', 'payment_type', 'amount'])
            ->whereIn('id', $nearbyIds)
            ->whereNotIn('competition_wallet_id', $wallets->pluck('id'))
            ->whereIn('payment_type', ['win', 'loss'])
            ->with('wallet:id,cmp_uid,customer_id,status')
            ->get();

        $cmpUids = $wallets->pluck('cmp_uid', 'id');
        $pairs = [];

        foreach ($games as $game) {
            $isLoss = $game->payment_type === 'loss';

            $match = $candidates
                ->filter(fn (CompetitionTransaction $candidate) => $candidate->payment_type === ($isLoss ? 'win' : 'loss')
                    && ($isLoss ? $candidate->id > $game->id : $candidate->id < $game->id)
                    && (float) $candidate->amount === (float) $game->amount
                    && $candidate->wallet?->cmp_uid === $cmpUids[$game->competition_wallet_id])
                ->sortBy(fn (CompetitionTransaction $candidate) => abs($candidate->id - $game->id))
                ->first();

            if ($match) {
                $pairs[$game->id] = [
                    'competition_wallet_id' => $match->competition_wallet_id,
                    'customer_id' => $match->wallet->customer_id,
                    'wallet_status' => (int) $match->wallet->status,
                    'transaction_id' => $match->id,
                ];
            }
        }

        return $pairs;
    }

    public function getCustomerLeaderboard(?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?? Carbon::now()->subMonths(3)->startOfMonth()->format('Y-m-d');
        $endDate = $endDate ?? Carbon::now()->endOfMonth()->format('Y-m-d');

        $customers = Customer::with([
            'gameWalletTransactions' => function ($query) {
                $query->where('payment_type', 'payout');
            },
            'allCompetitionTransactions' => function ($query) {
                $query->where('payment_type', 'win');
            },
        ])
            ->where('status', 1)
            ->where('id', '!=', 1)
            ->get();

        $singleGamesLeaderboard = $customers->map(function ($customer) use ($startDate, $endDate) {
            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'wins' => $customer->gameWalletTransactions
                    ->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59'])
                    ->sum('amount'),
            ];
        })
            ->filter(fn ($customer) => $customer['wins'] > 0)
            ->sortByDesc('wins')
            ->values()
            ->all();

        $competitionsLeaderboard = $customers->map(function ($customer) use ($startDate, $endDate) {
            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'wins' => $customer->allCompetitionTransactions
                    ->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59'])
                    ->sum('amount'),
            ];
        })
            ->filter(fn ($customer) => $customer['wins'] > 0)
            ->sortByDesc('wins')
            ->values()
            ->all();

        return [
            'single_leaderboard' => $singleGamesLeaderboard,
            'competitions_leaderboard' => $competitionsLeaderboard,
        ];
    }

    public function getCombinedLeaderboard(): array
    {
        $startDate = Carbon::now()->startOfWeek()->format('Y-m-d');
        $endDate = Carbon::now()->endOfWeek()->format('Y-m-d');

        $customers = Customer::with([
            'gameWalletTransactions' => function ($query) {
                $query->where('payment_type', 'payout');
            },
            'allCompetitionTransactions' => function ($query) {
                $query->where('payment_type', 'win');
            },
        ])
            ->where('status', 1)
            ->where('id', '!=', 1)
            ->get();

        $combinedLeaderboard = [];

        foreach ($customers as $customer) {
            $singleGameWins = $customer->gameWalletTransactions
                ->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59'])
                ->sum('amount');

            $competitionWins = $customer->allCompetitionTransactions
                ->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59'])
                ->sum('amount');

            $totalWins = $singleGameWins + $competitionWins;

            if ($totalWins > 0) {
                $combinedLeaderboard[] = [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'single_game_wins' => $singleGameWins,
                    'competition_wins' => $competitionWins,
                    'total_wins' => $totalWins,
                ];
            }
        }

        usort($combinedLeaderboard, fn ($a, $b) => $b['total_wins'] <=> $a['total_wins']);

        return ['leaderboard' => $combinedLeaderboard];
    }

    public function getCustomerPurchases($identifier): Collection
    {
        $customer = Customer::query()
            ->where('id', $identifier)
            ->orWhere('account_no', $identifier)
            ->first();

        if (! $customer) {
            return collect();
        }

        return $customer->purchases()->get()->map(function ($purchase) {
            return [
                'id' => $purchase->id,
                'customer' => $purchase->customer->name,
                'amount' => $purchase->amount,
                'type' => $purchase->purchase_type,
                'value' => $purchase->value,
                'created_at' => $purchase->created_at->toDateTimeString(),
            ];
        });
    }

    public function updateCustomer($identifier, array $data): ?Customer
    {
        $customer = Customer::where('id', $identifier)
            ->orWhere('account_no', $identifier)
            ->first();

        if (! $customer) {
            return null;
        }

        $customer->update($data);

        return $customer;
    }

    /**
     * Apply a signed amount to the customer's wallet as a ledgered adjustment.
     */
    /**
     * A signed, audited adjustment of the customer's wallet. A debit that would leave the balance below 0
     * is refused; a credit is always allowed, so a balance that is already negative can be brought back up.
     *
     * @return array{success: bool, message: string, status_code: int}
     */
    public function updateCustomerWallet($identifier, float $amount, ?string $reason = null, ?string $actor = null): array
    {
        $customer = Customer::query()
            ->where('id', $identifier)
            ->orWhere('account_no', $identifier)
            ->first();

        if (! $customer || ! $customer->wallet) {
            return ['success' => false, 'message' => 'Customer not found', 'status_code' => 404];
        }

        return DB::transaction(function () use ($customer, $amount, $reason, $actor) {
            $wallet = Wallet::lockForUpdate()->find($customer->wallet->id);

            if ($amount < 0 && round((float) $wallet->balance + $amount, 2) < 0) {
                return ['success' => false, 'message' => 'Adjustment would leave the wallet balance below 0', 'status_code' => 422];
            }

            $this->ledgerService->recordAdjustment(
                $wallet,
                $amount,
                $reason ?? WalletService::DEFAULT_ADJUSTMENT_REASON,
                $actor,
                ['operation' => 'update_customer_wallet']
            );

            return ['success' => true, 'message' => 'success', 'status_code' => 201];
        });
    }

    /**
     * Soft-delete a customer who holds no money and has nothing in progress. The wallet row is kept, so
     * ledger entries, deposits and reports still point at a real customer and wallet.
     *
     * @return array{success: bool, message: string, status_code: int, open?: list<string>}
     */
    public function deleteCustomer($identifier): array
    {
        $customer = Customer::where('id', $identifier)
            ->orWhere('account_no', $identifier)
            ->first();

        if (! $customer) {
            return ['success' => false, 'message' => 'Customer not found', 'status_code' => 404];
        }

        $open = $this->openObligations($customer);

        if ($open !== []) {
            return [
                'success' => false,
                'message' => 'Customer cannot be deleted while they hold money or have activity in progress',
                'status_code' => 409,
                'open' => $open,
            ];
        }

        $customer->delete();

        return ['success' => true, 'message' => 'Customer deleted successfully', 'status_code' => 200];
    }

    /**
     * What stops the customer from being deleted: money the platform owes them, or activity that can
     * still move money into or out of their wallets.
     *
     * @return list<string>
     */
    public function openObligations(Customer $customer): array
    {
        $wallet = $customer->wallet;
        $open = [];

        if ($wallet && (int) $wallet->id === (int) config('wallets.house_wallet_id', 1)) {
            return ['house_wallet'];
        }

        if ($wallet && (float) $wallet->balance !== 0.0) {
            $open[] = 'wallet_balance';
        }

        if ((float) ($customer->coin?->coins ?? 0) > 0) {
            $open[] = 'coin_balance';
        }

        if ((float) ($customer->referralWallet?->balance ?? 0) > 0) {
            $open[] = 'referral_balance';
        }

        $hasOpenGame = GameWallet::query()
            ->where('balance', '>', 0)
            ->whereIn('id', GameTransaction::query()->select('game_wallet_id')->where('customer_id', $customer->id))
            ->exists();

        if ($hasOpenGame) {
            $open[] = 'open_game';
        }

        $hasOpenCompetition = CompetitionWallet::query()
            ->where('customer_id', $customer->id)
            ->where(fn ($query) => $query->where('status', 1)->orWhere('balance', '>', 0))
            ->exists();

        if ($hasOpenCompetition) {
            $open[] = 'open_competition';
        }

        if ($wallet && $this->hasPendingWithdrawal($wallet)) {
            $open[] = 'pending_withdrawal';
        }

        $hasPendingReferralWithdrawal = ReferralWithdrawal::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [ReferralWithdrawal::STATUS_PENDING, ReferralWithdrawal::STATUS_PROCESSING])
            ->exists();

        if ($hasPendingReferralWithdrawal) {
            $open[] = 'pending_referral_withdrawal';
        }

        if (PendingBalance::where('customer_id', $customer->id)->where('status', 'holding')->exists()) {
            $open[] = 'pending_hold';
        }

        $hasOpenDispute = Complaint::where('customer_id', $customer->id)->where('status', Complaint::STATUS_PENDING)->exists()
            || DisputedTransaction::where('customer_id', $customer->id)->where('status', DisputedTransaction::STATUS_HELD)->exists();

        if ($hasOpenDispute) {
            $open[] = 'open_dispute';
        }

        return $open;
    }

    /**
     * A withdrawal still waiting on M-Pesa: reserved but not yet sent (status 1), or accepted and still
     * carrying its ConversationID because the B2C result has not arrived (a result replaces it).
     */
    private function hasPendingWithdrawal(Wallet $wallet): bool
    {
        return $wallet->transactions()
            ->where('payment_type', Withdraw::class)
            ->where(function ($query) {
                $query->where('status', 1)
                    ->orWhere(fn ($accepted) => $accepted->where('status', 2)
                        ->whereExists(fn ($payout) => $payout->select(DB::raw(1))
                            ->from('outgoing_payments')
                            ->whereColumn('outgoing_payments.id', 'transactions.payment_id')
                            ->whereColumn('outgoing_payments.receipt', 'transactions.payment_ref')));
            })
            ->exists();
    }
}
