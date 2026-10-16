<?php

namespace App\Services;

use App\Models\BetShopWallet;
use App\Models\Coin;
use App\Models\CompetitionWallet;
use App\Models\Deposit;
use App\Models\DisputedTransaction;
use App\Models\ExciseDutyCharge;
use App\Models\FinancialSnapshot;
use App\Models\GameWallet;
use App\Models\MpesaBalance;
use App\Models\PendingBalance;
use App\Models\ReferralWallet;
use App\Models\ShopWithdrawal;
use App\Models\Wallet;

class FinanceSnapshotService
{
    /**
     * Record today's position. Running it again on the same day overwrites that day's row.
     */
    public function takeSnapshot(): FinancialSnapshot
    {
        return FinancialSnapshot::updateOrCreate(
            ['snapshot_date' => now()->toDateString()],
            $this->currentPosition() + ['taken_at' => now()]
        );
    }

    /**
     * The position right now.
     *
     * Customer wallets and coins exclude the house wallet and the configured test customers.
     * Stuck escrow is money still sitting in a game or competition wallet that is no longer open.
     * Excise duty payable is duty taken from deposits and not yet in a KRA remittance.
     * Disputed funds are winnings held in dispute escrow while a complaint is pending.
     * Referral wallets are referral bonuses not yet withdrawn (test customers excluded).
     * Bet shop floats are prepaid float shop owners bought and have not yet loaded to players.
     * Shop withdrawals held are cash withdrawals players asked for at a shop and have not collected yet.
     *
     * @return array{customer_wallets_total: float, house_wallet_balance: float, game_escrow_total: float, competition_escrow_total: float, stuck_escrow_total: float, coin_liability: float, pending_holds_total: float, unmatched_deposits_total: float, excise_duty_payable: float, disputed_funds_total: float, referral_wallets_total: float, bet_shop_floats_total: float, shop_withdrawals_held_total: float, mpesa_balances: array<string, array<string, array{amount: float, as_of: string}>>|null}
     */
    public function currentPosition(): array
    {
        $houseWalletId = (int) config('wallets.house_wallet_id', 1);
        $testCustomerIds = config('finance.test_customer_ids');

        $stuckEscrow = GameWallet::where('status', '!=', 1)->where('balance', '>', 0)->sum('balance')
            + CompetitionWallet::where('status', '!=', 1)->where('balance', '>', 0)->sum('balance');

        $coins = Coin::whereNotIn('customer_id', $testCustomerIds)->sum('coins');

        return [
            'customer_wallets_total' => $this->money(
                Wallet::where('id', '!=', $houseWalletId)->whereNotIn('customer_id', $testCustomerIds)->sum('balance')
            ),
            'house_wallet_balance' => $this->money(Wallet::whereKey($houseWalletId)->sum('balance')),
            'game_escrow_total' => $this->money(GameWallet::where('status', 1)->sum('balance')),
            'competition_escrow_total' => $this->money(CompetitionWallet::where('status', 1)->sum('balance')),
            'stuck_escrow_total' => $this->money($stuckEscrow),
            'coin_liability' => $this->money($coins * config('finance.coin_rate')),
            'pending_holds_total' => $this->money(PendingBalance::where('status', 'holding')->sum('amount')),
            'unmatched_deposits_total' => $this->money(Deposit::where('status', 0)->sum('trans_amount')),
            'excise_duty_payable' => $this->money(ExciseDutyCharge::charged()->whereNull('remittance_id')->sum('excise_amount')),
            'disputed_funds_total' => $this->money(DisputedTransaction::held()->sum('balance')),
            'referral_wallets_total' => $this->money(ReferralWallet::whereNotIn('customer_id', $testCustomerIds)->sum('balance')),
            'bet_shop_floats_total' => $this->money(BetShopWallet::sum('balance')),
            'shop_withdrawals_held_total' => $this->money(ShopWithdrawal::where('status', ShopWithdrawal::STATUS_PENDING)->sum('balance')),
            'mpesa_balances' => $this->latestMpesaBalances(),
        ];
    }

    /**
     * Latest balance per M-Pesa account, e.g. ['b2c' => ['Utility Account' => ['amount' => 1200.0, 'as_of' => '...']]].
     *
     * @return array<string, array<string, array{amount: float, as_of: string}>>|null
     */
    private function latestMpesaBalances(): ?array
    {
        $latest = MpesaBalance::whereIn(
            'id',
            MpesaBalance::selectRaw('MAX(id)')->groupBy('type', 'account_name')
        )->get();

        if ($latest->isEmpty()) {
            return null;
        }

        return $latest
            ->groupBy('type')
            ->map(fn ($rows) => $rows->mapWithKeys(fn (MpesaBalance $row) => [
                (string) $row->account_name => [
                    'amount' => (float) $row->amount,
                    'as_of' => $row->created_at->toIso8601String(),
                ],
            ])->all())
            ->all();
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
