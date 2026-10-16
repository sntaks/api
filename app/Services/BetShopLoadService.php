<?php

namespace App\Services;

use App\Models\BetShop;
use App\Models\BetShopLoad;
use App\Models\BetShopWallet;
use App\Models\Customer;
use App\Models\ExciseDutyCharge;
use App\Models\Wallet;
use App\Util\PlayerNumber;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * Cash loads at a bet shop: the player hands over cash, the shop's float goes down by the gross amount and
 * the player's wallet goes up by it, then excise duty is taken as for an M-Pesa deposit. The shop owner
 * keeps the cash. Only GMS can reverse a load; a shop can never take money back from a player.
 */
class BetShopLoadService
{
    public const CODE_INVALID_PLAYER_NO = 'invalid_player_no';

    public const CODE_PLAYER_NOT_FOUND = 'player_not_found';

    public const CODE_PLAYER_INACTIVE = 'player_inactive';

    public const CODE_SHOP_NOT_ACTIVE = 'shop_not_active';

    public const CODE_INSUFFICIENT_FLOAT = 'insufficient_float';

    public const CODE_AMOUNT_OUT_OF_RANGE = 'amount_out_of_range';

    public const CODE_DAILY_LIMIT = 'daily_limit_reached';

    public const CODE_ALREADY_REVERSED = 'already_reversed';

    public const CODE_PLAYER_HAS_SPENT = 'player_has_spent';

    public const CODE_EXCISE_REMITTED = 'excise_remitted';

    public function __construct(
        private LedgerService $ledgerService,
        private ExciseDutyService $exciseDuty,
        private ReferralService $referrals,
        private FinanceMasker $masker,
    ) {}

    /**
     * The player a typed number belongs to, for the shop counter: masked name and whether they can be
     * loaded. Soft-deleted customers are not found.
     *
     * @return array{success: bool, message: string, status_code: int, code?: string, player?: array{player_no: string, name: ?string, active: bool}}
     */
    public function lookup(string $playerNoInput): array
    {
        $playerNo = PlayerNumber::normalize($playerNoInput);

        if ($playerNo === null) {
            return ['success' => false, 'message' => 'That is not a valid player number. Check it and try again.', 'status_code' => 422, 'code' => self::CODE_INVALID_PLAYER_NO];
        }

        $customer = Customer::where('player_no', $playerNo)->first();

        if (! $customer) {
            return ['success' => false, 'message' => 'No player has that number', 'status_code' => 404, 'code' => self::CODE_PLAYER_NOT_FOUND];
        }

        return ['success' => true, 'message' => 'Player found', 'status_code' => 200, 'player' => $this->presentPlayer($customer)];
    }

    /**
     * Load a player's wallet with cash paid at the shop. A repeat with the same Idempotency-Key returns
     * the first load instead of loading again.
     *
     * @return array{success: bool, message: string, status_code: int, code?: string, load?: BetShopLoad, float_balance?: float, replayed?: bool}
     */
    public function load(BetShop $shop, string $playerNoInput, int $amount, string $idempotencyKey, ?string $actor): array
    {
        $playerNo = PlayerNumber::normalize($playerNoInput);

        if ($playerNo === null) {
            return $this->refused('That is not a valid player number. Check it and try again.', 422, self::CODE_INVALID_PLAYER_NO);
        }

        $min = (int) config('bet_shops.loads.min_amount');
        $max = (int) config('bet_shops.loads.max_amount');

        if ($amount < $min || $amount > $max) {
            return $this->refused("A load must be between KES {$min} and KES {$max}", 422, self::CODE_AMOUNT_OUT_OF_RANGE);
        }

        try {
            return DB::transaction(fn () => $this->loadLocked($shop, $playerNo, $amount, $idempotencyKey, $actor));
        } catch (UniqueConstraintViolationException) {
            // The same Idempotency-Key raced in twice; the first one won, so hand that load back.
            return $this->replay(BetShopLoad::where('bet_shop_id', $shop->id)->where('idempotency_key', $idempotencyKey)->firstOrFail());
        }
    }

    /**
     * Undo a load made by mistake (GMS only): the excise duty is given back, the player's wallet returns
     * the gross amount and the shop float gets it back. Refused when the player has already spent the
     * money, or the excise duty is already part of a KRA remittance.
     *
     * @return array{success: bool, message: string, status_code: int, code?: string, load?: BetShopLoad}
     */
    public function reverse(int $loadId, string $reason, ?string $actor): array
    {
        return DB::transaction(function () use ($loadId, $reason, $actor) {
            $load = BetShopLoad::lockForUpdate()->find($loadId);

            if (! $load) {
                return $this->refused('Load not found', 404);
            }

            if ($load->isReversed()) {
                return $this->refused('The load is already reversed', 409, self::CODE_ALREADY_REVERSED) + ['load' => $load];
            }

            $float = BetShopWallet::lockForUpdate()->where('bet_shop_id', $load->bet_shop_id)->firstOrFail();
            $wallet = Wallet::lockForUpdate()->findOrFail($load->wallet_id);

            if (round((float) $wallet->balance - (float) $load->net_amount, 2) < 0) {
                return $this->refused('The player has already spent this money, so the load cannot be reversed', 409, self::CODE_PLAYER_HAS_SPENT) + ['load' => $load];
            }

            $charge = $load->exciseDutyCharge;

            if ($charge && $charge->status === ExciseDutyCharge::STATUS_CHARGED) {
                if ($charge->isRemitted()) {
                    return $this->refused('The excise duty on this load has already been remitted to KRA, so the load cannot be reversed', 409, self::CODE_EXCISE_REMITTED) + ['load' => $load];
                }

                $this->exciseDuty->reverse($charge);
                $wallet->refresh();
            }

            $this->ledgerService->reverseShopLoad($load, $float, $wallet, $reason);

            $load->update([
                'status' => BetShopLoad::STATUS_REVERSED,
                'reversed_at' => now(),
                'reversed_by' => $actor,
                'reversal_reason' => $reason,
            ]);

            $wallet->transactions()
                ->where('payment_type', BetShopLoad::class)
                ->where('payment_id', $load->id)
                ->update(['status' => 3]);

            Log::info('Shop load reversed', ['load_id' => $load->load_id, 'bet_shop_id' => $load->bet_shop_id, 'customer_id' => $load->customer_id, 'by' => $actor, 'reason' => $reason]);

            return ['success' => true, 'message' => 'Load reversed', 'status_code' => 200, 'load' => $load->fresh(['betShop', 'customer'])];
        });
    }

    /**
     * Loads, newest first: one shop's, or every shop's when $shop is null.
     *
     * @param  array{from?: ?string, to?: ?string, status?: ?string, customer_id?: ?int}  $filters
     */
    public function paginate(?BetShop $shop, array $filters, int $perPage): LengthAwarePaginator
    {
        $timezone = config('app.timezone');

        return BetShopLoad::with(['customer', 'betShop'])
            ->when($shop, fn ($query) => $query->where('bet_shop_id', $shop->id))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['customer_id'] ?? null, fn ($query, int $customerId) => $query->where('customer_id', $customerId))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->where('created_at', '>=', Carbon::parse($from, $timezone)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->where('created_at', '<=', Carbon::parse($to, $timezone)->endOfDay()))
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * What a shop has loaded today (app timezone), not counting reversed loads.
     *
     * @return array{loads: int, gross_amount: float}
     */
    public function todayFor(BetShop $shop): array
    {
        $totals = BetShopLoad::where('bet_shop_id', $shop->id)
            ->where('status', BetShopLoad::STATUS_COMPLETED)
            ->where('created_at', '>=', now()->startOfDay())
            ->selectRaw('COUNT(*) as loads, COALESCE(SUM(gross_amount), 0) as gross')
            ->first();

        return ['loads' => (int) $totals->loads, 'gross_amount' => round((float) $totals->gross, 2)];
    }

    /**
     * A load as the shop sees it: the player's number and masked name only. GMS also gets the customer.
     *
     * @return array<string, mixed>
     */
    public function present(BetShopLoad $load, bool $forGms = false): array
    {
        $customer = $load->customer;

        return [
            'id' => $load->id,
            'load_id' => $load->load_id,
            'shop_no' => $load->betShop?->shop_no,
            'player' => $customer ? $this->presentPlayer($customer) : null,
            'gross_amount' => round((float) $load->gross_amount, 2),
            'excise_amount' => round((float) $load->excise_amount, 2),
            'net_amount' => round((float) $load->net_amount, 2),
            'status' => $load->status,
            'loaded_at' => $load->created_at?->toIso8601String(),
            'reversed_at' => $load->reversed_at?->toIso8601String(),
        ] + ($forGms ? [
            'bet_shop_id' => $load->bet_shop_id,
            'customer_id' => $load->customer_id,
            'customer_name' => $customer?->name,
            'wallet_id' => $load->wallet_id,
            'loaded_by' => $load->loaded_by,
            'reversed_by' => $load->reversed_by,
            'reversal_reason' => $load->reversal_reason,
        ] : []);
    }

    /**
     * @return array{player_no: string, name: ?string, active: bool}
     */
    public function presentPlayer(Customer $customer): array
    {
        return [
            'player_no' => $customer->player_no,
            'name' => $this->masker->name($customer->name),
            'active' => (int) $customer->status === 1 && ! $customer->trashed(),
        ];
    }

    /**
     * @return array{success: bool, message: string, status_code: int, code?: string, load?: BetShopLoad, float_balance?: float, replayed?: bool}
     */
    private function loadLocked(BetShop $shop, string $playerNo, int $amount, string $idempotencyKey, ?string $actor): array
    {
        $shop = BetShop::lockForUpdate()->findOrFail($shop->id);

        if (! $shop->isActive()) {
            return $this->refused('The shop is not active', 403, self::CODE_SHOP_NOT_ACTIVE);
        }

        $existing = BetShopLoad::where('bet_shop_id', $shop->id)->where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return $this->replay($existing);
        }

        $customer = Customer::where('player_no', $playerNo)->first();

        if (! $customer) {
            return $this->refused('No player has that number', 404, self::CODE_PLAYER_NOT_FOUND);
        }

        if ((int) $customer->status !== 1) {
            return $this->refused('The player\'s account is not active', 409, self::CODE_PLAYER_INACTIVE);
        }

        $float = BetShopWallet::lockForUpdate()->firstOrCreate(['bet_shop_id' => $shop->id], ['balance' => 0]);

        if (round((float) $float->balance - $amount, 2) < 0) {
            return $this->refused('The shop float is too low for this load', 409, self::CODE_INSUFFICIENT_FLOAT) + ['float_balance' => round((float) $float->balance, 2)];
        }

        $wallet = Wallet::firstOrCreate(['customer_id' => $customer->id], ['balance' => 0]);
        $wallet = Wallet::lockForUpdate()->find($wallet->id);

        // Counted after the wallet lock, so two shops loading the same player at once cannot both pass.
        $loadedToday = (float) BetShopLoad::where('customer_id', $customer->id)
            ->where('status', BetShopLoad::STATUS_COMPLETED)
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('gross_amount');
        $dailyLimit = (int) config('bet_shops.loads.daily_limit_per_player');

        if ($loadedToday + $amount > $dailyLimit) {
            return $this->refused("The player can be loaded at most KES {$dailyLimit} a day at shops; KES ".number_format(max($dailyLimit - $loadedToday, 0), 0, '.', '').' is left today', 422, self::CODE_DAILY_LIMIT);
        }

        $load = BetShopLoad::create([
            'load_id' => Uuid::uuid4()->toString(),
            'bet_shop_id' => $shop->id,
            'customer_id' => $customer->id,
            'wallet_id' => $wallet->id,
            'gross_amount' => $amount,
            'excise_amount' => 0,
            'net_amount' => $amount,
            'status' => BetShopLoad::STATUS_COMPLETED,
            'idempotency_key' => $idempotencyKey,
            'loaded_by' => $actor,
        ]);

        [$floatEntry, $walletEntry] = $this->ledgerService->recordShopLoad($load, $float, $wallet, (float) $amount);
        $charge = $this->exciseDuty->chargeShopLoad($load, $wallet);

        $load->update([
            'shop_ledger_entry_id' => $floatEntry->id,
            'customer_ledger_entry_id' => $walletEntry->id,
            'excise_amount' => $charge?->excise_amount ?? 0,
            'net_amount' => $charge?->net_amount ?? $amount,
        ]);

        $wallet->transactions()->create([
            'payment_id' => $load->id,
            'payment_ref' => $load->load_id,
            'payment_type' => BetShopLoad::class,
            'amount' => $load->net_amount,
            'status' => 2,
            'balance_before' => $walletEntry->balance_before,
            'balance_after' => $wallet->balance,
        ]);

        $this->referrals->recordShopLoad($load, $customer->id);

        Log::info('Shop load', ['load_id' => $load->load_id, 'bet_shop_id' => $shop->id, 'customer_id' => $customer->id, 'gross' => $amount, 'by' => $actor]);

        return [
            'success' => true,
            'message' => 'Player loaded',
            'status_code' => 201,
            'load' => $load->fresh(['customer', 'betShop']),
            'float_balance' => round((float) $float->balance, 2),
        ];
    }

    /**
     * @return array{success: true, message: string, status_code: int, load: BetShopLoad, float_balance: float, replayed: true}
     */
    private function replay(BetShopLoad $load): array
    {
        return [
            'success' => true,
            'message' => 'Already loaded with this Idempotency-Key',
            'status_code' => 200,
            'load' => $load->load(['customer', 'betShop']),
            'float_balance' => round((float) BetShopWallet::where('bet_shop_id', $load->bet_shop_id)->value('balance'), 2),
            'replayed' => true,
        ];
    }

    /**
     * @return array{success: false, message: string, status_code: int, code?: string}
     */
    private function refused(string $message, int $statusCode, ?string $code = null): array
    {
        return array_filter([
            'success' => false,
            'message' => $message,
            'status_code' => $statusCode,
            'code' => $code,
        ], fn ($value) => $value !== null);
    }
}
