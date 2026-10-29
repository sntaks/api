<?php

namespace App\Services;

use App\Models\Customer;
use App\Util\PlayerNumber;

/**
 * The one rule for which customer a deposit belongs to, read from the bill ref (the part before the
 * first `#`). First match wins:
 *
 * account_no  the account number, with C2B's typing-slip and legacy phone-account normalisation
 * player_no   an 8-digit player number with a valid check digit (spaces and dashes allowed), unless
 *             it is another customer's national ID
 * phone       a reference that is only a phone number in a Kenyan format, when exactly one customer
 *             has it and it is verified; whoever pays, but `phone_other_payer` when the paying number
 *             is not that phone
 */
class DepositAccountResolver
{
    public function __construct(private WalletDepositService $walletDeposits) {}

    public function resolve(?string $billRef, ?string $payerMsisdn): DepositAccountMatch
    {
        $reference = trim(explode('#', (string) $billRef)[0]);

        if ($reference === '') {
            return DepositAccountMatch::unmatched(DepositAccountMatch::REASON_UNKNOWN);
        }

        $customer = Customer::where('account_no', $this->walletDeposits->normalizeAccountNo($reference))->first();

        if ($customer) {
            return DepositAccountMatch::matched($customer, DepositAccountMatch::MATCHED_BY_ACCOUNT_NO);
        }

        $playerNo = PlayerNumber::normalize($reference);
        $customer = $playerNo === null ? null : Customer::where('player_no', $playerNo)->first();

        // An 8-digit national ID can pass the check digit: when it is someone else's ID, the payer meant them.
        if ($customer && Customer::where('id_no', $reference)->whereKeyNot($customer->id)->exists()) {
            return DepositAccountMatch::unmatched(DepositAccountMatch::REASON_UNKNOWN);
        }

        if ($customer) {
            return DepositAccountMatch::matched($customer, DepositAccountMatch::MATCHED_BY_PLAYER_NO);
        }

        // Only a reference that is just a phone number (digits, spaces, dashes, a leading +) is read as one,
        // so SHOP-0712345678 or "Mary 0712345678" never reach a player's wallet.
        if (! preg_match('/^\+?[\d\s-]+$/', $reference)) {
            return DepositAccountMatch::unmatched(DepositAccountMatch::REASON_UNKNOWN);
        }

        $phoneHash = Customer::phoneHash($reference);

        return $phoneHash === null
            ? DepositAccountMatch::unmatched(DepositAccountMatch::REASON_UNKNOWN)
            : $this->byPhone($phoneHash, $payerMsisdn);
    }

    /**
     * The payer's number as a phone hash. M-Pesa sends `msisdn` as SHA-256 of the 254 number; an older or
     * plain number is hashed the same way. Masked numbers (with `*`) give null.
     */
    public function payerPhoneHash(?string $msisdn): ?string
    {
        $msisdn = trim((string) $msisdn);

        if (preg_match('/^[0-9a-f]{64}$/i', $msisdn)) {
            return strtolower($msisdn);
        }

        return str_contains($msisdn, '*') ? null : Customer::phoneHash($msisdn);
    }

    private function byPhone(string $phoneHash, ?string $payerMsisdn): DepositAccountMatch
    {
        $customers = Customer::where('phone_hash', $phoneHash)->limit(2)->get();

        if ($customers->isEmpty()) {
            return DepositAccountMatch::unmatched(DepositAccountMatch::REASON_UNKNOWN);
        }

        if ($customers->count() > 1) {
            return DepositAccountMatch::unmatched(DepositAccountMatch::REASON_PHONE_AMBIGUOUS);
        }

        $customer = $customers->first();

        if ($customer->phone_no_verified_at === null) {
            return DepositAccountMatch::unmatched(DepositAccountMatch::REASON_PHONE_UNVERIFIED);
        }

        return DepositAccountMatch::matched(
            $customer,
            $this->payerPhoneHash($payerMsisdn) === $phoneHash
                ? DepositAccountMatch::MATCHED_BY_PHONE
                : DepositAccountMatch::MATCHED_BY_PHONE_OTHER_PAYER,
        );
    }
}
