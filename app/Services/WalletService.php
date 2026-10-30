<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public const DEFAULT_ADJUSTMENT_REASON = 'unspecified';

    public function __construct(
        private LedgerService $ledgerService,
        private SignupBonusService $signupBonus,
        private PlayerGate $gate,
    ) {}

    public function listWallets(): Collection
    {
        return Wallet::with('customer')->get();
    }

    public function getWallet(int $id): ?Wallet
    {
        return Wallet::find($id);
    }

    /**
     * @param  array<string, mixed>  $data  A `balance` value is applied as a ledgered adjustment, not a raw overwrite.
     */
    public function updateWallet(int $id, array $data, ?string $reason = null, ?string $actor = null): ?Wallet
    {
        return DB::transaction(function () use ($id, $data, $reason, $actor) {
            $wallet = Wallet::lockForUpdate()->find($id);
            if (! $wallet) {
                return null;
            }

            if (array_key_exists('balance', $data)) {
                $this->ledgerService->recordAdjustment(
                    $wallet,
                    (float) $data['balance'] - (float) $wallet->balance,
                    $reason ?? self::DEFAULT_ADJUSTMENT_REASON,
                    $actor,
                    ['operation' => 'update_wallet']
                );
                unset($data['balance']);
            }

            $wallet->update($data);

            return $wallet;
        });
    }

    public function reduceBalance(int $walletId, float $amount, ?string $reason = null, ?string $actor = null): array
    {
        return DB::transaction(function () use ($walletId, $amount, $reason, $actor) {
            $wallet = Wallet::lockForUpdate()->find($walletId);
            if (! $wallet) {
                return ['success' => false, 'message' => 'Wallet not found'];
            }
            if ($wallet->balance < $amount) {
                return ['success' => false, 'message' => 'Insufficient balance'];
            }

            $balanceBefore = $wallet->balance;
            $this->ledgerService->recordAdjustment($wallet, -$amount, $reason ?? self::DEFAULT_ADJUSTMENT_REASON, $actor, ['operation' => 'reduce_balance']);

            return [
                'success' => true,
                'balance_before' => $balanceBefore,
                'balance_after' => $wallet->balance,
            ];
        });
    }

    public function addBalance(int $walletId, float $amount, ?string $reason = null, ?string $actor = null): array
    {
        return DB::transaction(function () use ($walletId, $amount, $reason, $actor) {
            $wallet = Wallet::lockForUpdate()->find($walletId);
            if (! $wallet) {
                return ['success' => false, 'message' => 'Wallet not found'];
            }

            $balanceBefore = $wallet->balance;
            $this->ledgerService->recordAdjustment($wallet, $amount, $reason ?? self::DEFAULT_ADJUSTMENT_REASON, $actor, ['operation' => 'add_balance']);

            return [
                'success' => true,
                'balance_before' => $balanceBefore,
                'balance_after' => $wallet->balance,
            ];
        });
    }

    public function setBalance(int $walletId, float $amount, ?string $reason = null, ?string $actor = null): array
    {
        return DB::transaction(function () use ($walletId, $amount, $reason, $actor) {
            $wallet = Wallet::lockForUpdate()->find($walletId);
            if (! $wallet) {
                return ['success' => false, 'message' => 'Wallet not found'];
            }

            $balanceBefore = $wallet->balance;
            $this->ledgerService->recordAdjustment(
                $wallet,
                $amount - (float) $balanceBefore,
                $reason ?? self::DEFAULT_ADJUSTMENT_REASON,
                $actor,
                ['operation' => 'set_balance']
            );

            return [
                'success' => true,
                'balance_before' => $balanceBefore,
                'balance_after' => $wallet->balance,
            ];
        });
    }

    public function transfer(int $senderWalletId, float $amount, ?int $receiverWalletId = null): array
    {
        if (! $receiverWalletId) {
            return ['success' => false, 'message' => 'Receiver wallet is required', 'status_code' => 400];
        }

        $wallet = Wallet::lockForUpdate()->find($senderWalletId);
        if (! $wallet) {
            return ['success' => false, 'message' => 'Wallet not found', 'status_code' => 404];
        }

        // Moving money out of a wallet into play is a stake: only an active player who is not on a break.
        $sender = $wallet->customer()->withTrashed()->first();
        if ($sender) {
            $canPlay = $this->gate->canPlay($sender);
            if (! $canPlay->allowed) {
                return ['success' => false, 'message' => $canPlay->message, 'status_code' => $canPlay->statusCode, 'code' => $canPlay->code];
            }
        }

        if ($wallet->balance < $amount) {
            return ['success' => false, 'message' => 'Insufficient balance', 'status_code' => 400];
        }

        if ($this->signupBonus->wouldSpendLockedBonus($wallet, (float) $amount)) {
            return $this->signupBonus->lockedRefusal($wallet);
        }

        $receiverWallet = Wallet::lockForUpdate()->find($receiverWalletId);
        if (! $receiverWallet) {
            return ['success' => false, 'message' => 'Receiver wallet not found', 'status_code' => 404];
        }

        DB::transaction(function () use ($wallet, $receiverWallet, $amount) {
            $walletTransaction = WalletTransaction::create([
                'transaction_type' => 'w2w',
                'sender_id' => $wallet->id,
                'receiver_id' => $receiverWallet->id,
                'initiator_id' => 1,
                'amount' => $amount,
            ]);

            [$senderEntry, $receiverEntry] = $this->ledgerService->recordWalletTransfer(
                $walletTransaction,
                $wallet,
                $receiverWallet,
                (float) $amount
            );

            $walletTransaction->update([
                'status' => 2,
                'sender_balance_before' => $senderEntry->balance_before,
                'sender_balance_after' => $senderEntry->balance_after,
                'receiver_balance_before' => $receiverEntry->balance_before,
                'receiver_balance_after' => $receiverEntry->balance_after,
            ]);
        });

        return ['success' => true, 'status_code' => 200];
    }
}
