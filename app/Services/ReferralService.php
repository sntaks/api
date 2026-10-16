<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\Referral;
use App\Models\ReferralBonus;
use App\Models\ReferralCode;
use App\Models\ReferralWallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Customer referrals, using the rules in config/referrals.php. A customer who signs up with another
 * customer's code becomes their referral; the referrer earns a bonus into their referral wallet when the
 * referral is verified and again on the referral's first wallet deposit (paid once verified).
 */
class ReferralService
{
    public function __construct(private LedgerService $ledgerService) {}

    public function isEnabled(): bool
    {
        return (bool) config('referrals.enabled');
    }

    /**
     * Codes are matched without regard to case and stored upper case.
     */
    public function normalizeCode(string $code): string
    {
        return Str::upper(trim($code));
    }

    public function findCode(string $code): ?ReferralCode
    {
        return ReferralCode::where('code', $this->normalizeCode($code))->first();
    }

    /**
     * Create or replace the customer's own code. Referrals already made keep pointing at the customer,
     * so changing the code only stops the old one matching new signups.
     *
     * @param  array{code: string, link?: ?string, qr_code?: ?string}  $data
     */
    public function saveCode(Customer $customer, array $data): ReferralCode
    {
        return ReferralCode::updateOrCreate(
            ['customer_id' => $customer->id],
            [
                'code' => $this->normalizeCode($data['code']),
                'link' => $data['link'] ?? null,
                'qr_code' => $data['qr_code'] ?? null,
            ]
        );
    }

    /**
     * Link a customer who just signed up to the customer whose code they used. Codes that belong to no
     * customer (agent codes), the customer's own code and signups before `effective_from` are ignored.
     */
    public function attachAtSignup(Customer $referred, ?string $code): ?Referral
    {
        if (! $this->isEnabled() || blank($code) || ! $this->isEffective($referred)) {
            return null;
        }

        $referralCode = $this->findCode($code);

        if (! $referralCode || $referralCode->customer_id === $referred->id || $this->isDeletedCustomer($referralCode->customer_id)) {
            return null;
        }

        return Referral::firstOrCreate(
            ['referred_id' => $referred->id],
            ['referrer_id' => $referralCode->customer_id, 'code_used' => $referralCode->code]
        );
    }

    /**
     * The client reports the referred customer verified their email and phone. Pays the signup bonus and
     * any first-deposit bonus that was waiting on verification. Safe to call more than once.
     */
    public function markVerified(Customer $referred): ?Referral
    {
        return DB::transaction(function () use ($referred) {
            $referral = Referral::where('referred_id', $referred->id)->lockForUpdate()->first();

            if (! $referral) {
                return null;
            }

            if ($referral->verified_at === null) {
                $referral->update(['verified_at' => now()]);
            }

            $this->payDueBonuses($referral);

            return $referral->fresh('bonuses');
        });
    }

    /**
     * Note a referred customer's first wallet deposit, and pay the bonus when they are already verified.
     * Call inside the caller's deposit transaction.
     */
    public function recordDeposit(Deposit $deposit, int $customerId): void
    {
        $referral = Referral::where('referred_id', $customerId)->lockForUpdate()->first();

        if (! $referral) {
            return;
        }

        if ($referral->first_deposit_id === null) {
            $referral->update(['first_deposit_id' => $deposit->id, 'first_deposited_at' => now()]);
        }

        $this->payDueBonuses($referral);
    }

    /**
     * Pay every milestone the referral has reached and not been paid for. Nothing is paid before the
     * referral is verified, or while referrals are switched off.
     *
     * @return list<ReferralBonus>
     */
    private function payDueBonuses(Referral $referral): array
    {
        if (! $this->isEnabled() || $referral->verified_at === null || $this->isDeletedCustomer($referral->referrer_id)) {
            return [];
        }

        $due = [ReferralBonus::MILESTONE_SIGNUP];
        if ($referral->first_deposit_id !== null) {
            $due[] = ReferralBonus::MILESTONE_FIRST_DEPOSIT;
        }

        $paid = $referral->bonuses()->pluck('milestone')->all();
        $bonuses = [];

        foreach (array_diff($due, $paid) as $milestone) {
            $amount = round((float) config("referrals.bonuses.{$milestone}"), 2);

            if ($amount > 0) {
                $bonuses[] = $this->payBonus($referral, $milestone, $amount);
            }
        }

        return $bonuses;
    }

    /**
     * A deleted customer's code stops matching signups, and they earn no further bonuses, so no money
     * builds up in the referral wallet of someone who can no longer withdraw it.
     */
    private function isDeletedCustomer(?int $customerId): bool
    {
        return $customerId !== null && Customer::onlyTrashed()->whereKey($customerId)->exists();
    }

    private function payBonus(Referral $referral, string $milestone, float $amount): ReferralBonus
    {
        $wallet = ReferralWallet::firstOrCreate(['customer_id' => $referral->referrer_id], ['balance' => 0]);
        $wallet = ReferralWallet::lockForUpdate()->find($wallet->id);

        $entry = $this->ledgerService->recordReferralBonus($referral, $wallet, $amount, $milestone);

        return ReferralBonus::create([
            'referral_id' => $referral->id,
            'customer_id' => $referral->referrer_id,
            'referred_id' => $referral->referred_id,
            'referral_wallet_id' => $wallet->id,
            'milestone' => $milestone,
            'amount' => $amount,
            'ledger_entry_id' => $entry->id,
        ]);
    }

    /**
     * One referrer's figures: referral counts by stage, what they earned and their wallet balance.
     *
     * @return array{code: ?string, referrals: array{total: int, verified: int, deposited: int, pending_verification: int, today: int, this_week: int, this_month: int}, earned: array{total: float, signup: float, first_deposit: float, this_month: float}, wallet_balance: float}
     */
    public function statsFor(Customer $referrer): array
    {
        $referrals = Referral::where('referrer_id', $referrer->id);
        $now = Carbon::now();

        $earned = ReferralBonus::where('customer_id', $referrer->id)
            ->selectRaw('milestone, SUM(amount) as amount')
            ->groupBy('milestone')
            ->pluck('amount', 'milestone');

        return [
            'code' => $referrer->referralCode?->code,
            'referrals' => [
                'total' => (clone $referrals)->count(),
                'verified' => (clone $referrals)->whereNotNull('verified_at')->count(),
                'deposited' => (clone $referrals)->whereNotNull('first_deposit_id')->count(),
                'pending_verification' => (clone $referrals)->whereNull('verified_at')->count(),
                'today' => (clone $referrals)->where('created_at', '>=', $now->copy()->startOfDay())->count(),
                'this_week' => (clone $referrals)->where('created_at', '>=', $now->copy()->startOfWeek())->count(),
                'this_month' => (clone $referrals)->where('created_at', '>=', $now->copy()->startOfMonth())->count(),
            ],
            'earned' => [
                'total' => round((float) $earned->sum(), 2),
                'signup' => round((float) ($earned[ReferralBonus::MILESTONE_SIGNUP] ?? 0), 2),
                'first_deposit' => round((float) ($earned[ReferralBonus::MILESTONE_FIRST_DEPOSIT] ?? 0), 2),
                'this_month' => round((float) ReferralBonus::where('customer_id', $referrer->id)
                    ->where('created_at', '>=', $now->copy()->startOfMonth())
                    ->sum('amount'), 2),
            ],
            'wallet_balance' => round((float) ($referrer->referralWallet?->balance ?? 0), 2),
        ];
    }

    /**
     * Programme-wide figures and the top referrers by amount earned.
     *
     * @return array{referrals: array{total: int, verified: int, deposited: int, today: int, this_week: int, this_month: int}, bonuses: array{total: float, signup: float, first_deposit: float, this_month: float}, unspent_balance: float, referrers: int, top_referrers: list<array{customer_id: int, name: ?string, referrals: int, earned: float}>}
     */
    public function programStats(int $top = 10): array
    {
        $now = Carbon::now();

        $bonuses = ReferralBonus::selectRaw('milestone, SUM(amount) as amount')->groupBy('milestone')->pluck('amount', 'milestone');

        $topReferrers = ReferralBonus::query()
            ->selectRaw('customer_id, SUM(amount) as earned, COUNT(DISTINCT referral_id) as referrals')
            ->groupBy('customer_id')
            ->orderByDesc('earned')
            ->limit($top)
            ->with('customer:id,name')
            ->get()
            ->map(fn (ReferralBonus $row) => [
                'customer_id' => (int) $row->customer_id,
                'name' => $row->customer?->name,
                'referrals' => (int) $row->referrals,
                'earned' => round((float) $row->earned, 2),
            ])
            ->all();

        return [
            'referrals' => [
                'total' => Referral::count(),
                'verified' => Referral::whereNotNull('verified_at')->count(),
                'deposited' => Referral::whereNotNull('first_deposit_id')->count(),
                'today' => Referral::where('created_at', '>=', $now->copy()->startOfDay())->count(),
                'this_week' => Referral::where('created_at', '>=', $now->copy()->startOfWeek())->count(),
                'this_month' => Referral::where('created_at', '>=', $now->copy()->startOfMonth())->count(),
            ],
            'bonuses' => [
                'total' => round((float) $bonuses->sum(), 2),
                'signup' => round((float) ($bonuses[ReferralBonus::MILESTONE_SIGNUP] ?? 0), 2),
                'first_deposit' => round((float) ($bonuses[ReferralBonus::MILESTONE_FIRST_DEPOSIT] ?? 0), 2),
                'this_month' => round((float) ReferralBonus::where('created_at', '>=', $now->copy()->startOfMonth())->sum('amount'), 2),
            ],
            'unspent_balance' => round((float) ReferralWallet::sum('balance'), 2),
            'referrers' => Referral::distinct()->count('referrer_id'),
            'top_referrers' => $topReferrers,
        ];
    }

    private function isEffective(Customer $referred): bool
    {
        $effectiveFrom = config('referrals.effective_from');

        return blank($effectiveFrom)
            || Carbon::parse($referred->created_at ?? now())->gte(Carbon::parse($effectiveFrom, config('app.timezone'))->startOfDay());
    }
}
