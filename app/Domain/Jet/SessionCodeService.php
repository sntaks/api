<?php

namespace App\Domain\Jet;

use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-time launch codes: random, single use, valid config('jet.session_code_ttl_seconds'), stored as a hash.
 * The player token issued at launch waits encrypted behind the code and is wiped from the row once claimed, so
 * it only ever leaves the server through the session exchange.
 */
class SessionCodeService
{
    public function issue(Customer $customer, string $playerToken, CarbonImmutable $tokenExpiresAt): string
    {
        $code = Str::random(40);

        DB::table('jet_session_codes')->insert([
            'code_hash' => hash('sha256', $code),
            'customer_id' => $customer->id,
            'token_ciphertext' => Crypt::encryptString($playerToken),
            'token_expires_at' => $tokenExpiresAt,
            'expires_at' => now()->addSeconds((int) config('jet.session_code_ttl_seconds')),
            'created_at' => now(),
        ]);

        return $code;
    }

    /**
     * Claims the code with one conditional update, so of two racing claims exactly one wins.
     *
     * @return array{token: string, expires_at: CarbonImmutable, customer_id: int}|null
     */
    public function redeem(string $code): ?array
    {
        $hash = hash('sha256', $code);

        return DB::transaction(function () use ($hash) {
            $claimed = DB::table('jet_session_codes')->where('code_hash', $hash)->whereNull('used_at')
                ->where('expires_at', '>', now())->update(['used_at' => now()]);

            if ($claimed !== 1) {
                return null;
            }

            $row = DB::table('jet_session_codes')->where('code_hash', $hash)->first();
            DB::table('jet_session_codes')->where('id', $row->id)->update(['token_ciphertext' => null]);

            return [
                'token' => Crypt::decryptString($row->token_ciphertext),
                'expires_at' => CarbonImmutable::parse($row->token_expires_at),
                'customer_id' => (int) $row->customer_id,
            ];
        });
    }
}
