<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\GameTransaction;
use App\Models\GameWallet;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GameWalletService
{
    /**
     * Customer id the game system writes on the house's rows in game_transactions (house cuts and drop
     * shares). Finance reports read house rows by it, so it stays fixed even if the house wallet id changes.
     */
    private const HOUSE_CUSTOMER_ID = 1;

    public function __construct(private LedgerService $ledgerService) {}

    /**
     * The refusal for any action on a game that is already settled (paid out, dropped or refunded).
     *
     * @return array{error: string, status: int, code: string}
     */
    private function gameClosed(): array
    {
        return ['error' => 'This game is already settled', 'status' => 409, 'code' => 'game_closed'];
    }

    private function houseWalletId(): int
    {
        return (int) config('wallets.house_wallet_id', 1);
    }

    /**
     * Every game wallet, or one page of them (newest first) when a page size is given.
     */
    public function listGameWallets(?int $perPage = null): Collection|LengthAwarePaginator
    {
        return $perPage === null ? GameWallet::all() : GameWallet::latest('id')->paginate($perPage);
    }

    public function createGameWallet(array $data): GameWallet
    {
        return GameWallet::create($data);
    }

    public function getGameWallet($identifier): ?GameWallet
    {
        return GameWallet::where('id', $identifier)
            ->orWhere('game_id', $identifier)
            ->first();
    }

    public function getGameIncome(?string $startDate = null, ?string $endDate = null): array
    {
        return DB::table('game_wallets as G')
            ->selectSub(function ($query) {
                $query->from('game_transactions')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('game_wallet_id', 'G.id')
                    ->where('payment_type', 'deposit');
            }, 'players')
            ->selectRaw('SUM(T.amount) as total_income')
            ->selectRaw('COUNT(DISTINCT G.id) as games_played')
            ->join('game_transactions as T', 'T.game_wallet_id', '=', 'G.id')
            ->where('T.payment_type', 'payout')
            ->where('T.customer_id', 1)
            ->whereBetween('T.created_at', [$startDate, $endDate])
            ->groupBy('players')
            ->orderBy('players')
            ->get()
            ->toArray();
    }

    /**
     * Payment types that pay out a game's winners (a normal win, or a split after a dropped connection).
     */
    private const GAME_PAYOUT_TYPES = ['payout', 'payout|dropped'];

    /**
     * Payment types that decide a game's outcome, so the game belongs in the results.
     */
    private const GAME_SETTLED_TYPES = ['payout', 'payout|dropped', 'refund|full'];

    private const GAME_REFUND_TYPES = ['refund|full', 'refund|dropped'];

    /**
     * Every decided game, oldest first, each with its players and whether they won or lost.
     *
     * @return list<array<string, mixed>>
     */
    public function getGameResults(): array
    {
        $games = $this->settledGamesQuery()->orderBy('GW.id')->get();

        return $this->buildGameResults($games, $this->gameResultTransactions());
    }

    /**
     * One page of decided games, newest first.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function paginateGameResults(int $perPage, int $page = 1): array
    {
        $paginator = $this->settledGamesQuery()->orderByDesc('GW.id')->paginate($perPage, ['*'], 'page', $page);
        $games = collect($paginator->items());

        return [
            'items' => $this->buildGameResults($games, $this->gameResultTransactions($games->pluck('id')->all())),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    private function settledGamesQuery(): Builder
    {
        return DB::table('game_wallets as GW')
            ->select('GW.id', 'GW.game_id')
            ->whereExists(function ($query) {
                $query->from('game_transactions')
                    ->whereColumn('game_wallet_id', 'GW.id')
                    ->whereIn('payment_type', self::GAME_SETTLED_TYPES);
            });
    }

    /**
     * The stakes, payouts and refunds of the given games (all games when null), grouped by game.
     *
     * @param  list<int>|null  $gameWalletIds
     * @return Collection<int|string, Collection<int, object>>
     */
    private function gameResultTransactions(?array $gameWalletIds = null): Collection
    {
        return DB::table('game_transactions as GT')
            ->leftJoin('customers as C', 'C.id', '=', 'GT.customer_id')
            ->when($gameWalletIds !== null, fn ($query) => $query->whereIn('GT.game_wallet_id', $gameWalletIds))
            ->whereIn('GT.payment_type', ['deposit', ...self::GAME_PAYOUT_TYPES, ...self::GAME_REFUND_TYPES])
            ->select('GT.game_wallet_id', 'GT.customer_id', 'C.name', 'GT.amount', 'GT.payment_type', 'GT.created_at')
            ->orderBy('GT.id')
            ->get()
            ->groupBy('game_wallet_id');
    }

    /**
     * @param  Collection<int, object>  $games
     * @param  Collection<int|string, Collection<int, object>>  $transactionsByGame
     * @return list<array<string, mixed>>
     */
    private function buildGameResults(Collection $games, Collection $transactionsByGame): array
    {
        $houseId = self::HOUSE_CUSTOMER_ID;

        return $games->map(function (object $game) use ($transactionsByGame, $houseId) {
            $transactions = $transactionsByGame->get($game->id, collect());
            $deposits = $transactions->where('payment_type', 'deposit');
            $payouts = $transactions->whereIn('payment_type', self::GAME_PAYOUT_TYPES);
            $playerPayouts = $payouts->where('customer_id', '!=', $houseId);
            $winner = $playerPayouts->first();
            $settledAt = $transactions->whereIn('payment_type', self::GAME_SETTLED_TYPES)->first();

            return [
                'id' => $game->id,
                'game_id' => $game->game_id,
                'players' => $deposits->count(),
                'total_bet' => (float) $deposits->sum('amount'),
                'customer_id' => $winner?->customer_id,
                'name' => $winner?->name,
                'amount' => (float) $playerPayouts->sum('amount'),
                'income' => (float) $payouts->where('customer_id', $houseId)->sum('amount'),
                'settlement' => $this->gameSettlement($transactions),
                'participants' => $this->gameParticipants($transactions, $houseId),
                'created_at' => $settledAt?->created_at,
            ];
        })->values()->all();
    }

    /**
     * How the game ended: a normal payout, a split after a dropped connection, or a full refund.
     *
     * @param  Collection<int, object>  $transactions
     */
    private function gameSettlement(Collection $transactions): string
    {
        return match (true) {
            $transactions->contains('payment_type', 'payout') => 'payout',
            $transactions->contains('payment_type', 'payout|dropped') => 'dropped',
            default => 'refunded',
        };
    }

    /**
     * Each staking player (the house left out) with their result: win when they were paid out,
     * refunded when they only got their stake back, loss otherwise.
     *
     * @param  Collection<int, object>  $transactions
     * @return list<array{customer_id: int, name: string|null, stake: float, amount_won: float, amount_refunded: float, result: string}>
     */
    private function gameParticipants(Collection $transactions, int $houseId): array
    {
        return $transactions
            ->where('payment_type', 'deposit')
            ->where('customer_id', '!=', $houseId)
            ->groupBy('customer_id')
            ->map(function (Collection $stakes, int|string $customerId) use ($transactions) {
                $own = $transactions->where('customer_id', $customerId);
                $won = (float) $own->whereIn('payment_type', self::GAME_PAYOUT_TYPES)->sum('amount');
                $refunded = (float) $own->whereIn('payment_type', self::GAME_REFUND_TYPES)->sum('amount');

                return [
                    'customer_id' => (int) $customerId,
                    'name' => $stakes->first()->name,
                    'stake' => (float) $stakes->sum('amount'),
                    'amount_won' => $won,
                    'amount_refunded' => $refunded,
                    'result' => match (true) {
                        $won > 0 => 'win',
                        $refunded > 0 => 'refunded',
                        default => 'loss',
                    },
                ];
            })
            ->values()
            ->all();
    }

    public function updateGameWallet($id, array $data): ?GameWallet
    {
        $gameWallet = GameWallet::where('id', $id)->first();
        if (! $gameWallet) {
            return null;
        }
        $gameWallet->update($data);

        return $gameWallet;
    }

    public function deleteGameWallet($id): bool
    {
        $gameWallet = GameWallet::where('id', $id)->first();
        if (! $gameWallet) {
            return false;
        }

        return $gameWallet->delete();
    }

    public function processGameWithdrawal(int $gameWalletId, int $customerId): array
    {
        $gameWallet = GameWallet::find($gameWalletId);
        if (! $gameWallet) {
            return ['error' => 'Game Wallet not found', 'status' => 404];
        }

        $customer = Customer::where('id', $customerId)
            ->orWhere('account_no', $customerId)
            ->first();
        if (! $customer) {
            return ['error' => 'Customer not found', 'status' => 404];
        }

        if (! $customer->wallet) {
            return ['error' => 'Customer wallet not found', 'status' => 404];
        }

        return DB::transaction(function () use ($gameWalletId, $customer) {
            $gameWallet = GameWallet::lockForUpdate()->find($gameWalletId);

            if ($gameWallet->status !== 1) {
                return $this->gameClosed();
            }

            $wallet = Wallet::lockForUpdate()->find($customer->wallet->id);
            $totalBalance = $gameWallet->balance;
            $houseShare = floor($totalBalance * config('finance.fees.game_withdrawal'));
            $playerShare = $totalBalance - $houseShare;

            $playerTransaction = GameTransaction::create([
                'game_wallet_id' => $gameWallet->id,
                'customer_id' => $customer->id,
                'amount' => $playerShare,
                'payment_type' => 'payout',
                'status' => 2,
            ]);

            $playerEntry = $this->ledgerService->recordGamePayout(
                $playerTransaction,
                $wallet,
                (float) $playerShare
            );

            $playerTransaction->update([
                'wallet_balance_before' => $playerEntry->balance_before,
                'wallet_balance_after' => $playerEntry->balance_after,
                'game_balance_before' => $totalBalance,
                'game_balance_after' => 0,
            ]);

            if ($houseShare > 0) {
                $houseWallet = Wallet::lockForUpdate()->find($this->houseWalletId());

                $houseTransaction = GameTransaction::create([
                    'game_wallet_id' => $gameWallet->id,
                    'customer_id' => self::HOUSE_CUSTOMER_ID,
                    'amount' => $houseShare,
                    'payment_type' => 'payout',
                    'status' => 2,
                ]);

                $houseEntry = $this->ledgerService->recordHouseCut(
                    $houseWallet,
                    (float) $houseShare,
                    'game_withdrawal',
                    $houseTransaction,
                    ['game_wallet_id' => $gameWallet->id]
                );

                $houseTransaction->update([
                    'wallet_balance_before' => $houseEntry->balance_before,
                    'wallet_balance_after' => $houseEntry->balance_after,
                    'game_balance_before' => $totalBalance,
                    'game_balance_after' => 0,
                ]);
            }

            $this->ledgerService->recordEscrowRelease($playerTransaction, $gameWallet, (float) $totalBalance, 'game_withdrawal');

            $gameWallet->balance = 0;
            $gameWallet->status = 3;
            $gameWallet->save();

            return ['status' => 'success'];
        });
    }

    /**
     * Give every stake back and close the game. Only an open game can be refunded: a game already paid out,
     * dropped or refunded is refused (409 game_closed), so stakes are never given back twice.
     */
    public function processFullRefund(int $gameWalletId): array
    {
        if (! GameWallet::whereKey($gameWalletId)->exists()) {
            return ['error' => 'Game Wallet not found', 'status' => 404];
        }

        return DB::transaction(function () use ($gameWalletId) {
            $gameWallet = GameWallet::lockForUpdate()->find($gameWalletId);

            if ($gameWallet->status !== 1) {
                return $this->gameClosed();
            }

            $depositsByCustomer = GameTransaction::where('game_wallet_id', $gameWalletId)
                ->where('payment_type', 'deposit')
                ->where('status', 1)
                ->select('customer_id', DB::raw('SUM(amount) as total_amount'))
                ->groupBy('customer_id')
                ->get();

            if ($depositsByCustomer->count() === 0) {
                return ['error' => 'No deposit transactions found for this game wallet', 'status' => 404];
            }

            foreach ($depositsByCustomer as $deposit) {
                $playerWallet = Wallet::where('customer_id', $deposit->customer_id)->lockForUpdate()->first();
                if (! $playerWallet) {
                    continue;
                }

                $refundTransaction = GameTransaction::create([
                    'game_wallet_id' => $gameWallet->id,
                    'customer_id' => $deposit->customer_id,
                    'amount' => $deposit->total_amount,
                    'payment_type' => 'refund|full',
                    'status' => 2,
                ]);

                $ledgerEntry = $this->ledgerService->recordRefund(
                    $refundTransaction,
                    $playerWallet,
                    (float) $deposit->total_amount,
                    'game_full_refund'
                );

                $refundTransaction->update([
                    'wallet_balance_before' => $ledgerEntry->balance_before,
                    'wallet_balance_after' => $ledgerEntry->balance_after,
                ]);
            }

            $this->ledgerService->recordEscrowRelease($gameWallet, $gameWallet, (float) $gameWallet->balance, 'game_full_refund');

            $gameWallet->balance = 0;
            $gameWallet->status = 3;
            $gameWallet->save();

            return ['status' => 'success'];
        });
    }

    /**
     * Pay a remaining player their share of a game voided because another player dropped out.
     *
     * The game server sends one call per remaining player with the share it worked out (the pot less
     * the house's cut, split equally). The first share closes the game; later shares of the same game
     * are still accepted. Once less than one share is left in escrow, that rest is the house's cut and
     * is swept to the house wallet.
     *
     * @return array{status: int|string, error?: string}
     */
    public function processDroppedGameShare(int $gameWalletId, int $customerId, float $amount): array
    {
        return DB::transaction(function () use ($gameWalletId, $customerId, $amount) {
            $gameWallet = GameWallet::lockForUpdate()->find($gameWalletId);
            if (! $gameWallet) {
                return ['error' => 'Game Wallet not found', 'status' => 404];
            }

            $wallet = Wallet::where('customer_id', $customerId)->lockForUpdate()->first();
            if (! $wallet) {
                return ['error' => 'Customer wallet not found', 'status' => 404];
            }

            $customerTransactions = GameTransaction::where('game_wallet_id', $gameWallet->id)->where('customer_id', $customerId);

            if (! (clone $customerTransactions)->where('payment_type', 'deposit')->exists()) {
                return ['error' => 'Customer did not stake in this game', 'status' => 422];
            }

            if ((clone $customerTransactions)->whereIn('payment_type', [...self::GAME_PAYOUT_TYPES, ...self::GAME_REFUND_TYPES])->exists()) {
                return ['error' => 'Customer has already been paid for this game', 'status' => 409];
            }

            if ($gameWallet->status != 1 && ! $this->hasDroppedGameShares($gameWallet)) {
                return ['error' => 'Game Wallet is already closed', 'status' => 409];
            }

            if (round($amount, 2) > round((float) $gameWallet->balance, 2)) {
                return ['error' => 'Share is more than the game wallet balance', 'status' => 409];
            }

            $gameBalanceBefore = (float) $gameWallet->balance;

            $shareTransaction = GameTransaction::create([
                'game_wallet_id' => $gameWallet->id,
                'customer_id' => $customerId,
                'amount' => $amount,
                'payment_type' => 'payout|dropped',
                'status' => 2,
            ]);

            $shareEntry = $this->ledgerService->recordGamePayout($shareTransaction, $wallet, $amount);
            $this->ledgerService->recordEscrowRelease($shareTransaction, $gameWallet, $amount, self::DROPPED_SHARE_REASON);

            $shareTransaction->update([
                'wallet_balance_before' => $shareEntry->balance_before,
                'wallet_balance_after' => $shareEntry->balance_after,
                'game_balance_before' => $gameBalanceBefore,
                'game_balance_after' => $gameWallet->balance,
            ]);

            $remainder = round((float) $gameWallet->balance, 2);
            if ($remainder > 0 && $remainder < round($amount, 2)) {
                $this->sweepDroppedGameRemainder($gameWallet, $remainder);
            }

            $gameWallet->status = 3;
            $gameWallet->save();

            return ['status' => 'success'];
        });
    }

    /**
     * Escrow release reason of a share paid through processDroppedGameShare.
     */
    private const DROPPED_SHARE_REASON = 'game_drop_share';

    /**
     * Whether shares of this game have already been paid through processDroppedGameShare, so the
     * game was closed by an earlier share rather than by a payout or refund.
     */
    private function hasDroppedGameShares(GameWallet $gameWallet): bool
    {
        return LedgerEntry::where('entry_type', 'escrow_release')
            ->where('referenceable_type', GameTransaction::class)
            ->whereIn('referenceable_id', GameTransaction::where('game_wallet_id', $gameWallet->id)
                ->where('payment_type', 'payout|dropped')
                ->select('id'))
            ->where('metadata->reason', self::DROPPED_SHARE_REASON)
            ->exists();
    }

    /**
     * Move what is left of a dropped game's pot, less than one share, to the house wallet as its cut.
     */
    private function sweepDroppedGameRemainder(GameWallet $gameWallet, float $remainder): void
    {
        $houseWallet = Wallet::lockForUpdate()->find(config('wallets.house_wallet_id', 1));
        $gameBalanceBefore = (float) $gameWallet->balance;

        $houseTransaction = GameTransaction::create([
            'game_wallet_id' => $gameWallet->id,
            'customer_id' => self::HOUSE_CUSTOMER_ID,
            'amount' => $remainder,
            'payment_type' => 'payout|dropped',
            'status' => 2,
        ]);

        $houseEntry = $this->ledgerService->recordHouseCut(
            $houseWallet,
            $remainder,
            'game_drop_payout',
            $houseTransaction,
            ['game_wallet_id' => $gameWallet->id]
        );
        $this->ledgerService->recordEscrowRelease($houseTransaction, $gameWallet, $remainder, self::DROPPED_SHARE_REASON);

        $houseTransaction->update([
            'wallet_balance_before' => $houseEntry->balance_before,
            'wallet_balance_after' => $houseEntry->balance_after,
            'game_balance_before' => $gameBalanceBefore,
            'game_balance_after' => $gameWallet->balance,
        ]);
    }

    /**
     * Every game transaction, or one page of them (newest first) when a page size is given.
     */
    public function listGameTransactions(?int $perPage = null): Collection|LengthAwarePaginator
    {
        $query = GameTransaction::with(['gameWallet']);

        return $perPage === null ? $query->get() : $query->latest('id')->paginate($perPage);
    }

    /**
     * Stake into a game: the amount moves from the player's wallet to the game wallet. Both rows are locked
     * and the balance is checked under the lock, so two stakes at once cannot spend the same money. A
     * settled game takes no more stakes (409 game_closed). False when the game, player or money is missing.
     *
     * @return GameTransaction|false|array{error: string, status: int, code: string}
     */
    public function createGameTransaction(array $data): GameTransaction|false|array
    {
        if (! GameWallet::whereKey($data['game_wallet_id'])->exists()) {
            return false;
        }

        $customer = Customer::with('wallet')->find($data['customer_id']);
        if (! $customer || ! $customer->wallet) {
            return false;
        }

        return DB::transaction(function () use ($customer, $data) {
            $gameWallet = GameWallet::lockForUpdate()->find($data['game_wallet_id']);

            if ($gameWallet->status !== 1) {
                return $this->gameClosed();
            }

            $wallet = Wallet::lockForUpdate()->find($customer->wallet->id);

            if ($wallet->balance < $data['amount']) {
                return false;
            }

            $gameTransaction = GameTransaction::create($data);

            [$customerEntry, $gameEntry] = $this->ledgerService->recordGameBet(
                $gameTransaction,
                $wallet,
                $gameWallet,
                (float) $data['amount']
            );

            $gameTransaction->update([
                'wallet_balance_before' => $customerEntry->balance_before,
                'wallet_balance_after' => $customerEntry->balance_after,
                'game_balance_before' => $gameEntry->balance_before,
                'game_balance_after' => $gameEntry->balance_after,
            ]);

            return $gameTransaction;
        });
    }

    public function getGameTransaction(int $id): ?GameTransaction
    {
        return GameTransaction::where('id', $id)->first();
    }

    public function updateGameTransaction(int $id, array $data): ?GameTransaction
    {
        $gameTransaction = GameTransaction::where('id', $id)->first();
        if (! $gameTransaction) {
            return null;
        }
        $gameTransaction->update($data);

        return $gameTransaction;
    }

    public function deleteGameTransaction(int $id): bool
    {
        $gameTransaction = GameTransaction::where('id', $id)->first();
        if (! $gameTransaction) {
            return false;
        }

        return $gameTransaction->delete();
    }

    public function processDropConnection(
        int $gameWalletId,
        array $players,
        array $active,
        array $dropped,
        bool $gameStarted
    ): array {
        $gameWallet = GameWallet::where('id', $gameWalletId)
            ->orWhere('game_id', $gameWalletId)
            ->first();

        if (! $gameWallet) {
            return ['error' => 'Game Wallet not found', 'status' => 404];
        }

        $playersCount = count($players);
        $activeCount = count($active);
        $droppedCount = count($dropped);

        if ($playersCount == 0) {
            return ['error' => 'No players', 'status' => 400];
        }
        if ($activeCount == 0 && $gameStarted) {
            return ['error' => 'No players active', 'status' => 400];
        }
        if ($droppedCount == 0) {
            return ['error' => 'No players dropped', 'status' => 400];
        }

        if ($gameStarted) {
            $invalidActive = array_diff($active, $players);
            if (! empty($invalidActive)) {
                return [
                    'error' => 'Some active players are not in the players list',
                    'invalid' => $invalidActive,
                    'status' => 400,
                ];
            }
        }

        $invalidDropped = array_diff($dropped, $players);
        if (! empty($invalidDropped)) {
            return [
                'error' => 'Some dropped players are not in the players list',
                'invalid' => $invalidDropped,
                'status' => 400,
            ];
        }

        // One transaction with the game row locked: only an open game can be settled, so a repeated drop call
        // is refused (409 game_closed) instead of paying again.
        return DB::transaction(function () use ($gameWallet, $active, $activeCount, $dropped, $gameStarted) {
            $gameWallet = GameWallet::lockForUpdate()->find($gameWallet->id);

            if ($gameWallet->status !== 1) {
                return $this->gameClosed();
            }

            if ($gameStarted) {
                $totalBalance = (float) $gameWallet->gameTransactions()
                    ->whereIn('customer_id', $dropped)
                    ->where('payment_type', 'deposit')
                    ->sum('amount');

                $payouts = $this->processPayouts($totalBalance, $activeCount, $gameWallet, $active, $this->houseWalletId(), true);
            } else {
                $payouts = [];

                foreach ($dropped as $playerId) {
                    $amount = $gameWallet->gameTransactions()
                        ->where('customer_id', $playerId)
                        ->where('payment_type', 'deposit')
                        ->sum('amount');

                    if ($amount > 0) {
                        $this->processRefund($gameWallet, $playerId, $amount);
                        $payouts[] = [
                            'player_id' => $playerId,
                            'amount' => $amount,
                            'type' => 'refund',
                        ];
                    }
                }
            }

            $gameWallet->status = 3;
            $gameWallet->save();

            return [
                'status' => 'success',
                'payouts' => $payouts,
            ];
        });
    }

    public function processRefund(GameWallet $gameWallet, int $playerId, float $amount): void
    {
        $playerWallet = Wallet::where('customer_id', $playerId)->lockForUpdate()->first();
        if (! $playerWallet) {
            return;
        }

        $gameTransaction = GameTransaction::create([
            'game_wallet_id' => $gameWallet->id,
            'customer_id' => $playerId,
            'amount' => $amount,
            'payment_type' => 'refund|dropped',
            'status' => 2,
        ]);

        $ledgerEntry = $this->ledgerService->recordRefund(
            $gameTransaction,
            $playerWallet,
            $amount,
            'game_drop_refund'
        );

        $gameTransaction->update([
            'wallet_balance_before' => $ledgerEntry->balance_before,
            'wallet_balance_after' => $ledgerEntry->balance_after,
        ]);
    }

    public function processPayouts(
        float $totalBalance,
        int $activeCount,
        GameWallet $gameWallet,
        array $active,
        int $houseWalletId,
        bool $gameStarted = true
    ): array {
        if ($gameStarted && $activeCount == 0) {
            throw new \Exception('Active player count cannot be zero when game has started.');
        }

        $houseShare = $gameStarted ? ceil($totalBalance * config('finance.fees.game_drop')) : 0;
        $playerShare = $gameStarted ? floor(($totalBalance - $houseShare) / $activeCount) : 0;

        $payouts = [];

        if ($gameStarted && $houseShare > 0) {
            $houseWallet = Wallet::lockForUpdate()->find($houseWalletId);

            $houseTransaction = GameTransaction::create([
                'game_wallet_id' => $gameWallet->id,
                'customer_id' => self::HOUSE_CUSTOMER_ID,
                'amount' => $houseShare,
                'payment_type' => 'payout|dropped',
                'status' => 2,
            ]);

            $houseEntry = $this->ledgerService->recordHouseCut(
                $houseWallet,
                (float) $houseShare,
                'game_drop_payout',
                $houseTransaction,
                ['game_wallet_id' => $gameWallet->id]
            );

            $houseTransaction->update([
                'wallet_balance_before' => $houseEntry->balance_before,
                'wallet_balance_after' => $houseEntry->balance_after,
            ]);
        }

        if ($gameStarted) {
            foreach ($active as $activePlayer) {
                $activeCustomer = Customer::find($activePlayer);
                if (! $activeCustomer) {
                    continue;
                }

                $wallet = Wallet::where('customer_id', $activeCustomer->id)->lockForUpdate()->first();
                if (! $wallet) {
                    throw new \Exception('Player wallet not found.');
                }

                $payoutTransaction = GameTransaction::create([
                    'game_wallet_id' => $gameWallet->id,
                    'customer_id' => $activeCustomer->id,
                    'amount' => $playerShare,
                    'payment_type' => 'payout|dropped',
                    'status' => 2,
                ]);

                $ledgerEntry = $this->ledgerService->recordGamePayout(
                    $payoutTransaction,
                    $wallet,
                    (float) $playerShare
                );

                $payoutTransaction->update([
                    'wallet_balance_before' => $ledgerEntry->balance_before,
                    'wallet_balance_after' => $ledgerEntry->balance_after,
                ]);

                $payouts[] = [
                    'player_id' => $activeCustomer->id,
                    'amount' => $playerShare,
                    'type' => 'payout',
                ];
            }
        }

        return $payouts;
    }
}
