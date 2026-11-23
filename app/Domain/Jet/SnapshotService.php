<?php

namespace App\Domain\Jet;

use App\Models\Customer;
use App\Models\JetBet;
use App\Models\JetRound;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * The M1 snapshot (schema 1), read inside the Jet fence so every watermark matches the rows it describes.
 * The crash point is shown only once crashed, seeds and proof only once settled.
 */
class SnapshotService
{
    /**
     * @return array<string, mixed>
     */
    public function read(?Customer $customer = null): array
    {
        return JetLock::run(function () use ($customer) {
            $round = JetRound::latest('id')->first();
            $data = [
                'schema_version' => 1,
                'server_now' => now()->toISOString(),
                'public_revision' => (int) DB::table('jet_contract_state')->where('id', 1)->value('revision'),
                'round' => $round ? $this->round($round) : null,
            ];

            if ($customer) {
                $wallet = Wallet::where('customer_id', $customer->id)->first();
                $data['private_revision'] = (int) (DB::table('jet_private_streams')->where('customer_id', $customer->id)->value('revision') ?? 0);
                $data['active_bets'] = JetBet::where('customer_id', $customer->id)->where('status', JetBet::STATUS_ACTIVE)
                    ->with('round')->orderBy('id')->get()->map(fn (JetBet $bet) => $this->bet($bet))->all();
                $data['balance'] = [
                    'amount' => $this->money($wallet->balance ?? 0),
                    'currency' => config('jet.currency'),
                    'revision' => (int) ($wallet->wallet_version ?? 0),
                ];
            }

            return $data;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function round(JetRound $round): array
    {
        $settled = $round->status === JetRound::STATUS_SETTLED;

        return [
            'round_number' => $round->round_number,
            // A round being voided is already out of play: the public only sees that it is voided
            'status' => $round->status === JetRound::STATUS_VOIDING ? JetRound::STATUS_VOIDED : $round->status,
            'revision' => $round->revision,
            'server_seed_hash' => $round->server_seed_hash,
            'betting_closes_at' => $round->betting_closes_at?->toIso8601String(),
            'started_at' => $round->started_at?->toIso8601String(),
            'acceleration_k' => (string) $round->acceleration_k,
            'crash_multiplier' => $round->crashRevealed() ? (string) $round->crash_multiplier : null,
            'crashed_at' => $round->crashed_at?->toIso8601String(),
            'server_seed' => $settled ? $round->server_seed : null,
            'client_seed' => $settled ? $round->client_seed : null,
            'nonce' => $settled ? $round->nonce : null,
            'proof' => $settled ? [
                'algorithm' => ProvablyFairService::ALGORITHM,
                'house_edge' => (string) $round->house_edge,
                'max_multiplier_cap' => (string) $round->max_multiplier_cap,
            ] : null,
        ];
    }

    /**
     * A player's own bet. payout is what reached the wallet (after winnings tax); gross_payout and tax explain it.
     *
     * @return array<string, mixed>
     */
    public function bet(JetBet $bet): array
    {
        return [
            'id' => $bet->id,
            'bet_reference' => $bet->wager_reference,
            'round_number' => $bet->round->round_number,
            'revision' => $bet->revision,
            'stake' => (string) $bet->stake,
            'auto_cashout' => $bet->auto_cashout,
            'cap_multiplier' => (string) $bet->cap_multiplier,
            'cashout_multiplier' => $bet->cashout_multiplier,
            'payout' => $bet->net_payout,
            'gross_payout' => $bet->payout,
            'tax' => $bet->tax,
            // M1 clients know a voided bet as cancelled
            'status' => $bet->status === JetBet::STATUS_VOID ? 'cancelled' : $bet->status,
            'placed_at' => $bet->placed_at?->toISOString(),
            'cashed_out_at' => $bet->cashed_out_at?->toISOString(),
        ];
    }

    private function money(float|int|string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
