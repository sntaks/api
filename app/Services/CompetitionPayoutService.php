<?php

namespace App\Services;

use App\Models\CompetitionTransaction;
use App\Models\CompetitionWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompetitionPayoutService
{
    public function __construct(
        private LedgerService $ledgerService,
        private CompetitionWalletService $walletService
    ) {}

    public function processPayout(int $senderId, int $receiverId, bool $receiverWithdraw = false): array
    {
        $sender = CompetitionWallet::find($senderId);
        $receiver = CompetitionWallet::find($receiverId);

        if (! $sender || ! $receiver) {
            throw new \InvalidArgumentException('Invalid Competition Wallet(s)');
        }
        if ($senderId === $receiverId) {
            throw new \InvalidArgumentException('Sender and Receiver Competition Wallets must be different');
        }
        $this->assertTransferable($sender, $receiver);
        if ($sender->game_type != $receiver->game_type) {
            throw new \InvalidArgumentException('Competition Wallets must have same game type');
        }
        if ($sender->cmp_uid != $receiver->cmp_uid) {
            throw new \InvalidArgumentException('Competition Wallets must have same competition');
        }

        if ($sender->game_type == 1) {
            $result = $this->handleTournamentPayout($sender, $receiver);
        } elseif ($sender->game_type == 2) {
            $result = $this->handleJackpotPayout($sender, $receiver);
        } else {
            throw new \InvalidArgumentException('Unsupported game type');
        }

        if ($receiverWithdraw) {
            $result['withdrawal'] = $this->attemptReceiverWithdrawal($receiver);
        }

        return $result;
    }

    private function attemptReceiverWithdrawal(CompetitionWallet $receiver): array
    {
        try {
            return $this->walletService->processWithdrawal($receiver->id, $receiver->customer_id);
        } catch (\Throwable $e) {
            Log::error('Competition Wallet Receiver Withdrawal Error', [
                'message' => $e->getMessage(),
                'competition_wallet_id' => $receiver->id,
            ]);

            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    /**
     * @throws \Throwable
     */
    public function handleTournamentPayout(CompetitionWallet $sender, CompetitionWallet $receiver): array
    {
        return $this->transferRoundResult($sender, $receiver);
    }

    /**
     * Jackpot rounds transfer exactly like tournament rounds. `jp_rounds` is intentionally not
     * consulted here: it has no bearing on round-by-round transfer mechanics.
     *
     * @throws \Throwable
     */
    public function handleJackpotPayout(CompetitionWallet $sender, CompetitionWallet $receiver): array
    {
        return $this->transferRoundResult($sender, $receiver);
    }

    /**
     * Move the sender's full balance to the receiver and record the paired loss/win transactions.
     *
     * @return array{status: string}
     *
     * @throws \Throwable
     */
    private function transferRoundResult(CompetitionWallet $sender, CompetitionWallet $receiver): array
    {
        DB::transaction(function () use ($sender, $receiver) {
            // Both competition wallets are locked in id order and processPayout's checks are repeated on the locked
            // rows, so two round results at once cannot move the same balance twice
            $locked = [];
            foreach (collect([$sender->id, $receiver->id])->sort() as $id) {
                $locked[$id] = CompetitionWallet::lockForUpdate()->find($id)
                    ?? throw new \InvalidArgumentException('Invalid Competition Wallet(s)');
            }
            [$sender, $receiver] = [$locked[$sender->id], $locked[$receiver->id]];
            $this->assertTransferable($sender, $receiver);

            $totalBalance = $sender->balance;

            $senderCustomer = $sender->customer;
            $senderTransaction = CompetitionTransaction::create([
                'competition_wallet_id' => $sender->id,
                'customer_id' => $senderCustomer->id,
                'amount' => $totalBalance,
                'payment_type' => 'loss',
                'level' => $sender->level,
                'status' => 2,
            ]);

            $receiverCustomer = $receiver->customer;
            $receiverTransaction = CompetitionTransaction::create([
                'competition_wallet_id' => $receiver->id,
                'customer_id' => $receiverCustomer->id,
                'amount' => $totalBalance,
                'payment_type' => 'win',
                'level' => $receiver->level,
                'status' => 2,
            ]);

            [$senderEntry, $receiverEntry] = $this->ledgerService->recordEscrowTransfer(
                $senderTransaction,
                $sender,
                $receiver,
                (float) $totalBalance
            );

            $sender->level -= 1;
            $sender->save();

            $receiver->level += 1;
            $receiver->save();

            $senderTransaction->update([
                'competition_wallet_balance_before' => $senderEntry->balance_before,
                'competition_wallet_balance_after' => $senderEntry->balance_after,
            ]);

            $receiverTransaction->update([
                'competition_wallet_balance_before' => $receiverEntry->balance_before,
                'competition_wallet_balance_after' => $receiverEntry->balance_after,
            ]);
        });

        return ['status' => 'success'];
    }

    /**
     * Both wallets open and not frozen by a complaint, and the sender holding money to move.
     *
     * @throws \InvalidArgumentException
     */
    private function assertTransferable(CompetitionWallet $sender, CompetitionWallet $receiver): void
    {
        if ($sender->status !== 1) {
            throw new \InvalidArgumentException('Sender Competition Wallet is not open');
        }
        if ($receiver->status !== 1) {
            throw new \InvalidArgumentException('Receiver Competition Wallet is not open');
        }
        if ($sender->isUnderDispute() || $receiver->isUnderDispute()) {
            throw new \InvalidArgumentException('Competition Wallet is frozen by a pending complaint');
        }
        if ($sender->balance <= 0) {
            throw new \InvalidArgumentException('Insufficient balance in Sender Competition Wallet');
        }
    }
}
