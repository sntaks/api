<?php

namespace App\Services;

use App\Models\BetShop;
use App\Models\BetShopFloatPurchase;
use App\Models\BetShopWallet;
use App\Models\Deposit;
use App\Models\DepositResolution;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Float a shop owner buys by paying the paybill with bill ref SHOP-<shop_no>. The payment arrives as an
 * unmatched deposit and is credited here to the shop's float wallet in full: no excise duty (it is not a
 * betting deposit), no referral hook, and it is kept out of customer deposit figures.
 *
 * While callbacks are unauthenticated GMS credits it by hand after checking the M-Pesa statement;
 * `bet_shops.auto_credit_float` lets the C2B handler do it once callback authentication is enforced.
 * Voiding reverses the credit and puts the deposit back to unmatched.
 */
class BetShopFloatService
{
    public const CODE_INSUFFICIENT_FLOAT = 'insufficient_float';

    public const CODE_SHOP_CLOSED = 'shop_closed';

    /** Who an automatic credit is recorded as. */
    public const CALLBACK_ACTOR = 'c2b:auto';

    public function __construct(private LedgerService $ledgerService) {}

    /**
     * The shop a bill ref names (SHOP-<shop_no>, any case and spacing), or null.
     */
    public function shopFromBillRef(?string $billRef): ?BetShop
    {
        $prefix = preg_quote(config('bet_shops.bill_ref_prefix'), '/');

        if (! preg_match("/^\s*{$prefix}\s*([A-Z0-9]+)\s*$/i", (string) $billRef, $match)) {
            return null;
        }

        return BetShop::where('shop_no', strtoupper($match[1]))->first();
    }

    /**
     * Credit an unmatched deposit to a shop's float.
     *
     * @return array{success: bool, message: string, status_code: int, deposit?: Deposit, float_purchase?: BetShopFloatPurchase, code?: string}
     */
    public function credit(int $depositId, BetShop $shop, string $note, ?string $actor): array
    {
        return DB::transaction(function () use ($depositId, $shop, $note, $actor) {
            $deposit = Deposit::lockForUpdate()->find($depositId);

            if (! $deposit) {
                return $this->refused('Deposit not found', 404);
            }

            if ((int) $deposit->status !== Deposit::STATUS_UNMATCHED) {
                return $this->refused('Only unmatched deposits can be credited to a shop float', 409, $deposit, UnmatchedDepositService::CODE_ALREADY_RESOLVED);
            }

            return $this->creditLocked($deposit, $shop, $note, $actor);
        });
    }

    /**
     * Credit a C2B payment whose bill ref names a shop straight to its float, when automatic crediting is
     * on and the shop is active. Call inside the C2B transaction with the new deposit. Returns false when
     * the payment should be left unmatched instead.
     */
    public function creditFromCallback(Deposit $deposit): bool
    {
        if (! config('bet_shops.auto_credit_float')) {
            return false;
        }

        $shop = $this->shopFromBillRef($deposit->bill_ref_no);

        if (! $shop || ! $shop->isActive()) {
            return false;
        }

        return $this->creditLocked($deposit, $shop, 'Paybill payment with bill ref '.$deposit->bill_ref_no, self::CALLBACK_ACTOR)['success'];
    }

    /**
     * Void a float purchase: reverse the credit and put the deposit back to unmatched. Refused when the
     * float no longer holds the amount (it has been loaded to players).
     *
     * @return array{success: bool, message: string, status_code: int, float_purchase?: BetShopFloatPurchase, code?: string}
     */
    public function void(int $id, string $reason, ?string $actor): array
    {
        return DB::transaction(function () use ($id, $reason, $actor) {
            $purchase = BetShopFloatPurchase::lockForUpdate()->find($id);

            if (! $purchase) {
                return $this->refused('Float purchase not found', 404);
            }

            if ($purchase->isVoided()) {
                return $this->refused('Float purchase is already voided', 409, code: UnmatchedDepositService::CODE_ALREADY_RESOLVED) + ['float_purchase' => $purchase];
            }

            $deposit = Deposit::lockForUpdate()->findOrFail($purchase->deposit_id);
            $float = BetShopWallet::lockForUpdate()->where('bet_shop_id', $purchase->bet_shop_id)->firstOrFail();
            $amount = (float) $purchase->amount;

            if (round((float) $float->balance - $amount, 2) < 0) {
                return $this->refused('The shop float no longer holds this amount, so it cannot be reversed', 409, code: self::CODE_INSUFFICIENT_FLOAT) + ['float_purchase' => $purchase];
            }

            $reversal = $this->ledgerService->reverseShopFloatPurchase($purchase->ledgerEntry, $float, $reason);

            $purchase->update([
                'voided_at' => now(),
                'voided_by' => $actor,
                'void_reason' => $reason,
                'void_ledger_entry_id' => $reversal->id,
            ]);

            DepositResolution::where('deposit_id', $deposit->id)->where('action', DepositResolution::ACTION_SHOP_FLOAT)->delete();
            $deposit->update(['status' => Deposit::STATUS_UNMATCHED]);

            Log::channel('mpesa')->info('Shop float purchase voided', ['float_purchase_id' => $purchase->id, 'deposit_id' => $deposit->id, 'by' => $actor]);

            return ['success' => true, 'message' => 'Float purchase voided; the deposit is unmatched again', 'status_code' => 200, 'float_purchase' => $purchase->fresh()];
        });
    }

    public function paginate(BetShop $shop, int $perPage): LengthAwarePaginator
    {
        return BetShopFloatPurchase::with('deposit')
            ->where('bet_shop_id', $shop->id)
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(BetShopFloatPurchase $purchase): array
    {
        return [
            'id' => $purchase->id,
            'bet_shop_id' => $purchase->bet_shop_id,
            'deposit_id' => $purchase->deposit_id,
            'trans_id' => $purchase->deposit?->trans_id,
            'bill_ref_no' => $purchase->deposit?->bill_ref_no,
            'amount' => round((float) $purchase->amount, 2),
            'note' => $purchase->note,
            'status' => $purchase->isVoided() ? 'voided' : 'active',
            'recorded_by' => $purchase->recorded_by,
            'created_at' => $purchase->created_at?->toIso8601String(),
            'voided_at' => $purchase->voided_at?->toIso8601String(),
            'voided_by' => $purchase->voided_by,
            'void_reason' => $purchase->void_reason,
        ];
    }

    /**
     * @return array{success: bool, message: string, status_code: int, deposit?: Deposit, float_purchase?: BetShopFloatPurchase, code?: string}
     */
    private function creditLocked(Deposit $deposit, BetShop $shop, string $note, ?string $actor): array
    {
        $shop = BetShop::lockForUpdate()->findOrFail($shop->id);

        if ($shop->status === BetShop::STATUS_CLOSED) {
            return $this->refused('The shop is closed', 409, $deposit, self::CODE_SHOP_CLOSED);
        }

        $float = BetShopWallet::lockForUpdate()->firstOrCreate(['bet_shop_id' => $shop->id], ['balance' => 0]);
        $amount = (float) $deposit->trans_amount;

        $ledgerEntry = $this->ledgerService->recordShopFloatPurchase($deposit, $float, $amount);

        $deposit->update(['status' => Deposit::STATUS_COMPLETED]);

        $purchase = BetShopFloatPurchase::create([
            'bet_shop_id' => $shop->id,
            'deposit_id' => $deposit->id,
            'amount' => $amount,
            'ledger_entry_id' => $ledgerEntry->id,
            'note' => $note,
            'recorded_by' => $actor,
        ]);

        DepositResolution::create([
            'deposit_id' => $deposit->id,
            'action' => DepositResolution::ACTION_SHOP_FLOAT,
            'customer_id' => null,
            'ledger_entry_id' => $ledgerEntry->id,
            'note' => $note,
            'resolved_by' => $actor,
        ]);

        Log::channel('mpesa')->info('Deposit credited to a shop float', ['deposit_id' => $deposit->id, 'bet_shop_id' => $shop->id, 'float_purchase_id' => $purchase->id, 'amount' => $amount, 'by' => $actor]);

        return [
            'success' => true,
            'message' => 'Deposit credited to the shop float',
            'status_code' => 200,
            'deposit' => $deposit->fresh('resolution'),
            'float_purchase' => $purchase,
        ];
    }

    /**
     * @return array{success: false, message: string, status_code: int, deposit?: Deposit, code?: string}
     */
    private function refused(string $message, int $statusCode, ?Deposit $deposit = null, ?string $code = null): array
    {
        return array_filter([
            'success' => false,
            'message' => $message,
            'status_code' => $statusCode,
            'deposit' => $deposit,
            'code' => $code,
        ], fn ($value) => $value !== null);
    }
}
