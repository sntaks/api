<?php

namespace App\Services;

use App\Models\BetShop;
use App\Models\BetShopWallet;
use App\Models\Customer;
use App\Models\ShopWithdrawal;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * Cash withdrawals at a bet shop. Only the player can ask for one (from the client, logged in): the amount
 * leaves their wallet at once and they get a one-time pickup code that only they see. At the counter they
 * give the code; the shop enters it and pays the cash from its till, and the amount moves to the shop's
 * float. An unclaimed request expires and the money goes back; so does a cancelled or rejected one, and one
 * that gets too many wrong codes (so a shop cannot guess its way in).
 */
class ShopWithdrawalService
{
    public const CODE_SHOP_NOT_FOUND = 'shop_not_found';

    public const CODE_SHOP_NOT_ACTIVE = 'shop_not_active';

    public const CODE_PLAYER_INACTIVE = 'player_inactive';

    public const CODE_ID_REQUIRED = 'id_required';

    public const CODE_AMOUNT_OUT_OF_RANGE = 'amount_out_of_range';

    public const CODE_INSUFFICIENT_BALANCE = 'insufficient_balance';

    public const CODE_NOT_FOUND = 'withdrawal_not_found';

    public const CODE_NOT_PENDING = 'not_pending';

    public const CODE_EXPIRED = 'expired';

    public const CODE_WRONG_CODE = 'wrong_code';

    public const CODE_TOO_MANY_ATTEMPTS = 'too_many_attempts';

    /** Who an automatic expiry is recorded as. */
    public const EXPIRY_ACTOR = 'system:expiry';

    public function __construct(
        private LedgerService $ledgerService,
        private SignupBonusService $signupBonus,
        private FinanceMasker $masker,
        private PlayerGate $gate,
    ) {}

    /**
     * Ask for cash at a shop. Returns the withdrawal and its pickup code, shown this once.
     *
     * @return array{success: bool, message: string, status_code: int, code?: string, withdrawal?: ShopWithdrawal, pickup_code?: string}
     */
    public function request(Customer $customer, string $shopNo, int $amount, ?string $actor): array
    {
        $min = (int) config('bet_shops.withdrawals.min_amount');
        $max = (int) config('bet_shops.withdrawals.max_amount');

        if ($amount < $min || $amount > $max) {
            return $this->refused("A shop withdrawal must be between KES {$min} and KES {$max}", 422, self::CODE_AMOUNT_OUT_OF_RANGE);
        }

        $shop = BetShop::where('shop_no', strtoupper(trim($shopNo)))->first();

        if (! $shop) {
            return $this->refused('No bet shop has that number', 404, self::CODE_SHOP_NOT_FOUND);
        }

        if (! $shop->isActive()) {
            return $this->refused('That bet shop is not taking withdrawals', 409, self::CODE_SHOP_NOT_ACTIVE);
        }

        // Only a suspended player is stopped (D16): a closed or not-yet-active account, or a player on a break, may still take their money out.
        $canWithdraw = $this->gate->canWithdraw($customer);
        if (! $canWithdraw->allowed) {
            return $this->refused($canWithdraw->message, $canWithdraw->statusCode, $canWithdraw->code);
        }

        if (blank($customer->id_no)) {
            return $this->refused('An ID number is needed on the account to withdraw cash at a shop', 422, self::CODE_ID_REQUIRED);
        }

        return DB::transaction(function () use ($customer, $shop, $amount, $actor) {
            $wallet = Wallet::where('customer_id', $customer->id)->lockForUpdate()->first();

            if (! $wallet || round((float) $wallet->balance - $amount, 2) < 0) {
                return $this->refused('Insufficient wallet balance for this withdrawal', 409, self::CODE_INSUFFICIENT_BALANCE);
            }

            if ($this->signupBonus->wouldSpendLockedBonus($wallet, (float) $amount)) {
                return $this->signupBonus->lockedRefusal($wallet);
            }

            $pickupCode = $this->newPickupCode();

            $withdrawal = ShopWithdrawal::create([
                'withdrawal_id' => Uuid::uuid4()->toString(),
                'bet_shop_id' => $shop->id,
                'customer_id' => $customer->id,
                'wallet_id' => $wallet->id,
                'amount' => $amount,
                'balance' => 0,
                'status' => ShopWithdrawal::STATUS_PENDING,
                'pickup_code_hash' => Hash::make($pickupCode),
                'expires_at' => now()->addHours((int) config('bet_shops.withdrawals.expiry_hours')),
                'requested_by' => $actor,
            ]);

            [$walletEntry] = $this->ledgerService->recordShopWithdrawalHold($withdrawal, $wallet, (float) $amount);

            $transaction = $wallet->transactions()->create([
                'payment_id' => $withdrawal->id,
                'payment_ref' => $withdrawal->withdrawal_id,
                'payment_type' => ShopWithdrawal::class,
                'amount' => $amount,
                'status' => 1,
                'balance_before' => $walletEntry->balance_before,
                'balance_after' => $walletEntry->balance_after,
            ]);

            $withdrawal->update(['transaction_id' => $transaction->id]);

            Log::info('Shop withdrawal requested', ['withdrawal_id' => $withdrawal->withdrawal_id, 'bet_shop_id' => $shop->id, 'customer_id' => $customer->id, 'amount' => $amount, 'by' => $actor]);

            return [
                'success' => true,
                'message' => 'Show this pickup code at the shop to collect the cash. It is not shown again.',
                'status_code' => 201,
                'withdrawal' => $withdrawal->fresh('betShop'),
                'pickup_code' => $pickupCode,
            ];
        });
    }

    /**
     * The player cancels their own pending request; the money goes back to their wallet.
     *
     * @return array{success: bool, message: string, status_code: int, code?: string, withdrawal?: ShopWithdrawal}
     */
    public function cancel(Customer $customer, string $withdrawalId, ?string $actor): array
    {
        return DB::transaction(function () use ($customer, $withdrawalId, $actor) {
            $withdrawal = ShopWithdrawal::where('withdrawal_id', $withdrawalId)->where('customer_id', $customer->id)->lockForUpdate()->first();

            if (! $withdrawal) {
                return $this->refused('Withdrawal not found', 404, self::CODE_NOT_FOUND);
            }

            if (! $withdrawal->isPending()) {
                return $this->refused("The withdrawal is already {$withdrawal->status}", 409, self::CODE_NOT_PENDING) + ['withdrawal' => $withdrawal];
            }

            $this->release($withdrawal, ShopWithdrawal::STATUS_CANCELLED, 'Cancelled by the player', $actor);

            return ['success' => true, 'message' => 'Withdrawal cancelled; the money is back in the wallet', 'status_code' => 200, 'withdrawal' => $withdrawal->fresh('betShop')];
        });
    }

    /**
     * The shop pays the player against their pickup code. A wrong code counts against the request; after
     * `max_code_attempts` wrong codes it is cancelled and the money goes back to the player.
     *
     * @return array{success: bool, message: string, status_code: int, code?: string, withdrawal?: ShopWithdrawal, attempts_left?: int, float_balance?: float}
     */
    public function pay(BetShop $shop, string $withdrawalId, string $pickupCode, ?string $actor): array
    {
        return DB::transaction(function () use ($shop, $withdrawalId, $pickupCode, $actor) {
            $withdrawal = ShopWithdrawal::where('withdrawal_id', $withdrawalId)->where('bet_shop_id', $shop->id)->lockForUpdate()->first();

            if (! $withdrawal) {
                return $this->refused('Withdrawal not found', 404, self::CODE_NOT_FOUND);
            }

            if (! $withdrawal->isPending()) {
                return $this->refused("The withdrawal is already {$withdrawal->status}", 409, self::CODE_NOT_PENDING) + ['withdrawal' => $withdrawal];
            }

            if ($withdrawal->hasExpired()) {
                $this->release($withdrawal, ShopWithdrawal::STATUS_EXPIRED, 'Not collected in time', self::EXPIRY_ACTOR);

                return $this->refused('The withdrawal has expired and the money went back to the player', 409, self::CODE_EXPIRED) + ['withdrawal' => $withdrawal->fresh()];
            }

            if (! Hash::check(preg_replace('/\s+/', '', $pickupCode), $withdrawal->pickup_code_hash)) {
                $attempts = $withdrawal->failed_attempts + 1;
                $maxAttempts = (int) config('bet_shops.withdrawals.max_code_attempts');
                $withdrawal->update(['failed_attempts' => $attempts]);

                if ($attempts >= $maxAttempts) {
                    $this->release($withdrawal, ShopWithdrawal::STATUS_CANCELLED, 'Too many wrong pickup codes', $actor);

                    return $this->refused('Too many wrong codes: the withdrawal was cancelled and the money went back to the player', 409, self::CODE_TOO_MANY_ATTEMPTS) + ['withdrawal' => $withdrawal->fresh()];
                }

                return $this->refused('That pickup code is wrong', 422, self::CODE_WRONG_CODE) + ['attempts_left' => $maxAttempts - $attempts];
            }

            $float = BetShopWallet::lockForUpdate()->firstOrCreate(['bet_shop_id' => $shop->id], ['balance' => 0]);

            $this->ledgerService->recordShopCashOut($withdrawal, $float);

            $withdrawal->update([
                'status' => ShopWithdrawal::STATUS_PAID,
                'closed_at' => now(),
                'closed_by' => $actor,
            ]);

            Transaction::whereKey($withdrawal->transaction_id)->update(['status' => 2]);

            Log::info('Shop withdrawal paid', ['withdrawal_id' => $withdrawal->withdrawal_id, 'bet_shop_id' => $shop->id, 'amount' => (float) $withdrawal->amount, 'by' => $actor]);

            return [
                'success' => true,
                'message' => 'Paid. Hand the player KES '.number_format((float) $withdrawal->amount, 0, '.', ','),
                'status_code' => 200,
                'withdrawal' => $withdrawal->fresh(['betShop', 'customer']),
                'float_balance' => round((float) $float->balance, 2),
            ];
        });
    }

    /**
     * The shop turns a pending request down (e.g. not enough cash in the till); the money goes back.
     *
     * @return array{success: bool, message: string, status_code: int, code?: string, withdrawal?: ShopWithdrawal}
     */
    public function reject(BetShop $shop, string $withdrawalId, string $reason, ?string $actor): array
    {
        return DB::transaction(function () use ($shop, $withdrawalId, $reason, $actor) {
            $withdrawal = ShopWithdrawal::where('withdrawal_id', $withdrawalId)->where('bet_shop_id', $shop->id)->lockForUpdate()->first();

            if (! $withdrawal) {
                return $this->refused('Withdrawal not found', 404, self::CODE_NOT_FOUND);
            }

            if (! $withdrawal->isPending()) {
                return $this->refused("The withdrawal is already {$withdrawal->status}", 409, self::CODE_NOT_PENDING) + ['withdrawal' => $withdrawal];
            }

            $this->release($withdrawal, ShopWithdrawal::STATUS_REJECTED, $reason, $actor);

            return ['success' => true, 'message' => 'Withdrawal rejected; the money is back in the player\'s wallet', 'status_code' => 200, 'withdrawal' => $withdrawal->fresh(['betShop', 'customer'])];
        });
    }

    /**
     * Expire every pending request past its expiry time and give the money back. Returns how many.
     */
    public function expireDue(): int
    {
        $expired = 0;

        ShopWithdrawal::where('status', ShopWithdrawal::STATUS_PENDING)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use (&$expired) {
                DB::transaction(function () use ($id, &$expired) {
                    $withdrawal = ShopWithdrawal::lockForUpdate()->find($id);

                    if ($withdrawal?->hasExpired()) {
                        $this->release($withdrawal, ShopWithdrawal::STATUS_EXPIRED, 'Not collected in time', self::EXPIRY_ACTOR);
                        $expired++;
                    }
                });
            });

        return $expired;
    }

    /**
     * Withdrawals, newest first, filtered by shop, customer, status and the Nairobi day they were asked for.
     *
     * @param  array{status?: ?string, customer_id?: ?int, from?: ?string, to?: ?string}  $filters
     */
    public function paginate(?BetShop $shop, ?Customer $customer, array $filters, int $perPage): LengthAwarePaginator
    {
        return ShopWithdrawal::with(['betShop', 'customer'])
            ->when($shop, fn ($query) => $query->where('bet_shop_id', $shop->id))
            ->when($customer, fn ($query) => $query->where('customer_id', $customer->id))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['customer_id'] ?? null, fn ($query, int|string $customerId) => $query->where('customer_id', (int) $customerId))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->where('created_at', '>=', Carbon::parse($from, config('app.timezone'))->startOfDay()))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->where('created_at', '<=', Carbon::parse($to, config('app.timezone'))->endOfDay()))
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * A withdrawal for the shop (masked player, never the code), the player or GMS.
     *
     * @param  'shop'|'customer'|'gms'  $audience
     * @return array<string, mixed>
     */
    public function present(ShopWithdrawal $withdrawal, string $audience): array
    {
        $customer = $withdrawal->customer;

        $row = [
            'withdrawal_id' => $withdrawal->withdrawal_id,
            'shop_no' => $withdrawal->betShop?->shop_no,
            'shop_name' => $withdrawal->betShop?->name,
            'amount' => round((float) $withdrawal->amount, 2),
            'status' => $withdrawal->status,
            'expires_at' => $withdrawal->expires_at?->toIso8601String(),
            'requested_at' => $withdrawal->created_at?->toIso8601String(),
            'closed_at' => $withdrawal->closed_at?->toIso8601String(),
        ];

        if ($audience !== 'customer') {
            $row['player'] = $customer ? [
                'player_no' => $customer->player_no,
                'name' => $this->masker->name($customer->name),
            ] : null;
            $row['failed_attempts'] = $withdrawal->failed_attempts;
        }

        if ($audience !== 'shop') {
            $row['close_reason'] = $withdrawal->close_reason;
        }

        if ($audience === 'gms') {
            $row += [
                'id' => $withdrawal->id,
                'bet_shop_id' => $withdrawal->bet_shop_id,
                'customer_id' => $withdrawal->customer_id,
                'customer_name' => $customer?->name,
                'requested_by' => $withdrawal->requested_by,
                'closed_by' => $withdrawal->closed_by,
            ];
        }

        return $row;
    }

    /**
     * Give the held money back to the player and close the request. Call inside a transaction with the
     * withdrawal row locked.
     */
    private function release(ShopWithdrawal $withdrawal, string $status, string $reason, ?string $actor): void
    {
        $wallet = Wallet::lockForUpdate()->findOrFail($withdrawal->wallet_id);

        $this->ledgerService->recordShopWithdrawalRelease($withdrawal, $wallet, $reason);

        $withdrawal->update([
            'status' => $status,
            'closed_at' => now(),
            'closed_by' => $actor,
            'close_reason' => $reason,
        ]);

        Transaction::whereKey($withdrawal->transaction_id)->update(['status' => 3]);

        Log::info('Shop withdrawal released', ['withdrawal_id' => $withdrawal->withdrawal_id, 'status' => $status, 'reason' => $reason, 'by' => $actor]);
    }

    private function newPickupCode(): string
    {
        $length = (int) config('bet_shops.withdrawals.code_length');

        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{success: false, message: string, status_code: int, code: string}
     */
    private function refused(string $message, int $statusCode, string $code): array
    {
        return ['success' => false, 'message' => $message, 'status_code' => $statusCode, 'code' => $code];
    }
}
