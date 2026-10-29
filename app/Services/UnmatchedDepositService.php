<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositResolution;
use App\Util\PlayerNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resolves deposits whose account number matched no customer (status 0). A deposit is either assigned to a
 * customer, which credits their wallet exactly like a plain C2B deposit, or recorded as refunded to the
 * payer (the reversal itself is done on the M-Pesa portal). The M-Pesa record (bill ref, msisdn, amount)
 * is never changed; each resolution is kept in `deposit_resolutions`.
 */
class UnmatchedDepositService
{
    public const COMMAND_ACTOR = 'command:deposits:match-unmatched';

    /** Machine-readable reasons for a 409, so callers do not depend on message wording. */
    public const CODE_ALREADY_RESOLVED = 'already_resolved';

    public const CODE_REFERENCE_USED = 'reference_used';

    public const CODE_HOUSE_CUSTOMER = 'house_customer';

    private const REFERENCE_USED_MESSAGE = 'That M-Pesa reference is already on another refund';

    /** Suggestion kinds, strongest first. */
    public const MATCH_KINDS = ['account_no', 'player_no', 'payer_phone', 'bill_ref_phone'];

    public function __construct(
        private WalletDepositService $walletDeposits,
        private HouseFundingService $houseFunding,
        private DepositAccountResolver $resolver,
    ) {}

    /**
     * Customers an unmatched deposit probably belongs to, strongest match first:
     *
     * account_no      the bill ref (normalised as C2B does) is the customer's account number
     * player_no       the bill ref is the customer's player number (valid check digit)
     * payer_phone     the number M-Pesa says paid (msisdn, sent as a SHA-256 hash) is the customer's phone
     * bill_ref_phone  the bill ref, read as a phone number, is the customer's phone
     *
     * `matches` lists every kind a customer matched; `ambiguous` is true when the matched phone number
     * belongs to more than one customer. Phone suggestions ignore verification, so these are hints:
     * a person always decides.
     *
     * @return list<array{customer_id: int, name: string, account_no: ?string, phone_no: ?string, match: string, matches: list<string>, ambiguous: bool}>
     */
    public function suggestionsFor(Deposit $deposit): array
    {
        /** @var array<int, array{customer: Customer, matches: list<string>, ambiguous: bool}> $found */
        $found = [];
        $add = function ($customers, string $kind) use (&$found) {
            $ambiguous = $customers->count() > 1;

            foreach ($customers as $customer) {
                $found[$customer->id] ??= ['customer' => $customer, 'matches' => [], 'ambiguous' => false];
                $found[$customer->id]['matches'][] = $kind;
                $found[$customer->id]['ambiguous'] = $found[$customer->id]['ambiguous'] || $ambiguous;
            }
        };

        $accountNo = $this->walletDeposits->accountNoFromBillRef($deposit->bill_ref_no);

        if ($accountNo !== '') {
            $add(Customer::where('account_no', $accountNo)->limit(1)->get(), 'account_no');
        }

        $playerNo = PlayerNumber::normalize(explode('#', (string) $deposit->bill_ref_no)[0]);

        if ($playerNo !== null) {
            $add(Customer::where('player_no', $playerNo)->limit(1)->get(), 'player_no');
        }

        $payerHash = $this->resolver->payerPhoneHash($deposit->msisdn);

        if ($payerHash !== null) {
            $add(Customer::where('phone_hash', $payerHash)->limit(5)->get(), 'payer_phone');
        }

        $billRefHash = Customer::phoneHash(explode('#', (string) $deposit->bill_ref_no)[0]);

        if ($billRefHash !== null) {
            $add(Customer::where('phone_hash', $billRefHash)->limit(5)->get(), 'bill_ref_phone');
        }

        $strength = array_flip(self::MATCH_KINDS);

        return collect($found)
            ->map(fn (array $row) => $this->suggestion($row['customer'], $row['matches'], $row['ambiguous']))
            ->sortBy([
                fn (array $a, array $b) => $strength[$a['match']] <=> $strength[$b['match']],
                fn (array $a, array $b) => count($b['matches']) <=> count($a['matches']),
            ])
            ->values()
            ->all();
    }

    /**
     * Credit an unmatched deposit to a customer's wallet: ledger `deposit`, excise duty, transaction row,
     * referral first-deposit hook, all as for a C2B payment.
     *
     * @return array{success: bool, message: string, status_code: int, deposit?: Deposit, code?: string}
     */
    public function assign(int $depositId, int $customerId, string $note, ?string $actor): array
    {
        return DB::transaction(function () use ($depositId, $customerId, $note, $actor) {
            $deposit = Deposit::lockForUpdate()->find($depositId);

            if (! $deposit) {
                return $this->refused('Deposit not found', 404);
            }

            if ((int) $deposit->status !== Deposit::STATUS_UNMATCHED) {
                return $this->refused('Only unmatched deposits can be assigned', 409, $deposit, self::CODE_ALREADY_RESOLVED);
            }

            $customer = Customer::find($customerId);

            if (! $customer) {
                return $this->refused('Customer not found', 404);
            }

            if ((int) $customer->id === $this->houseFunding->houseCustomerId()) {
                return $this->refused('The house wallet is funded with the house-funding action, not assigned as a deposit', 422, $deposit, self::CODE_HOUSE_CUSTOMER);
            }

            $ledgerEntry = $this->walletDeposits->credit($deposit, $customer);

            $deposit->update(['status' => Deposit::STATUS_COMPLETED]);

            DepositResolution::create([
                'deposit_id' => $deposit->id,
                'action' => DepositResolution::ACTION_ASSIGNED,
                'customer_id' => $customer->id,
                'ledger_entry_id' => $ledgerEntry->id,
                'account_no_used' => $customer->account_no,
                'note' => $note,
                'resolved_by' => $actor,
            ]);

            Log::channel('mpesa')->info('Unmatched deposit assigned', ['deposit_id' => $deposit->id, 'customer_id' => $customer->id, 'by' => $actor]);

            return ['success' => true, 'message' => 'Deposit credited to the customer', 'status_code' => 200, 'deposit' => $deposit->fresh('resolution')];
        });
    }

    /**
     * Record that an unmatched deposit was sent back to the payer on the M-Pesa portal. No wallet moves.
     *
     * A concurrent refund that slips past the reference check hits the unique index and gets the same 409.
     *
     * @return array{success: bool, message: string, status_code: int, deposit?: Deposit, code?: string, errors?: array<string, list<string>>}
     */
    public function refund(int $depositId, string $mpesaReference, string $note, ?string $actor): array
    {
        try {
            return $this->recordRefund($depositId, $mpesaReference, $note, $actor);
        } catch (UniqueConstraintViolationException) {
            return $this->referenceUsed(Deposit::find($depositId));
        }
    }

    /**
     * @return array{success: bool, message: string, status_code: int, deposit?: Deposit, code?: string, errors?: array<string, list<string>>}
     */
    private function recordRefund(int $depositId, string $mpesaReference, string $note, ?string $actor): array
    {
        return DB::transaction(function () use ($depositId, $mpesaReference, $note, $actor) {
            $deposit = Deposit::lockForUpdate()->find($depositId);

            if (! $deposit) {
                return $this->refused('Deposit not found', 404);
            }

            if ((int) $deposit->status !== Deposit::STATUS_UNMATCHED) {
                return $this->refused('Only unmatched deposits can be refunded', 409, $deposit, self::CODE_ALREADY_RESOLVED);
            }

            if (DepositResolution::where('mpesa_reference', $mpesaReference)->exists()) {
                return $this->referenceUsed($deposit);
            }

            $deposit->update(['status' => Deposit::STATUS_REFUNDED]);

            DepositResolution::create([
                'deposit_id' => $deposit->id,
                'action' => DepositResolution::ACTION_REFUNDED,
                'mpesa_reference' => $mpesaReference,
                'note' => $note,
                'resolved_by' => $actor,
            ]);

            Log::channel('mpesa')->info('Unmatched deposit refunded', ['deposit_id' => $deposit->id, 'reference' => $mpesaReference, 'by' => $actor]);

            return ['success' => true, 'message' => 'Deposit recorded as refunded', 'status_code' => 200, 'deposit' => $deposit->fresh('resolution')];
        });
    }

    /**
     * Assign every unmatched deposit whose bill ref now matches a customer by the live C2B rule
     * (DepositAccountResolver: account number, player number, or a unique verified phone), for payers who
     * paid before registering, before verifying their phone, or whose account number was corrected. Never
     * the house account (that money is recorded as house funding), and never a phone match paid from
     * another number (`phone_other_payer`: left as a suggestion). Assigned deposits get `matched_by`.
     * With $dryRun nothing is changed. $actor is recorded as resolved_by.
     *
     * @return Collection<int, array{deposit_id: int, trans_id: ?string, amount: float, bill_ref_no: ?string, customer_id: int, account_no: ?string, player_no: ?string, matched_by: string, assigned: bool, message: string}>
     */
    public function matchByBillRef(bool $dryRun, ?string $actor = self::COMMAND_ACTOR): Collection
    {
        $results = collect();
        $houseCustomerId = $this->houseFunding->houseCustomerId();

        Deposit::where('status', Deposit::STATUS_UNMATCHED)
            ->orderBy('id')
            ->chunkById(200, function ($deposits) use ($dryRun, $actor, $results, $houseCustomerId) {
                foreach ($deposits as $deposit) {
                    $match = $this->resolver->resolve($deposit->bill_ref_no, $deposit->msisdn);
                    $customer = $match->customer;

                    // An old payment from someone else's number may predate who holds that phone now: a person decides.
                    if (! $customer || (int) $customer->id === $houseCustomerId || $match->matchedBy === DepositAccountMatch::MATCHED_BY_PHONE_OTHER_PAYER) {
                        continue;
                    }

                    $reference = trim(explode('#', (string) $deposit->bill_ref_no)[0]);
                    $outcome = $dryRun
                        ? ['success' => true, 'message' => 'Would assign']
                        : $this->assign($deposit->id, $customer->id, "Matched by {$match->matchedBy} {$reference}", $actor);

                    if (! $dryRun && $outcome['success']) {
                        $deposit->forceFill(['matched_by' => $match->matchedBy])->save();
                    }

                    $results->push([
                        'deposit_id' => $deposit->id,
                        'trans_id' => $deposit->trans_id,
                        'amount' => (float) $deposit->trans_amount,
                        'bill_ref_no' => $deposit->bill_ref_no,
                        'customer_id' => $customer->id,
                        'account_no' => $customer->account_no,
                        'matched_by' => $match->matchedBy,
                        'player_no' => $customer->player_no,
                        'assigned' => ! $dryRun && $outcome['success'],
                        'message' => $outcome['message'],
                    ]);
                }
            });

        return $results;
    }

    /**
     * @param  list<string>  $matches
     * @return array{customer_id: int, name: string, account_no: ?string, phone_no: ?string, match: string, matches: list<string>, ambiguous: bool}
     */
    private function suggestion(Customer $customer, array $matches, bool $ambiguous): array
    {
        $matches = array_values(array_intersect(self::MATCH_KINDS, $matches));

        return [
            'customer_id' => $customer->id,
            'name' => $customer->name,
            'account_no' => $customer->account_no,
            'player_no' => $customer->player_no,
            'phone_no' => app(FinanceMasker::class)->phone($customer->phone_no),
            'match' => $matches[0],
            'matches' => $matches,
            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * @param  array<string, list<string>>|null  $errors
     * @return array{success: bool, message: string, status_code: int, deposit?: Deposit, code?: string, errors?: array<string, list<string>>}
     */
    private function refused(string $message, int $statusCode, ?Deposit $deposit = null, ?string $code = null, ?array $errors = null): array
    {
        return array_filter([
            'success' => false,
            'message' => $message,
            'status_code' => $statusCode,
            'deposit' => $deposit,
            'code' => $code,
            'errors' => $errors,
        ], fn ($value) => $value !== null);
    }

    /**
     * The 409 for a reference already on another refund, with a field error shaped like a 422's.
     *
     * @return array{success: bool, message: string, status_code: int, deposit?: Deposit, code: string, errors: array<string, list<string>>}
     */
    private function referenceUsed(?Deposit $deposit): array
    {
        return $this->refused(self::REFERENCE_USED_MESSAGE, 409, $deposit, self::CODE_REFERENCE_USED, ['mpesa_reference' => [self::REFERENCE_USED_MESSAGE]]);
    }
}
