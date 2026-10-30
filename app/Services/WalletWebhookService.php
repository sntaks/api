<?php

namespace App\Services;

use App\Exceptions\WalletWebhookFatalException;
use App\Exceptions\WalletWebhookRetryableException;
use App\Jobs\SendWalletWebhookJob;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WalletWebhookService
{
    /**
     * @var array<string, string>
     */
    private const REASON_MAP = [
        'deposit' => 'deposit',
        'excise_duty' => 'deposit',
        'excise_duty_reversal' => 'deposit',
        'withdrawal' => 'withdrawal',
        'withdrawal_reversal' => 'withdrawal_reversal',
        'game_bet' => 'game',
        'game_payout' => 'game',
        'competition_bet' => 'game',
        'competition_payout' => 'game',
        'adjustment' => 'adjustment',
        'wager_stake' => 'game',
        'wager_stake_reversal' => 'game',
        'wager_stake_settled' => 'game',
        'wager_stake_settled_reversal' => 'game',
        'wager_payout' => 'game',
        'wager_payout_reversal' => 'game',
        'wager_free_bet_payout' => 'game',
        'wager_free_bet_payout_reversal' => 'game',
        'wager_bonus' => 'game',
        'wager_bonus_reversal' => 'game',
        'wager_refund' => 'game',
        'wager_refund_reversal' => 'game',
        'winnings_tax' => 'game',
        'winnings_tax_reversal' => 'game',
    ];

    /**
     * URL and secret are both present, regardless of the feature flag.
     * Used by the smoke-test command, which should work even while the
     * feature is deployed dark.
     */
    public function isConfigured(): bool
    {
        return filled(config('wallet-webhook.url')) && filled(config('wallet-webhook.secret'));
    }

    /**
     * The feature flag is on AND the endpoint is configured.
     */
    public function isEnabled(): bool
    {
        return (bool) config('wallet-webhook.enabled') && $this->isConfigured();
    }

    /**
     * The house wallet (config wallets.house_wallet_id) is not a player wallet, so the site
     * never needs its balance.
     */
    public function isHouseWallet(int $walletId): bool
    {
        return $walletId === (int) config('wallets.house_wallet_id', 1);
    }

    /**
     * Schedule a debounced webhook send for a wallet whose balance just changed.
     * If a send is already scheduled within the debounce window, this is a no-op —
     * that pending job will read the latest state when it executes. The house
     * wallet is skipped.
     */
    public function scheduleForWallet(int $walletId, int $customerId): void
    {
        if (! $this->isEnabled() || $this->isHouseWallet($walletId)) {
            return;
        }

        $lockKey = "wallet-webhook:debounce:{$walletId}";
        $window = (int) config('wallet-webhook.debounce_seconds', 2);

        if (Cache::add($lockKey, true, now()->addSeconds($window + 1))) {
            SendWalletWebhookJob::dispatch($walletId, $customerId, (string) Str::uuid())
                ->delay(now()->addSeconds($window));
        }
    }

    /**
     * Build the current wallet.updated payload for a wallet. Returns null if the
     * wallet no longer exists (e.g. deleted between scheduling and execution).
     *
     * @return array{customer_id: int, balance: float, balance_version: int, reason: string, occurred_at: string}|null
     */
    public function buildSnapshot(int $walletId, int $customerId): ?array
    {
        $wallet = Wallet::find($walletId);

        if (! $wallet) {
            return null;
        }

        return [
            'customer_id' => $customerId,
            'balance' => (float) $wallet->balance,
            'balance_version' => (int) $wallet->wallet_version,
            'reason' => $this->resolveReason($wallet),
            'occurred_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Sign and send one wallet.updated event.
     *
     * @param  array{customer_id: int, balance: float, balance_version: int, reason: string, occurred_at: string}  $snapshot
     *
     * @throws WalletWebhookRetryableException
     * @throws WalletWebhookFatalException
     */
    public function send(string $eventId, array $snapshot): void
    {
        $body = [
            'event' => 'wallet.updated',
            'event_id' => $eventId,
            'customer_id' => $snapshot['customer_id'],
            'balance' => $snapshot['balance'],
            'balance_version' => $snapshot['balance_version'],
            'reason' => $snapshot['reason'],
            'occurred_at' => $snapshot['occurred_at'],
        ];

        // Serialise once and reuse the exact same bytes for signing and sending,
        // per the contract. Http::post($url, $array) would let the client
        // re-encode the body, risking a mismatch with what was signed.
        $rawJson = json_encode($body, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$rawJson, (string) config('wallet-webhook.secret'));

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Kadi-Event-Id' => $eventId,
                'X-Kadi-Timestamp' => $timestamp,
                'X-Kadi-Signature' => $signature,
            ])
                ->timeout((int) config('wallet-webhook.http_timeout', 5))
                ->connectTimeout((int) config('wallet-webhook.http_connect_timeout', 3))
                ->withBody($rawJson, 'application/json')
                ->post((string) config('wallet-webhook.url'));
        } catch (ConnectionException $e) {
            throw new WalletWebhookRetryableException(previous: $e);
        }

        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $responseBody = Str::limit($response->body(), 500);

        if ($status === 429 || $status >= 500) {
            throw new WalletWebhookRetryableException($status, $responseBody);
        }

        throw new WalletWebhookFatalException($status, $responseBody);
    }

    private function resolveReason(Wallet $wallet): string
    {
        $entryType = LedgerEntry::where('wallet_type', LedgerEntry::WALLET_TYPE_WALLET)
            ->where('wallet_id', $wallet->id)
            ->latest('id')
            ->value('entry_type');

        return self::REASON_MAP[$entryType] ?? 'other';
    }
}
