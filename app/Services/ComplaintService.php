<?php

namespace App\Services;

use App\Exceptions\ComplaintException;
use App\Models\CompetitionTransaction;
use App\Models\CompetitionWallet;
use App\Models\Complaint;
use App\Models\DisputedTransaction;
use App\Models\GameTransaction;
use App\Models\GameWallet;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Complaints about game, tournament and jackpot results. Filing one moves the disputed winnings into
 * dispute escrow so the winner cannot spend them while the complaint is pending.
 */
class ComplaintService
{
    private const GAME_PAYOUT_TYPES = ['payout', 'payout|dropped'];

    private const OPEN_COMPETITION_TYPES = ['win', 'deposit'];

    /**
     * Customer and competition wallets locked during this filing, so two disputes held from the same
     * wallet reduce one in-memory balance.
     *
     * @var array<string, Wallet|CompetitionWallet>
     */
    private array $lockedWallets = [];

    public function __construct(private LedgerService $ledgerService) {}

    /**
     * File a complaint and hold every disputed transaction. When a source wallet cannot cover a
     * transaction, whatever it has is held and the rest is recorded as a shortfall.
     *
     * @param  array{customer_id: int, game_wallet_id?: ?int, competition_wallet_id?: ?int, transaction_ids?: ?list<int>, reason: string, description?: ?string}  $data
     *
     * @throws ComplaintException
     */
    public function file(array $data, ?string $actor): Complaint
    {
        $this->lockedWallets = [];

        return DB::transaction(function () use ($data, $actor) {
            $customerId = (int) $data['customer_id'];
            $transactionIds = array_map('intval', $data['transaction_ids'] ?? []);

            [$subjectType, $targets] = empty($data['game_wallet_id'])
                ? $this->competitionTargets((int) $data['competition_wallet_id'], $customerId, $transactionIds)
                : $this->gameTargets((int) $data['game_wallet_id'], $customerId, $transactionIds);

            foreach ($targets as $target) {
                $this->ensureNotAlreadyDisputed($target['transaction']);
            }

            $complaint = Complaint::create([
                'complaint_id' => (string) Str::uuid(),
                'customer_id' => $customerId,
                'subject_type' => $subjectType,
                'game_wallet_id' => $data['game_wallet_id'] ?? null,
                'competition_wallet_id' => $data['competition_wallet_id'] ?? null,
                'reason' => $data['reason'],
                'description' => $data['description'] ?? null,
                'status' => Complaint::STATUS_PENDING,
                'filed_by' => $actor,
            ]);

            $disputed = $held = 0.0;

            foreach ($targets as $target) {
                $dispute = $this->hold($complaint, $target['transaction'], $target['source'], $target['amount']);

                $disputed += (float) $dispute->amount;
                $held += (float) $dispute->held_amount;
            }

            $complaint->update([
                'disputed_amount' => round($disputed, 2),
                'held_amount' => round($held, 2),
                'shortfall_amount' => round($disputed - $held, 2),
            ]);

            return $complaint->load('disputedTransactions');
        });
    }

    /**
     * A valid complaint: every disputed transaction is reversed and the players who funded it are refunded
     * to their main wallets. A game refunds every player's net stake from what was held plus the game's
     * house cuts, which are reversed. A tournament or jackpot reverses only the disputed rounds: the held
     * money goes back to the players whose losses (or own stake) funded it. When the money falls short,
     * what there is is shared in proportion to what each player is owed.
     *
     * @throws ComplaintException
     */
    public function resolve(int $complaintId, string $note, ?string $actor): Complaint
    {
        return DB::transaction(function () use ($complaintId, $note, $actor) {
            $complaint = $this->lockedPendingComplaint($complaintId);
            $disputes = $complaint->disputedTransactions()->held()->orderBy('id')->lockForUpdate()->get();
            $houseCuts = new Collection;
            $houseWallet = null;

            if ($complaint->subject_type === Complaint::SUBJECT_GAME) {
                // Lock order: the players' wallets, the house wallet, then the house cuts it gives back. The cuts are
                // reversed on this locked house instance: a plain re-read could return the transaction's older
                // snapshot and overwrite a house balance committed while this transaction waited for the lock.
                $stakes = $this->gameStakes((int) $complaint->game_wallet_id);
                foreach (Wallet::whereIn('customer_id', array_keys($stakes))->orderBy('id')->pluck('customer_id') as $customerId) {
                    $this->customerWallet($customerId);
                }
                $houseWallet = Wallet::lockForUpdate()->find(config('wallets.house_wallet_id', 1));
                $houseCuts = $this->gameHouseCuts((int) $complaint->game_wallet_id);
                $pot = $disputes->sum(fn (DisputedTransaction $dispute) => (float) $dispute->balance)
                    + $houseCuts->sum(fn (LedgerEntry $houseCut) => (float) $houseCut->credit);
                $refunds = $this->share($pot, $stakes);
            } else {
                $refunds = [];
                foreach ($disputes as $dispute) {
                    foreach ($this->share((float) $dispute->balance, $this->competitionFunders($dispute)) as $customerId => $amount) {
                        $refunds[$customerId] = round(($refunds[$customerId] ?? 0.0) + $amount, 2);
                    }
                }
            }

            $wallets = [];
            foreach (array_keys($refunds) as $customerId) {
                $wallets[$customerId] = $this->customerWallet($customerId);
            }

            foreach ($disputes as $dispute) {
                $this->ledgerService->recordDisputeRefundFunding($dispute);
                $dispute->status = DisputedTransaction::STATUS_REVERSED;
                $dispute->save();
            }

            foreach ($houseCuts as $houseCut) {
                $this->ledgerService->reverseHouseCut($houseCut, $complaint, $houseWallet);
            }

            foreach ($refunds as $customerId => $amount) {
                $this->ledgerService->recordDisputeRefund($complaint, $wallets[$customerId], $amount);
            }

            $complaint->update([
                'status' => Complaint::STATUS_RESOLVED,
                'refunded_amount' => round(array_sum($refunds), 2),
                'house_cuts_reversed' => round($houseCuts->sum(fn (LedgerEntry $houseCut) => (float) $houseCut->credit), 2),
                'resolution_note' => $note,
                'closed_by' => $actor,
                'closed_at' => now(),
            ]);

            return $complaint->load(['disputedTransactions', 'refunds']);
        });
    }

    /**
     * An invalid complaint: everything held goes back to the wallets it was taken from.
     *
     * @throws ComplaintException
     */
    public function reject(int $complaintId, string $note, ?string $actor): Complaint
    {
        return $this->release($complaintId, Complaint::STATUS_REJECTED, $note, $actor);
    }

    /**
     * A withdrawn complaint: everything held goes back to the wallets it was taken from.
     *
     * @throws ComplaintException
     */
    public function cancel(int $complaintId, string $note, ?string $actor): Complaint
    {
        return $this->release($complaintId, Complaint::STATUS_CANCELLED, $note, $actor);
    }

    /**
     * @throws ComplaintException
     */
    private function release(int $complaintId, string $status, string $note, ?string $actor): Complaint
    {
        return DB::transaction(function () use ($complaintId, $status, $note, $actor) {
            $complaint = $this->lockedPendingComplaint($complaintId);
            $released = 0.0;

            foreach ($complaint->disputedTransactions()->held()->orderBy('id')->lockForUpdate()->get() as $dispute) {
                $released += (float) $dispute->balance;
                $this->ledgerService->recordDisputeRelease($dispute, $this->sourceWallet($dispute));

                $dispute->status = DisputedTransaction::STATUS_RELEASED;
                $dispute->save();
            }

            $complaint->update([
                'status' => $status,
                'released_amount' => round($released, 2),
                'resolution_note' => $note,
                'closed_by' => $actor,
                'closed_at' => now(),
            ]);

            return $complaint->load(['disputedTransactions', 'refunds']);
        });
    }

    /**
     * @throws ComplaintException
     */
    private function lockedPendingComplaint(int $complaintId): Complaint
    {
        $this->lockedWallets = [];
        $complaint = Complaint::lockForUpdate()->find($complaintId);

        if (! $complaint) {
            throw new ComplaintException('Complaint not found', 404);
        }

        if (! $complaint->isPending()) {
            throw ComplaintException::conflict("Complaint is already {$complaint->status}.");
        }

        return $complaint;
    }

    /**
     * What each player put into a game and has not had back, keyed by customer id. The house is left out.
     *
     * @return array<int, float>
     */
    private function gameStakes(int $gameWalletId): array
    {
        $stakes = GameTransaction::where('game_wallet_id', $gameWalletId)
            ->where('customer_id', '!=', $this->houseCustomerId())
            ->selectRaw("customer_id, SUM(CASE WHEN payment_type = 'deposit' THEN amount ELSE 0 END) - SUM(CASE WHEN payment_type LIKE 'refund%' THEN amount ELSE 0 END) as net_stake")
            ->groupBy('customer_id')
            ->pluck('net_stake', 'customer_id');

        return $stakes->map(fn ($stake) => (float) $stake)->filter(fn (float $stake) => $stake > 0)->all();
    }

    /**
     * House cuts taken on a game's transactions that have not been reversed.
     *
     * @return Collection<int, LedgerEntry>
     */
    private function gameHouseCuts(int $gameWalletId): Collection
    {
        return LedgerEntry::where('entry_type', 'house_cut')
            ->where('status', 'settled')
            ->where('referenceable_type', GameTransaction::class)
            ->whereIn('referenceable_id', GameTransaction::where('game_wallet_id', $gameWalletId)->select('id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Who paid for a disputed competition transaction, and how much of it, keyed by customer id. A win was
     * funded by the player who lost that round; a bet by the player who placed it; a payout by the losers of
     * every round the wallet won plus the wallet owner's own remaining stake.
     *
     * @return array<int, float>
     *
     * @throws ComplaintException
     */
    private function competitionFunders(DisputedTransaction $dispute): array
    {
        $transaction = CompetitionTransaction::with('wallet')->findOrFail($dispute->disputable_id);
        $funders = [];

        $addFunder = function (int $customerId, float $amount) use (&$funders) {
            if ($amount > 0) {
                $funders[$customerId] = ($funders[$customerId] ?? 0.0) + $amount;
            }
        };

        if ($transaction->payment_type === 'deposit') {
            $addFunder((int) $transaction->customer_id, (float) $dispute->amount);
        } elseif ($transaction->payment_type === 'win') {
            $addFunder((int) $this->losingRound($transaction)->customer_id, (float) $transaction->amount);
        } else {
            $wins = CompetitionTransaction::where('competition_wallet_id', $transaction->competition_wallet_id)
                ->where('payment_type', 'win')
                ->orderBy('id')
                ->get();

            foreach ($wins as $win) {
                $addFunder((int) $this->losingRound($win)->customer_id, (float) $win->amount);
            }

            $addFunder((int) $transaction->customer_id, round((float) $transaction->amount - (float) $wins->sum('amount'), 2));
        }

        if ($funders === []) {
            throw new ComplaintException("No player to refund was found for transaction {$transaction->id}.");
        }

        return $funders;
    }

    /**
     * The `loss` recorded with a round `win`: same competition, same amount, written just before it.
     *
     * @throws ComplaintException
     */
    private function losingRound(CompetitionTransaction $win): CompetitionTransaction
    {
        $loss = CompetitionTransaction::where('payment_type', 'loss')
            ->where('amount', $win->amount)
            ->where('id', '<', $win->id)
            ->whereIn('competition_wallet_id', CompetitionWallet::where('cmp_uid', $win->wallet->cmp_uid)->select('id'))
            ->orderByDesc('id')
            ->first();

        if (! $loss) {
            throw new ComplaintException("The losing round for win {$win->id} was not found.");
        }

        return $loss;
    }

    /**
     * Split an amount between players in proportion to what each is owed, in whole cents. Cents left over
     * from rounding go to the largest claims first, so the parts always add up to the amount.
     *
     * @param  array<int, float>  $claims  keyed by customer id
     * @return array<int, float>
     */
    private function share(float $amount, array $claims): array
    {
        $cents = (int) round($amount * 100);
        $total = array_sum($claims);

        if ($cents <= 0 || $total <= 0) {
            return [];
        }

        $shares = [];
        foreach ($claims as $customerId => $claim) {
            $shares[$customerId] = intdiv((int) round($claim * 100) * $cents, (int) round($total * 100));
        }

        arsort($claims);
        $left = $cents - array_sum($shares);
        foreach (array_keys($claims) as $customerId) {
            if ($left <= 0) {
                break;
            }
            $shares[$customerId]++;
            $left--;
        }

        return array_map(fn (int $share) => $share / 100, array_filter($shares, fn (int $share) => $share > 0));
    }

    /**
     * The wallet a disputed transaction's money was held from.
     *
     * @throws ComplaintException
     */
    private function sourceWallet(DisputedTransaction $dispute): Wallet|CompetitionWallet
    {
        $wallet = $dispute->source_wallet_type === LedgerEntry::WALLET_TYPE_COMPETITION
            ? CompetitionWallet::lockForUpdate()->find($dispute->source_wallet_id)
            : Wallet::lockForUpdate()->find($dispute->source_wallet_id);

        if (! $wallet) {
            throw new ComplaintException("The wallet the money was held from ({$dispute->source_wallet_type} {$dispute->source_wallet_id}) no longer exists.");
        }

        return $wallet;
    }

    /**
     * The winner payouts of a game. The complainant must have played in it and cannot dispute their own payout.
     *
     * @param  list<int>  $transactionIds
     * @return array{0: string, 1: list<array{transaction: GameTransaction, source: Wallet, amount: float}>}
     *
     * @throws ComplaintException
     */
    private function gameTargets(int $gameWalletId, int $customerId, array $transactionIds): array
    {
        $gameWallet = GameWallet::lockForUpdate()->findOrFail($gameWalletId);

        $played = GameTransaction::where('game_wallet_id', $gameWallet->id)
            ->where('customer_id', $customerId)
            ->where('payment_type', 'deposit')
            ->exists();

        if (! $played) {
            throw new ComplaintException('Only a player in this game can complain about it.');
        }

        $payouts = GameTransaction::where('game_wallet_id', $gameWallet->id)
            ->whereIn('payment_type', self::GAME_PAYOUT_TYPES)
            ->whereNotIn('customer_id', [$this->houseCustomerId(), $customerId])
            ->when($transactionIds !== [], fn ($query) => $query->whereIn('id', $transactionIds))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $this->ensureAllFound($payouts, $transactionIds, 'winner payouts in this game');

        if ($payouts->isEmpty()) {
            throw new ComplaintException('This game has no winner payout to dispute.');
        }

        $targets = $payouts->map(fn (GameTransaction $payout) => [
            'transaction' => $payout,
            'source' => $this->customerWallet($payout->customer_id),
            'amount' => (float) $payout->amount,
        ])->all();

        return [Complaint::SUBJECT_GAME, $targets];
    }

    /**
     * An open competition wallet has the named win/deposit transactions (default the latest win) held from
     * the wallet itself. A closed one has its payout held from the customer's wallet.
     *
     * @param  list<int>  $transactionIds
     * @return array{0: string, 1: list<array{transaction: CompetitionTransaction, source: Wallet|CompetitionWallet, amount: float}>}
     *
     * @throws ComplaintException
     */
    private function competitionTargets(int $competitionWalletId, int $customerId, array $transactionIds): array
    {
        $competitionWallet = $this->lockedCompetitionWallet($competitionWalletId);

        $subjectType = match ((int) $competitionWallet->game_type) {
            1 => Complaint::SUBJECT_TOURNAMENT,
            2 => Complaint::SUBJECT_JACKPOT,
            default => throw new ComplaintException('Unsupported competition type.'),
        };

        if ((int) $competitionWallet->customer_id === $customerId) {
            throw new ComplaintException('A customer cannot complain about their own competition wallet.');
        }

        $played = CompetitionWallet::where('cmp_uid', $competitionWallet->cmp_uid)
            ->where('customer_id', $customerId)
            ->exists();

        if (! $played) {
            throw new ComplaintException('Only a player in this competition can complain about it.');
        }

        $isOpen = (int) $competitionWallet->status === 1;

        $transactions = CompetitionTransaction::where('competition_wallet_id', $competitionWallet->id)
            ->when(
                $isOpen,
                fn ($query) => $query->whereIn('payment_type', $transactionIds === [] ? ['win'] : self::OPEN_COMPETITION_TYPES),
                fn ($query) => $query->where('payment_type', 'payout')
            )
            ->when($transactionIds !== [], fn ($query) => $query->whereIn('id', $transactionIds))
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        $this->ensureAllFound($transactions, $transactionIds, $isOpen ? 'win or deposit transactions on this competition wallet' : 'payouts from this competition wallet');

        if ($isOpen && $transactionIds === []) {
            $transactions = $transactions->take(1);
        }

        if ($transactions->isEmpty()) {
            throw new ComplaintException($isOpen
                ? 'This competition wallet has no win to dispute.'
                : 'This competition wallet has no payout to dispute.');
        }

        $targets = $transactions->sortBy('id')->values()->map(fn (CompetitionTransaction $transaction) => [
            'transaction' => $transaction,
            'source' => $isOpen ? $competitionWallet : $this->customerWallet($competitionWallet->customer_id),
            'amount' => $this->creditedToCompetitionWallet($transaction),
        ])->all();

        return [$subjectType, $targets];
    }

    /**
     * Create the disputed transaction row and move what the source can cover into dispute escrow.
     */
    private function hold(Complaint $complaint, GameTransaction|CompetitionTransaction $transaction, Wallet|CompetitionWallet $source, float $amount): DisputedTransaction
    {
        $heldAmount = round(min($amount, max((float) $source->balance, 0.0)), 2);

        $dispute = DisputedTransaction::create([
            'complaint_id' => $complaint->id,
            'disputable_type' => $transaction::class,
            'disputable_id' => $transaction->id,
            'customer_id' => $transaction->customer_id,
            'source_wallet_type' => $source instanceof Wallet ? LedgerEntry::WALLET_TYPE_WALLET : LedgerEntry::WALLET_TYPE_COMPETITION,
            'source_wallet_id' => $source->id,
            'amount' => $amount,
            'held_amount' => $heldAmount,
            'shortfall_amount' => round($amount - $heldAmount, 2),
            'balance' => 0,
            'status' => DisputedTransaction::STATUS_HELD,
        ]);

        if ($heldAmount > 0) {
            $this->ledgerService->recordDisputeHold($dispute, $source, $heldAmount);
        }

        return $dispute;
    }

    /**
     * @throws ComplaintException
     */
    private function ensureNotAlreadyDisputed(GameTransaction|CompetitionTransaction $transaction): void
    {
        $open = DisputedTransaction::held()
            ->where('disputable_type', $transaction::class)
            ->where('disputable_id', $transaction->id)
            ->exists();

        if ($open) {
            throw ComplaintException::conflict("Transaction {$transaction->id} is already under an open complaint.");
        }
    }

    /**
     * @param  Collection<int, GameTransaction|CompetitionTransaction>  $found
     * @param  list<int>  $transactionIds
     *
     * @throws ComplaintException
     */
    private function ensureAllFound(Collection $found, array $transactionIds, string $expected): void
    {
        $missing = array_values(array_diff(array_unique($transactionIds), $found->pluck('id')->all()));

        if ($missing !== []) {
            throw new ComplaintException('transaction_ids must be '.$expected.'. Not found: '.implode(', ', $missing).'.');
        }
    }

    /**
     * What a competition transaction put into its wallet. A bet credits its stake minus the house cut.
     */
    private function creditedToCompetitionWallet(CompetitionTransaction $transaction): float
    {
        if ($transaction->payment_type === 'deposit'
            && $transaction->competition_wallet_balance_before !== null
            && $transaction->competition_wallet_balance_after !== null) {
            return round((float) $transaction->competition_wallet_balance_after - (float) $transaction->competition_wallet_balance_before, 2);
        }

        return (float) $transaction->amount;
    }

    /**
     * @throws ComplaintException
     */
    private function customerWallet(int $customerId): Wallet
    {
        $key = LedgerEntry::WALLET_TYPE_WALLET.':'.$customerId;

        if (! isset($this->lockedWallets[$key])) {
            $wallet = Wallet::where('customer_id', $customerId)->lockForUpdate()->first();

            if (! $wallet) {
                throw new ComplaintException("Customer {$customerId} has no wallet to hold the disputed money from.");
            }

            $this->lockedWallets[$key] = $wallet;
        }

        return $this->lockedWallets[$key];
    }

    private function lockedCompetitionWallet(int $competitionWalletId): CompetitionWallet
    {
        $key = LedgerEntry::WALLET_TYPE_COMPETITION.':'.$competitionWalletId;

        return $this->lockedWallets[$key] ??= CompetitionWallet::lockForUpdate()->findOrFail($competitionWalletId);
    }

    private function houseCustomerId(): int
    {
        return (int) (Wallet::whereKey(config('wallets.house_wallet_id', 1))->value('customer_id') ?? 1);
    }
}
