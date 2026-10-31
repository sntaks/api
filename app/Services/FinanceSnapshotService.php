<?php

namespace App\Services;

use App\Models\BetShopWallet;
use App\Models\Coin;
use App\Models\CompetitionWallet;
use App\Models\Deposit;
use App\Models\DisputedTransaction;
use App\Models\ExciseDutyCharge;
use App\Models\FinancialSnapshot;
use App\Models\FinancialSnapshotLine;
use App\Models\GameWallet;
use App\Models\MpesaBalance;
use App\Models\PendingBalance;
use App\Models\Product;
use App\Models\ReferralWallet;
use App\Models\ShopWithdrawal;
use App\Models\Wager;
use App\Models\Wallet;
use App\Models\WinningsTaxCharge;
use Illuminate\Support\Facades\DB;

class FinanceSnapshotService
{
    /**
     * Balance sheet accounts that belong to the whole platform, keyed by their currentPosition() field.
     * One wallet per player serves every game, so customer wallets are never split by product.
     */
    private const PLATFORM_ACCOUNTS = [
        'customer_wallets_total' => 'customer_wallets',
        'stuck_escrow_total' => 'stuck_escrow',
        'coin_liability' => 'coin_liability',
        'pending_holds_total' => 'pending_holds',
        'unmatched_deposits_total' => 'unmatched_deposits',
        'excise_duty_payable' => 'excise_duty_payable',
        'disputed_funds_total' => 'disputed_funds',
        'referral_wallets_total' => 'referral_wallets',
        'bet_shop_floats_total' => 'bet_shop_floats',
        'shop_withdrawals_held_total' => 'shop_withdrawals',
    ];

    /**
     * Record today's position and its per-product lines. Running it again on the same day overwrites both.
     */
    public function takeSnapshot(): FinancialSnapshot
    {
        return DB::transaction(function () {
            $date = now()->toDateString();
            $position = $this->currentPosition();

            $snapshot = FinancialSnapshot::updateOrCreate(['snapshot_date' => $date], $position + ['taken_at' => now()]);

            // Deleted and written again: a unique key does not stop two platform lines, whose product_id is null
            FinancialSnapshotLine::whereDate('snapshot_date', $date)->delete();
            foreach ($this->positionByProduct($position) as $line) {
                FinancialSnapshotLine::create($line + ['snapshot_date' => $date]);
            }

            return $snapshot;
        });
    }

    /**
     * The position split by product (section 5.7): open wagers, game and competition escrow and winnings tax
     * payable per product, and one platform line (product_id null) for every account that has no product.
     * Product lines with nothing in them are left out.
     *
     * @param  array<string, mixed>|null  $position  currentPosition(), when the caller already has it
     * @return list<array{product_id: int|null, account: string, amount: float}>
     */
    public function positionByProduct(?array $position = null): array
    {
        $position ??= $this->currentPosition();
        $testCustomerIds = config('finance.test_customer_ids');
        $lines = [];

        $add = function (?int $productId, string $account, float $amount) use (&$lines) {
            if ($productId !== null && round($amount, 2) == 0.0) {
                return;
            }

            $lines[] = ['product_id' => $productId, 'account' => $account, 'amount' => $this->money($amount)];
        };

        $openWagers = Wager::whereNotIn('customer_id', $testCustomerIds)->groupBy('product_id')->selectRaw('product_id, SUM(balance) as amount')->pluck('amount', 'product_id');
        foreach ($openWagers as $productId => $amount) {
            $add((int) $productId, 'open_wagers', (float) $amount);
        }

        $add(Product::idFor(Product::CODE_KADI), 'game_escrow', (float) GameWallet::where('status', 1)->sum('balance'));

        $competitionEscrow = CompetitionWallet::where('status', 1)->groupBy('game_type')->selectRaw('game_type, SUM(balance) as amount')->pluck('amount', 'game_type');
        foreach ($competitionEscrow as $gameType => $amount) {
            $code = match ((int) $gameType) {
                1 => Product::CODE_KADI_TOURNAMENT,
                2 => Product::CODE_KADI_JACKPOT,
                default => null,
            };
            $add($code === null ? null : Product::idFor($code), 'competition_escrow', (float) $amount);
        }

        $taxPayable = WinningsTaxCharge::whereNull('remittance_id')->groupBy('product_id')->selectRaw('product_id, SUM(amount) as amount')->pluck('amount', 'product_id');
        foreach ($taxPayable as $productId => $amount) {
            $add((int) $productId, 'winnings_tax_payable', (float) $amount);
        }

        foreach (self::PLATFORM_ACCOUNTS as $field => $account) {
            $add(null, $account, (float) $position[$field]);
        }

        return $lines;
    }

    /**
     * The position right now.
     *
     * Customer wallets and coins exclude the house wallet and the configured test customers.
     * Stuck escrow is money still sitting in a game or competition wallet that is no longer open.
     * Excise duty payable is duty taken from deposits and not yet in a KRA remittance.
     * Winnings tax payable is tax withheld from house-banked wins, less reversal credits, not yet in a KRA remittance.
     * Open wagers are stakes held in the escrow of house-banked bets not yet settled (test customers excluded).
     * Disputed funds are winnings held in dispute escrow while a complaint is pending.
     * Referral wallets are referral bonuses not yet withdrawn (test customers excluded).
     * Bet shop floats are prepaid float shop owners bought and have not yet loaded to players.
     * Shop withdrawals held are cash withdrawals players asked for at a shop and have not collected yet.
     *
     * @return array{customer_wallets_total: float, house_wallet_balance: float, game_escrow_total: float, competition_escrow_total: float, stuck_escrow_total: float, coin_liability: float, pending_holds_total: float, unmatched_deposits_total: float, excise_duty_payable: float, winnings_tax_payable: float, open_wagers_total: float, disputed_funds_total: float, referral_wallets_total: float, bet_shop_floats_total: float, shop_withdrawals_held_total: float, mpesa_balances: array<string, array<string, array{amount: float, as_of: string}>>|null}
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
            'winnings_tax_payable' => $this->money(WinningsTaxCharge::whereNull('remittance_id')->sum('amount')),
            'open_wagers_total' => $this->money(Wager::whereNotIn('customer_id', $testCustomerIds)->sum('balance')),
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
