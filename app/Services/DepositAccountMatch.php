<?php

namespace App\Services;

use App\Models\Customer;

/**
 * Which customer a deposit's bill ref names, and how: by account number, player number or phone
 * (`phone_other_payer` when someone else's number paid). With no customer, `unmatchedReason` says why.
 */
final readonly class DepositAccountMatch
{
    public const MATCHED_BY_ACCOUNT_NO = 'account_no';

    public const MATCHED_BY_PLAYER_NO = 'player_no';

    public const MATCHED_BY_PHONE = 'phone';

    public const MATCHED_BY_PHONE_OTHER_PAYER = 'phone_other_payer';

    public const REASON_UNKNOWN = 'unknown';

    public const REASON_PHONE_AMBIGUOUS = 'phone_ambiguous';

    public const REASON_PHONE_UNVERIFIED = 'phone_unverified';

    public function __construct(
        public ?Customer $customer,
        public ?string $matchedBy = null,
        public ?string $unmatchedReason = null,
    ) {}

    public static function matched(Customer $customer, string $matchedBy): self
    {
        return new self($customer, $matchedBy);
    }

    public static function unmatched(string $reason): self
    {
        return new self(null, unmatchedReason: $reason);
    }
}
