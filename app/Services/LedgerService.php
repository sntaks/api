<?php

namespace App\Services;

use App\Models\BetShopFloatRedemption;
use App\Models\BetShopLoad;
use App\Models\BetShopWallet;
use App\Models\Coin;
use App\Models\CompetitionTransaction;
use App\Models\CompetitionWallet;
use App\Models\Complaint;
use App\Models\Deposit;
use App\Models\DisputedTransaction;
use App\Models\GameWallet;
use App\Models\LedgerEntry;
use App\Models\PendingBalance;
use App\Models\PromotionCredit;
use App\Models\Referral;
use App\Models\ReferralWallet;
use App\Models\ReferralWithdrawal;
use App\Models\ShopWithdrawal;
use App\Models\Wager;
use App\Models\WagerSettlement;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdraw;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class LedgerService
{
    public function __construct(private ProductResolver $products = new ProductResolver) {}

    public function recordDeposit($deposit, Wallet $wallet, float $amount): LedgerEntry
    {
        $balanceBefore = $wallet->balance;
        $wallet->balance += $amount;
        $wallet->save();

        return $this->createEntry(
            entryType: 'deposit',
            referenceable: $deposit,
            wallet: $wallet,
            customerId: $wallet->customer_id,
            debit: 0,
            credit: $amount,
            balanceBefore: $balanceBefore,
            balanceAfter: $wallet->balance
        );
    }

    /**
     * Take excise duty from a wallet that was just credited with a deposit.
     */
    public function recordExciseDuty(Deposit $deposit, Wallet $wallet, float $amount, float $rate): LedgerEntry
    {
        $balanceBefore = $wallet->balance;
        $wallet->balance -= $amount;
        $wallet->save();

        return $this->createEntry(
            entryType: 'excise_duty',
            referenceable: $deposit,
            wallet: $wallet,
            customerId: $wallet->customer_id,
            debit: $amount,
            credit: 0,
            balanceBefore: $balanceBefore,
            balanceAfter: $wallet->balance,
            metadata: ['deposit_id' => $deposit->id, 'rate' => $rate, 'gross_amount' => (float) $deposit->trans_amount]
        );
    }

    /**
     * Credit owner money paid into the paybill to the house wallet as capital. Single-sided like a deposit
     * (the cash side is M-Pesa), but never a customer deposit: no excise duty, no referral hook.
     */
    public function recordHouseFunding(Deposit $deposit, Wallet $houseWallet, float $amount): LedgerEntry
    {
        $balanceBefore = $houseWallet->balance;
        $houseWallet->balance += $amount;
        $houseWallet->save();

        return $this->createEntry(
            entryType: 'house_funding',
            referenceable: $deposit,
            wallet: $houseWallet,
            customerId: $houseWallet->customer_id,
            debit: 0,
            credit: $amount,
            balanceBefore: $balanceBefore,
            balanceAfter: $houseWallet->balance,
            metadata: ['deposit_id' => $deposit->id, 'trans_id' => $deposit->trans_id]
        );
    }

    /**
     * Take a voided house funding back out of the house wallet.
     */
    public function reverseHouseFunding(LedgerEntry $funding, Wallet $houseWallet, string $reason): LedgerEntry
    {
        $amount = (float) $funding->credit;

        $balanceBefore = $houseWallet->balance;
        $houseWallet->balance -= $amount;
        $houseWallet->save();

        $funding->status = 'reversed';
        $funding->save();

        return $this->createEntry(
            entryType: 'house_funding_reversal',
            referenceable: $funding->referenceable,
            wallet: $houseWallet,
            customerId: $houseWallet->customer_id,
            debit: $amount,
            credit: 0,
            balanceBefore: $balanceBefore,
            balanceAfter: $houseWallet->balance,
            metadata: ($funding->metadata ?? []) + ['original_entry_id' => $funding->entry_id, 'reason' => $reason]
        );
    }

    /**
     * Credit float a shop owner bought through the paybill to the shop's float wallet. Single-sided like a
     * deposit (the cash side is M-Pesa), and not a customer deposit: no excise duty, no referral hook.
     */
    public function recordShopFloatPurchase(Deposit $deposit, BetShopWallet $float, float $amount): LedgerEntry
    {
        $balanceBefore = $float->balance;
        $float->balance += $amount;
        $float->save();

        return $this->createEntry(
            entryType: 'shop_float_purchase',
            referenceable: $deposit,
            wallet: $float,
            customerId: null,
            debit: 0,
            credit: $amount,
            balanceBefore: $balanceBefore,
            balanceAfter: $float->balance,
            metadata: ['deposit_id' => $deposit->id, 'trans_id' => $deposit->trans_id, 'bet_shop_id' => $float->bet_shop_id]
        );
    }

    /**
     * Take a voided float purchase back out of the shop's float.
     */
    public function reverseShopFloatPurchase(LedgerEntry $purchase, BetShopWallet $float, string $reason): LedgerEntry
    {
        $amount = (float) $purchase->credit;

        $balanceBefore = $float->balance;
        $float->balance -= $amount;
        $float->save();

        $purchase->status = 'reversed';
        $purchase->save();

        return $this->createEntry(
            entryType: 'shop_float_purchase_reversal',
            referenceable: $purchase->referenceable,
            wallet: $float,
            customerId: null,
            debit: $amount,
            credit: 0,
            balanceBefore: $balanceBefore,
            balanceAfter: $float->balance,
            metadata: ($purchase->metadata ?? []) + ['original_entry_id' => $purchase->entry_id, 'reason' => $reason]
        );
    }

    /**
     * Take float paid back to the shop owner out of the shop's float. Single-sided like a withdrawal: the
     * cash side is the payment GMS made outside the API.
     */
    public function recordShopFloatRedemption(BetShopFloatRedemption $redemption, BetShopWallet $float): LedgerEntry
    {
        $amount = (float) $redemption->amount;

        $balanceBefore = $float->balance;
        $float->balance -= $amount;
        $float->save();

        return $this->createEntry(
            entryType: 'shop_float_redemption',
            referenceable: $redemption,
            wallet: $float,
            customerId: null,
            debit: $amount,
            credit: 0,
            balanceBefore: $balanceBefore,
            balanceAfter: $float->balance,
            metadata: ['bet_shop_id' => $float->bet_shop_id, 'payment_reference' => $redemption->payment_reference]
        );
    }

    /**
     * Put a voided float redemption back on the shop's float.
     */
    public function reverseShopFloatRedemption(LedgerEntry $redemption, BetShopWallet $float, string $reason): LedgerEntry
    {
        $amount = (float) $redemption->debit;

        $balanceBefore = $float->balance;
        $float->balance += $amount;
        $float->save();

        $redemption->status = 'reversed';
        $redemption->save();

        return $this->createEntry(
            entryType: 'shop_float_redemption_reversal',
            referenceable: $redemption->referenceable,
            wallet: $float,
            customerId: null,
            debit: 0,
            credit: $amount,
            balanceBefore: $balanceBefore,
            balanceAfter: $float->balance,
            metadata: ($redemption->metadata ?? []) + ['original_entry_id' => $redemption->entry_id, 'reason' => $reason]
        );
    }

    /**
     * Move cash a player paid at a shop from the shop's float to the player's wallet, gross. Paired, so it
     * nets to zero; excise duty is then taken from the wallet with recordShopLoadExciseDuty().
     *
     * @return array{0: LedgerEntry, 1: LedgerEntry} [shop float entry, customer wallet entry]
     */
    public function recordShopLoad(BetShopLoad $load, BetShopWallet $float, Wallet $wallet, float $amount): array
    {
        $floatBefore = $float->balance;
        $float->balance -= $amount;
        $float->save();

        $walletBefore = $wallet->balance;
        $wallet->balance += $amount;
        $wallet->save();

        $metadata = ['bet_shop_id' => $load->bet_shop_id, 'load_id' => $load->load_id];

        $floatEntry = $this->createEntry(
            entryType: 'shop_load',
            referenceable: $load,
            wallet: $float,
            customerId: null,
            debit: $amount,
            credit: 0,
            balanceBefore: $floatBefore,
            balanceAfter: $float->balance,
            metadata: $metadata + ['customer_id' => $wallet->customer_id]
        );

        $walletEntry = $this->createEntry(
            entryType: 'shop_load',
            referenceable: $load,
            wallet: $wallet,
            customerId: $wallet->customer_id,
            debit: 0,
            credit: $amount,
            balanceBefore: $walletBefore,
            balanceAfter: $wallet->balance,
            metadata: $metadata
        );

        return [$floatEntry, $walletEntry];
    }

    /**
     * Take excise duty from a wallet that was just loaded at a shop.
     */
    public function recordShopLoadExciseDuty(BetShopLoad $load, Wallet $wallet, float $amount, float $rate): LedgerEntry
    {
        $balanceBefore = $wallet->balance;
        $wallet->balance -= $amount;
        $wallet->save();

        return $this->createEntry(
            entryType: 'excise_duty',
            referenceable: $load,
            wallet: $wallet,
            customerId: $wallet->customer_id,
            debit: $amount,
            credit: 0,
            balanceBefore: $balanceBefore,
            balanceAfter: $wallet->balance,
            metadata: ['bet_shop_load_id' => $load->id, 'rate' => $rate, 'gross_amount' => (float) $load->gross_amount]
        );
    }

    /**
     * Undo a shop load: the player's wallet gives back the gross amount and the shop float gets it back.
     * Reverse the load's excise duty first, so the wallet holds the gross again.
     *
     * @return array{0: LedgerEntry, 1: LedgerEntry} [shop float entry, customer wallet entry]
     */
    public function reverseShopLoad(BetShopLoad $load, BetShopWallet $float, Wallet $wallet, string $reason): array
    {
        $amount = (float) $load->gross_amount;

        $walletBefore = $wallet->balance;
        $wallet->balance -= $amount;
        $wallet->save();

        $floatBefore = $float->balance;
        $float->balance += $amount;
        $float->save();

        LedgerEntry::whereIn('id', [$load->shop_ledger_entry_id, $load->customer_ledger_entry_id])->update(['status' => 'reversed']);

        $metadata = ['bet_shop_id' => $load->bet_shop_id, 'load_id' => $load->load_id, 'reason' => $reason];

        $floatEntry = $this->createEntry(
            entryType: 'shop_load_reversal',
            referenceable: $load,
            wallet: $float,
            customerId: null,
            debit: 0,
            credit: $amount,
            balanceBefore: $floatBefore,
            balanceAfter: $float->balance,
            metadata: $metadata + ['customer_id' => $wallet->customer_id]
        );

        $walletEntry = $this->createEntry(
            entryType: 'shop_load_reversal',
            referenceable: $load,
            wallet: $wallet,
            customerId: $wallet->customer_id,
            debit: $amount,
            credit: 0,
            balanceBefore: $walletBefore,
            balanceAfter: $wallet->balance,
            metadata: $metadata
        );

        return [$floatEntry, $walletEntry];
    }

    /**
     * Take a shop cash withdrawal out of the player's wallet and hold it on the withdrawal until it is paid.
     *
     * @return array{0: LedgerEntry, 1: LedgerEntry} [customer wallet entry, withdrawal entry]
     */
    public function recordShopWithdrawalHold(ShopWithdrawal $withdrawal, Wallet $wallet, float $amount): array
    {
        return $this->move('shop_withdrawal_hold', $withdrawal, $wallet, $withdrawal, $amount);
    }

    /**
     * Give a held shop cash withdrawal back to the player's wallet (cancelled, rejected or expired).
     *
     * @return array{0: LedgerEntry, 1: LedgerEntry} [withdrawal entry, customer wallet entry]
     */
    public function recordShopWithdrawalRelease(ShopWithdrawal $withdrawal, Wallet $wallet, string $reason): array
    {
        return $this->move('shop_withdrawal_release', $withdrawal, $withdrawal, $wallet, (float) $withdrawal->balance, ['reason' => $reason]);
    }

    /**
     * The shop paid the player the cash: the held amount moves to the shop's float, which the owner spent
     * from the till.
     *
     * @return array{0: LedgerEntry, 1: LedgerEntry} [withdrawal entry, shop float entry]
     */
    public function recordShopCashOut(ShopWithdrawal $withdrawal, BetShopWallet $float): array
    {
        return $this->move('shop_cash_out', $withdrawal, $withdrawal, $float, (float) $withdrawal->balance);
    }

    /**
     * Move an amount from one wallet to another as a matching pair of entries of the same type.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{0: LedgerEntry, 1: LedgerEntry} [from entry, to entry]
     */
    private function move(string $entryType, Model $reference, Model $from, Model $to, float $amount, array $metadata = []): array
    {
        $fromBefore = $from->balance;
        $from->balance -= $amount;
        $from->save();

        $toBefore = $to->balance;
        $to->balance += $amount;
        $to->save();

        $metadata += ['reference_id' => $reference->getKey()];

        $fromEntry = $this->createEntry(
            entryType: $entryType,
            referenceable: $reference,
            wallet: $from,
            customerId: $from instanceof Wallet ? $from->customer_id : null,
            debit: $amount,
            credit: 0,
            balanceBefore: $fromBefore,
            balanceAfter: $from->balance,
            metadata: $metadata
        );

        $toEntry = $this->createEntry(
            entryType: $entryType,
            referenceable: $reference,
            wallet: $to,
            customerId: $to instanceof Wallet ? $to->customer_id : null,
            debit: 0,
            credit: $amount,
            balanceBefore: $toBefore,
            balanceAfter: $to->balance,
            metadata: $metadata
        );

        return [$fromEntry, $toEntry];
    }

    /**
     * Move a promotion's gross amount from the house wallet to the customer's wallet. Paired, so it nets to
     * zero in the trial balance; the cost shows as the house wallet going down.
     *
     * @return array{0: LedgerEntry, 1: LedgerEntry} [house entry, customer entry]
     */
    public function recordPromotionCredit(PromotionCredit $credit, Wallet $houseWallet, Wallet $customerWallet, float $amount): array
    {
        $metadata = ['promotion' => $credit->promotion, 'promotion_credit_id' => $credit->id];

        $houseBefore = $houseWallet->balance;
        $houseWallet->balance -= $amount;
        $houseWallet->save();

        $houseEntry = $this->createEntry(
            entryType: 'promo_credit',
            referenceable: $credit,
            wallet: $houseWallet,
            customerId: $houseWallet->customer_id,
            debit: $amount,
            credit: 0,
            balanceBefore: $houseBefore,
            balanceAfter: $houseWallet->balance,
            metadata: $metadata
        );

        $customerBefore = $customerWallet->balance;
        $customerWallet->balance += $amount;
        $customerWallet->save();

        $customerEntry = $this->createEntry(
            entryType: 'promo_credit',
            referenceable: $credit,
            wallet: $customerWallet,
            customerId: $customerWallet->customer_id,
            debit: 0,
            credit: $amount,
            balanceBefore: $customerBefore,
            balanceAfter: $customerWallet->balance,
            metadata: $metadata
        );

        return [$houseEntry, $customerEntry];
    }

    /**
     * Take excise duty from a wallet that was just credited with a promotion.
     */
    public function recordPromotionExciseDuty(PromotionCredit $credit, Wallet $wallet, float $amount, float $rate): LedgerEntry
    {
        $balanceBefore = $wallet->balance;
        $wallet->balance -= $amount;
        $wallet->save();

        return $this->createEntry(
            entryType: 'excise_duty',
            referenceable: $credit,
            wallet: $wallet,
            customerId: $wallet->customer_id,
            debit: $amount,
            credit: 0,
            balanceBefore: $balanceBefore,
            balanceAfter: $wallet->balance,
            metadata: ['promotion_credit_id' => $credit->id, 'rate' => $rate, 'gross_amount' => (float) $credit->gross_amount]
        );
    }

    public function recordWithdrawal($withdraw, Wallet $wallet, float $amount): LedgerEntry
    {
        $balanceBefore = $wallet->balance;
        $wallet->balance -= $amount;
        $wallet->save();

        return $this->createEntry(
            entryType: 'withdrawal',
            referenceable: $withdraw,
            wallet: $wallet,
            customerId: $wallet->customer_id,
            debit: $amount,
            credit: 0,
            balanceBefore: $balanceBefore,
            balanceAfter: $wallet->balance
        );
    }

    public function recordGameBet($gameTransaction, Wallet $customerWallet, $gameWallet, float $amount): array
    {
        $customerBalanceBefore = $customerWallet->balance;
        $gameBalanceBefore = $gameWallet->balance;

        $customerWallet->balance -= $amount;
        $customerWallet->save();

        $gameWallet->balance += $amount;
        $gameWallet->save();

        $customerEntry = $this->createEntry(
            entryType: 'game_bet',
            referenceable: $gameTransaction,
            wallet: $customerWallet,
            customerId: $customerWallet->customer_id,
            debit: $amount,
            credit: 0,
            balanceBefore: $customerBalanceBefore,
            balanceAfter: $customerWallet->balance,
            metadata: ['game_wallet_id' => $gameWallet->id]
        );

        $gameEntry = $this->createEntry(
            entryType: 'game_bet',
            referenceable: $gameTransaction,
            wallet: $gameWallet,
            customerId: null,
            debit: 0,
            credit: $amount,
            balanceBefore: $gameBalanceBefore,
            balanceAfter: $gameWallet->balance,
            metadata: ['customer_wallet_id' => $customerWallet->id]
        );

        return [$customerEntry, $gameEntry];
    }

    public function recordGameBetWithHouseCut($gameTransaction, Wallet $customerWallet, $gameWallet, float $amount, float $houseCut): array
    {
        $customerBalanceBefore = $customerWallet->balance;
        $gameBalanceBefore = $gameWallet->balance;
        $netCredit = $amount - $houseCut;

        $customerWallet->balance -= $amount;
        $customerWallet->save();

        $gameWallet->balance += $netCredit;
        $gameWallet->save();

        $customerEntry = $this->createEntry(
            entryType: 'game_bet',
            referenceable: $gameTransaction,
            wallet: $customerWallet,
            customerId: $customerWallet->customer_id,
            debit: $amount,
            credit: 0,
            balanceBefore: $customerBalanceBefore,
            balanceAfter: $customerWallet->balance,
            metadata: ['game_wallet_id' => $gameWallet->id, 'house_cut' => $houseCut]
        );

        $gameEntry = $this->createEntry(
            entryType: 'game_bet',
            referenceable: $gameTransaction,
            wallet: $gameWallet,
            customerId: null,
            debit: 0,
            credit: $netCredit,
            balanceBefore: $gameBalanceBefore,
            balanceAfter: $gameWallet->balance,
            metadata: ['customer_wallet_id' => $customerWallet->id, 'house_cut' => $houseCut]
        );

        return [$customerEntry, $gameEntry];
    }

    public function recordGamePayout($gameTransaction, Wallet $winnerWallet, float $amount): LedgerEntry
    {
        $balanceBefore = $winnerWallet->balance;
        $winnerWallet->balance += $amount;
        $winnerWallet->save();

        return $this->createEntry(
            entryType: 'game_payout',
            referenceable: $gameTransaction,
            wallet: $winnerWallet,
            customerId: $winnerWallet->customer_id,
            debit: 0,
            credit: $amount,
            balanceBefore: $balanceBefore,
            balanceAfter: $winnerWallet->balance
        );
    }

    public function recordCompetitionBet(
        CompetitionTransaction $ct,
        Wallet $wallet,
        CompetitionWallet $cw,
        float $amount,
        float $houseCut
    ): array {
        $walletBalanceBefore = $wallet->balance;
        $cwBalanceBefore = $cw->balance;

        $wallet->balance -= $amount;
        $wallet->save();

        $cw->balance += ($amount - $houseCut);
        $cw->save();

        $walletEntry = $this->createEntry(
            entryType: 'competition_bet',
            referenceable: $ct,
            wallet: $wallet,
            customerId: $wallet->customer_id,
            debit: $amount,
            credit: 0,
            balanceBefore: $walletBalanceBefore,
            balanceAfter: $wallet->balance,
            metadata: ['competition_wallet_id' => $cw->id, 'house_cut' => $houseCut]
        );

        $cwEntry = $this->createEntry(
            entryType: 'competition_bet',
            referenceable: $ct,
            wallet: $cw,
            customerId: null,
            debit: 0,
            credit: $amount - $houseCut,
            balanceBefore: $cwBalanceBefore,
            balanceAfter: $cw->balance,
            metadata: ['customer_wallet_id' => $wallet->id, 'house_cut' => $houseCut]
        );

        return [$walletEntry, $cwEntry];
    }

    public function recordCompetitionPayout(CompetitionTransaction $ct, Wallet $wallet, float $amount): LedgerEntry
    {
        $balanceBefore = $wallet->balance;
        $wallet->balance += $amount;
        $wallet->save();

        return $this->createEntry(
            entryType: 'competition_payout',
            referenceable: $ct,
            wallet: $wallet,
            customerId: $wallet->customer_id,
            debit: 0,
            credit: $amount,
            balanceBefore: $balanceBefore,
            balanceAfter: $wallet->balance
        );
    }

    public function recordWalletTransfer(WalletTransaction $wt, Wallet $sender, Wallet $receiver, float $amount): array
    {
        $senderBalanceBefore = $sender->balance;
        $receiverBalanceBefore = $receiver->balance;

        $sender->balance -= $amount;
        $sender->save();

        $receiver->balance += $amount;
        $receiver->save();

        $senderEntry = $this->createEntry(
            entryType: 'wallet_transfer',
            referenceable: $wt,
            wallet: $sender,
            customerId: $sender->customer_id,
            debit: $amount,
            credit: 0,
            balanceBefore: $senderBalanceBefore,
            balanceAfter: $sender->balance,
            metadata: ['direction' => 'outgoing', 'receiver_wallet_id' => $receiver->id]
        );

        $receiverEntry = $this->createEntry(
            entryType: 'wallet_transfer',
            referenceable: $wt,
            wallet: $receiver,
            customerId: $receiver->customer_id,
            debit: 0,
            credit: $amount,
            balanceBefore: $receiverBalanceBefore,
            balanceAfter: $receiver->balance,
            metadata: ['direction' => 'incoming', 'sender_wallet_id' => $sender->id]
        );

        return [$senderEntry, $receiverEntry];
    }

    public function recordCoinPurchase($coinBuy, Wallet $wallet, $coinWallet, float $amount, int $coins): array
    {
        $walletBalanceBefore = $wallet->balance;
        $coinBalanceBefore = $coinWallet->coins;

        $wallet->balance -= $amount;
        $wallet->save();

        $coinWallet->coins += $coins;
        $coinWallet->save();

        $walletEntry = $this->createEntry(
            entryType: 'coin_purchase',
            referenceable: $coinBuy,
            wallet: $wallet,
            customerId: $wallet->customer_id,
            debit: $amount,
            credit: 0,
            balanceBefore: $walletBalanceBefore,
            balanceAfter: $wallet->balance,
            metadata: ['coins_purchased' => $coins]
        );

        $coinEntry = $this->createEntry(
            entryType: 'coin_purchase',
            referenceable: $coinBuy,
            wallet: $coinWallet,
            customerId: null,
            debit: 0,
            credit: $coins,
            balanceBefore: $coinBalanceBefore,
            balanceAfter: $coinWallet->coins,
            metadata: ['amount_paid' => $amount]
        );

        return [$walletEntry, $coinEntry];
    }

    public function recordCoinExchange($coinExchange, Wallet $wallet, $coinWallet, float $amount, int $coins): array
    {
        $walletBalanceBefore = $wallet->balance;
        $coinBalanceBefore = $coinWallet->coins;

        $coinWallet->coins -= $coins;
        $coinWallet->save();

        $wallet->balance += $amount;
        $wallet->save();

        $walletEntry = $this->createEntry(
            entryType: 'coin_exchange',
            referenceable: $coinExchange,
            wallet: $wallet,
            customerId: $wallet->customer_id,
            debit: 0,
            credit: $amount,
            balanceBefore: $walletBalanceBefore,
            balanceAfter: $wallet->balance,
            metadata: ['coins_exchanged' => $coins]
        );

        $coinEntry = $this->createEntry(
            entryType: 'coin_exchange',
            referenceable: $coinExchange,
            wallet: $coinWallet,
            customerId: null,
            debit: $coins,
            credit: 0,
            balanceBefore: $coinBalanceBefore,
            balanceAfter: $coinWallet->coins,
            metadata: ['amount_credited' => $amount]
        );

        return [$walletEntry, $coinEntry];
    }

    public function recordCoinTransfer($coinTransfer, $senderCoinWallet, $receiverCoinWallet, int $coins): array
    {
        $senderBalanceBefore = $senderCoinWallet->coins;
        $receiverBalanceBefore = $receiverCoinWallet->coins;

        $senderCoinWallet->coins -= $coins;
        $senderCoinWallet->save();

        $receiverCoinWallet->coins += $coins;
        $receiverCoinWallet->save();

        $senderEntry = $this->createEntry(
            entryType: 'coin_transfer',
            referenceable: $coinTransfer,
            wallet: $senderCoinWallet,
            customerId: null,
            debit: $coins,
            credit: 0,
            balanceBefore: $senderBalanceBefore,
            balanceAfter: $senderCoinWallet->coins,
            metadata: ['direction' => 'outgoing', 'receiver_wallet_id' => $receiverCoinWallet->id]
        );

        $receiverEntry = $this->createEntry(
            entryType: 'coin_transfer',
            referenceable: $coinTransfer,
            wallet: $receiverCoinWallet,
            customerId: null,
            debit: 0,
            credit: $coins,
            balanceBefore: $receiverBalanceBefore,
            balanceAfter: $receiverCoinWallet->coins,
            metadata: ['direction' => 'incoming', 'sender_wallet_id' => $senderCoinWallet->id]
        );

        return [$senderEntry, $receiverEntry];
    }

    /**
     * @param  array<string, mixed>  $context  Extra metadata, e.g. game_wallet_id or competition_wallet_id.
     */
    public function recordHouseCut(
        Wallet $houseWallet,
        float $amount,
        string $source,
        ?Model $reference = null,
        array $context = []
    ): LedgerEntry {
        $balanceBefore = $houseWallet->balance;
        $houseWallet->balance += $amount;
        $houseWallet->save();

        return $this->createEntry(
            entryType: 'house_cut',
            referenceable: $reference,
            wallet: $houseWallet,
            customerId: $houseWallet->customer_id,
            debit: 0,
            credit: $amount,
            balanceBefore: $balanceBefore,
            balanceAfter: $houseWallet->balance,
            metadata: ['source' => $source] + $context
        );
    }

    public function recordRefund($reference, Wallet $wallet, float $amount, string $reason): LedgerEntry
    {
        $balanceBefore = $wallet->balance;
        $wallet->balance += $amount;
        $wallet->save();

        return $this->createEntry(
            entryType: 'refund',
            referenceable: $reference,
            wallet: $wallet,
            customerId: $wallet->customer_id,
            debit: 0,
            credit: $amount,
            balanceBefore: $balanceBefore,
            balanceAfter: $wallet->balance,
            metadata: ['reason' => $reason]
        );
    }

    /**
     * Record a manual balance change. A positive amount credits the wallet, a negative one debits it.
     * The reason and actor are kept in the entry metadata so manual movements can be audited.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordAdjustment(Wallet $wallet, float $signedAmount, string $reason, ?string $actor = null, array $context = []): ?LedgerEntry
    {
        if (round($signedAmount, 2) == 0.0) {
            return null;
        }

        $balanceBefore = $wallet->balance;
        $wallet->balance += $signedAmount;
        $wallet->save();

        return $this->createEntry(
            entryType: 'adjustment',
            referenceable: null,
            wallet: $wallet,
            customerId: $wallet->customer_id,
            debit: $signedAmount < 0 ? abs($signedAmount) : 0,
            credit: $signedAmount > 0 ? $signedAmount : 0,
            balanceBefore: $balanceBefore,
            balanceAfter: $wallet->balance,
            metadata: ['reason' => $reason, 'actor' => $actor] + $context
        );
    }

    /**
     * Debit a game or competition wallet when its balance leaves escrow (payout, refund).
     */
    public function recordEscrowRelease(Model $reference, GameWallet|CompetitionWallet $escrow, float $amount, string $reason): ?LedgerEntry
    {
        if ($amount <= 0) {
            return null;
        }

        $balanceBefore = $escrow->balance;
        $escrow->balance -= $amount;
        $escrow->save();

        return $this->createEntry(
            entryType: 'escrow_release',
            referenceable: $reference,
            wallet: $escrow,
            customerId: null,
            debit: $amount,
            credit: 0,
            balanceBefore: $balanceBefore,
            balanceAfter: $escrow->balance,
            metadata: ['reason' => $reason]
        );
    }

    /**
     * Move a balance between two competition wallets (round win/loss).
     *
     * @return array{0: LedgerEntry, 1: LedgerEntry}
     */
    public function recordEscrowTransfer(CompetitionTransaction $reference, CompetitionWallet $sender, CompetitionWallet $receiver, float $amount): array
    {
        $senderBalanceBefore = $sender->balance;
        $receiverBalanceBefore = $receiver->balance;

        $sender->balance -= $amount;
        $sender->save();

        $receiver->balance += $amount;
        $receiver->save();

        $senderEntry = $this->createEntry(
            entryType: 'escrow_transfer',
            referenceable: $reference,
            wallet: $sender,
            customerId: null,
            debit: $amount,
            credit: 0,
            balanceBefore: $senderBalanceBefore,
            balanceAfter: $sender->balance,
            metadata: ['direction' => 'outgoing', 'receiver_wallet_id' => $receiver->id]
        );

        $receiverEntry = $this->createEntry(
            entryType: 'escrow_transfer',
            referenceable: $reference,
            wallet: $receiver,
            customerId: null,
            debit: 0,
            credit: $amount,
            balanceBefore: $receiverBalanceBefore,
            balanceAfter: $receiver->balance,
            metadata: ['direction' => 'incoming', 'sender_wallet_id' => $sender->id]
        );

        return [$senderEntry, $receiverEntry];
    }

    /**
     * Move disputed money from a customer or competition wallet into dispute escrow.
     *
     * @return array{0: LedgerEntry, 1: LedgerEntry} [source entry, dispute escrow entry]
     */
    public function recordDisputeHold(DisputedTransaction $dispute, Wallet|CompetitionWallet $source, float $amount): array
    {
        $sourceBalanceBefore = $source->balance;
        $disputeBalanceBefore = $dispute->balance;

        $source->balance -= $amount;
        $source->save();

        $dispute->balance += $amount;
        $dispute->save();

        $metadata = ['complaint_id' => $dispute->complaint_id, 'disputed_transaction_id' => $dispute->id];

        $sourceEntry = $this->createEntry(
            entryType: 'dispute_hold',
            referenceable: $dispute,
            wallet: $source,
            customerId: $source instanceof Wallet ? $source->customer_id : null,
            debit: $amount,
            credit: 0,
            balanceBefore: $sourceBalanceBefore,
            balanceAfter: $source->balance,
            metadata: $metadata
        );

        $disputeEntry = $this->createEntry(
            entryType: 'dispute_hold',
            referenceable: $dispute,
            wallet: $dispute,
            customerId: null,
            debit: 0,
            credit: $amount,
            balanceBefore: $disputeBalanceBefore,
            balanceAfter: $dispute->balance,
            metadata: $metadata + ['source_wallet_type' => $dispute->source_wallet_type, 'source_wallet_id' => $dispute->source_wallet_id]
        );

        return [$sourceEntry, $disputeEntry];
    }

    /**
     * Give everything still held in dispute escrow back to the wallet it was taken from.
     *
     * @return array{0: LedgerEntry, 1: LedgerEntry}|null [dispute escrow entry, source entry], or null when nothing is held
     */
    public function recordDisputeRelease(DisputedTransaction $dispute, Wallet|CompetitionWallet $source): ?array
    {
        $amount = (float) $dispute->balance;

        if ($amount <= 0) {
            return null;
        }

        $disputeBalanceBefore = $dispute->balance;
        $sourceBalanceBefore = $source->balance;

        $dispute->balance -= $amount;
        $dispute->save();

        $source->balance += $amount;
        $source->save();

        $metadata = ['complaint_id' => $dispute->complaint_id, 'disputed_transaction_id' => $dispute->id];

        $disputeEntry = $this->createEntry(
            entryType: 'dispute_release',
            referenceable: $dispute,
            wallet: $dispute,
            customerId: null,
            debit: $amount,
            credit: 0,
            balanceBefore: $disputeBalanceBefore,
            balanceAfter: $dispute->balance,
            metadata: $metadata
        );

        $sourceEntry = $this->createEntry(
            entryType: 'dispute_release',
            referenceable: $dispute,
            wallet: $source,
            customerId: $source instanceof Wallet ? $source->customer_id : null,
            debit: 0,
            credit: $amount,
            balanceBefore: $sourceBalanceBefore,
            balanceAfter: $source->balance,
            metadata: $metadata
        );

        return [$disputeEntry, $sourceEntry];
    }

    /**
     * Take the money held for a disputed transaction out of dispute escrow to pay refunds. The matching
     * credits are the recordDisputeRefund() entries.
     */
    public function recordDisputeRefundFunding(DisputedTransaction $dispute): ?LedgerEntry
    {
        $amount = (float) $dispute->balance;

        if ($amount <= 0) {
            return null;
        }

        $balanceBefore = $dispute->balance;
        $dispute->balance -= $amount;
        $dispute->save();

        return $this->createEntry(
            entryType: 'dispute_refund',
            referenceable: $dispute,
            wallet: $dispute,
            customerId: null,
            debit: $amount,
            credit: 0,
            balanceBefore: $balanceBefore,
            balanceAfter: $dispute->balance,
            metadata: ['complaint_id' => $dispute->complaint_id, 'disputed_transaction_id' => $dispute->id]
        );
    }

    /**
     * Credit a player refunded by a resolved complaint.
     */
    public function recordDisputeRefund(Complaint $complaint, Wallet $wallet, float $amount): LedgerEntry
    {
        $balanceBefore = $wallet->balance;
        $wallet->balance += $amount;
        $wallet->save();

        return $this->createEntry(
            entryType: 'dispute_refund',
            referenceable: $complaint,
            wallet: $wallet,
            customerId: $wallet->customer_id,
            debit: 0,
            credit: $amount,
            balanceBefore: $balanceBefore,
            balanceAfter: $wallet->balance,
            metadata: ['complaint_id' => $complaint->id]
        );
    }

    /**
     * Take back a house cut so it can be refunded. The reversal keeps the original's reference and
     * source, so the income statement reduces the same revenue line on the day it is reversed.
     *
     * Pass the house wallet the caller locked so the balance changes on that instance, not on a plain re-read
     * that could return an older REPEATABLE READ snapshot.
     *
     * @throws \LogicException
     */
    public function reverseHouseCut(LedgerEntry $houseCut, Complaint $complaint, ?Wallet $lockedHouseWallet = null): LedgerEntry
    {
        if ($lockedHouseWallet && (int) $lockedHouseWallet->id !== (int) $houseCut->wallet_id) {
            throw new \LogicException("House cut {$houseCut->id} is on wallet {$houseCut->wallet_id}, not the locked wallet {$lockedHouseWallet->id}");
        }

        $houseWallet = $lockedHouseWallet ?? $houseCut->wallet;
        $amount = (float) $houseCut->credit;

        $balanceBefore = $houseWallet->balance;
        $houseWallet->balance -= $amount;
        $houseWallet->save();

        $houseCut->status = 'reversed';
        $houseCut->save();

        return $this->createEntry(
            entryType: 'house_cut_reversal',
            referenceable: $houseCut->referenceable,
            wallet: $houseWallet,
            customerId: $houseWallet->customer_id,
            debit: $amount,
            credit: 0,
            balanceBefore: $balanceBefore,
            balanceAfter: $houseWallet->balance,
            metadata: ($houseCut->metadata ?? []) + ['original_entry_id' => $houseCut->entry_id, 'complaint_id' => $complaint->id]
        );
    }

    /**
     * Credit a referrer's referral wallet with a milestone bonus. It is single-sided: nothing leaves the
     * house until a bonus is transferred out of the referral wallet, which is when it becomes an expense.
     */
    public function recordReferralBonus(Referral $referral, ReferralWallet $wallet, float $amount, string $milestone): LedgerEntry
    {
        $balanceBefore = $wallet->balance;
        $wallet->balance += $amount;
        $wallet->save();

        return $this->createEntry(
            entryType: 'referral_bonus',
            referenceable: $referral,
            wallet: $wallet,
            customerId: $wallet->customer_id,
            debit: 0,
            credit: $amount,
            balanceBefore: $balanceBefore,
            balanceAfter: $wallet->balance,
            metadata: ['milestone' => $milestone, 'referral_id' => $referral->id, 'referred_id' => $referral->referred_id]
        );
    }

    /**
     * Debit a referral wallet for a payout to M-Pesa. Single-sided: the cash leaves the referral shortcode.
     */
    public function recordReferralWithdrawal(ReferralWithdrawal $withdrawal, ReferralWallet $wallet, float $amount): LedgerEntry
    {
        $balanceBefore = $wallet->balance;
        $wallet->balance -= $amount;
        $wallet->save();

        return $this->createEntry(
            entryType: 'referral_withdrawal',
            referenceable: $withdrawal,
            wallet: $wallet,
            customerId: $wallet->customer_id,
            debit: $amount,
            credit: 0,
            balanceBefore: $balanceBefore,
            balanceAfter: $wallet->balance,
            metadata: ['referral_withdrawal_id' => $withdrawal->id]
        );
    }

    /**
     * Give a failed referral payout back to the referral wallet.
     */
    public function reverseReferralWithdrawal(LedgerEntry $entry, ReferralWallet $wallet): LedgerEntry
    {
        $amount = (float) $entry->debit;
        $balanceBefore = $wallet->balance;
        $wallet->balance += $amount;
        $wallet->save();

        $entry->status = 'reversed';
        $entry->save();

        return $this->createEntry(
            entryType: 'referral_withdrawal_reversal',
            referenceable: $entry->referenceable,
            wallet: $wallet,
            customerId: $wallet->customer_id,
            debit: 0,
            credit: $amount,
            balanceBefore: $balanceBefore,
            balanceAfter: $wallet->balance,
            metadata: ($entry->metadata ?? []) + ['original_entry_id' => $entry->entry_id]
        );
    }

    /**
     * Work out which wallet table an entry written before `wallet_type` existed points at.
     * Returns null when the entry cannot be classified with confidence.
     */
    public function inferWalletType(LedgerEntry $entry): ?string
    {
        $metadata = $entry->metadata ?? [];

        if (str_ends_with($entry->entry_type, '_reversal')) {
            $original = LedgerEntry::where('entry_id', $metadata['original_entry_id'] ?? '')->first();

            return $original?->wallet_type ?? ($original ? $this->inferWalletType($original) : null);
        }

        return match ($entry->entry_type) {
            'deposit', 'excise_duty', 'withdrawal', 'wallet_transfer', 'refund', 'house_cut',
            'game_payout', 'competition_payout', 'adjustment', 'house_funding' => LedgerEntry::WALLET_TYPE_WALLET,
            'game_bet' => match (true) {
                isset($metadata['customer_wallet_id']) => LedgerEntry::WALLET_TYPE_GAME,
                isset($metadata['game_wallet_id']) => LedgerEntry::WALLET_TYPE_WALLET,
                default => null,
            },
            'competition_bet' => match (true) {
                isset($metadata['customer_wallet_id']) => LedgerEntry::WALLET_TYPE_COMPETITION,
                isset($metadata['competition_wallet_id']) => LedgerEntry::WALLET_TYPE_WALLET,
                default => null,
            },
            'coin_purchase' => match (true) {
                isset($metadata['amount_paid']) => LedgerEntry::WALLET_TYPE_COIN,
                isset($metadata['coins_purchased']) => LedgerEntry::WALLET_TYPE_WALLET,
                default => null,
            },
            'coin_exchange' => match (true) {
                isset($metadata['amount_credited']) => LedgerEntry::WALLET_TYPE_COIN,
                isset($metadata['coins_exchanged']) => LedgerEntry::WALLET_TYPE_WALLET,
                default => null,
            },
            'coin_transfer' => LedgerEntry::WALLET_TYPE_COIN,
            'referral_bonus', 'referral_withdrawal' => LedgerEntry::WALLET_TYPE_REFERRAL,
            default => str_ends_with($entry->entry_type, '_settled') ? LedgerEntry::WALLET_TYPE_WALLET : null,
        };
    }

    private function walletTypeFor(Model $wallet): string
    {
        return match (true) {
            $wallet instanceof GameWallet => LedgerEntry::WALLET_TYPE_GAME,
            $wallet instanceof CompetitionWallet => LedgerEntry::WALLET_TYPE_COMPETITION,
            $wallet instanceof Coin => LedgerEntry::WALLET_TYPE_COIN,
            $wallet instanceof DisputedTransaction => LedgerEntry::WALLET_TYPE_DISPUTE,
            $wallet instanceof ReferralWallet => LedgerEntry::WALLET_TYPE_REFERRAL,
            $wallet instanceof BetShopWallet => LedgerEntry::WALLET_TYPE_BET_SHOP,
            $wallet instanceof ShopWithdrawal => LedgerEntry::WALLET_TYPE_SHOP_WITHDRAWAL,
            $wallet instanceof Wager => LedgerEntry::WALLET_TYPE_WAGER,
            default => LedgerEntry::WALLET_TYPE_WALLET,
        };
    }

    public function holdFunds(Wallet $wallet, float $amount, string $type, $reference = null): PendingBalance
    {
        return PendingBalance::create([
            'pending_id' => Uuid::uuid4()->toString(),
            'wallet_id' => $wallet->id,
            'customer_id' => $wallet->customer_id,
            'amount' => $amount,
            'type' => $type,
            'referenceable_type' => $reference ? get_class($reference) : null,
            'referenceable_id' => $reference?->id,
            'status' => 'holding',
            'expires_at' => now()->addHours(24),
        ]);
    }

    public function settleHold(PendingBalance $hold): LedgerEntry
    {
        $hold->status = 'settled';
        $hold->save();

        $wallet = $hold->wallet;
        $balanceBefore = $wallet->balance;
        $wallet->balance += $hold->amount;
        $wallet->save();

        return $this->createEntry(
            entryType: $hold->type.'_settled',
            referenceable: $hold->referenceable,
            wallet: $wallet,
            customerId: $wallet->customer_id,
            debit: 0,
            credit: $hold->amount,
            balanceBefore: $balanceBefore,
            balanceAfter: $wallet->balance,
            metadata: ['pending_id' => $hold->pending_id]
        );
    }

    /**
     * Move a cash wager's stake from the player's wallet into the wager's own escrow.
     *
     * @return array{0: LedgerEntry, 1: LedgerEntry} [player entry, wager entry]
     */
    public function recordWagerStake(Wager $wager, Wallet $playerWallet, float $amount): array
    {
        return $this->move('wager_stake', $wager, $playerWallet, $wager, $amount);
    }

    /**
     * Release a settled wager's stake from its escrow to the house wallet.
     *
     * @return array{0: LedgerEntry, 1: LedgerEntry} [wager entry, house entry]
     */
    public function recordWagerStakeSettled(WagerSettlement $settlement, Wager $wager, Wallet $houseWallet, float $amount): array
    {
        return $this->move('wager_stake_settled', $settlement, $wager, $houseWallet, $amount);
    }

    /**
     * Pay a winning cash wager's gross payout from the house wallet to the player.
     *
     * @return array{0: LedgerEntry, 1: LedgerEntry} [house entry, player entry]
     */
    public function recordWagerPayout(WagerSettlement $settlement, Wallet $houseWallet, Wallet $playerWallet, float $amount): array
    {
        return $this->move('wager_payout', $settlement, $houseWallet, $playerWallet, $amount);
    }

    /**
     * Pay a winning free bet from the house wallet to the player. The voucher itself never touched the wallet.
     *
     * @return array{0: LedgerEntry, 1: LedgerEntry} [house entry, player entry]
     */
    public function recordWagerFreeBetPayout(WagerSettlement $settlement, Wallet $houseWallet, Wallet $playerWallet, float $amount): array
    {
        return $this->move('wager_free_bet_payout', $settlement, $houseWallet, $playerWallet, $amount);
    }

    /**
     * Pay a settlement's bonus from the house wallet to the player.
     *
     * @return array{0: LedgerEntry, 1: LedgerEntry} [house entry, player entry]
     */
    public function recordWagerBonus(WagerSettlement $settlement, Wallet $houseWallet, Wallet $playerWallet, float $amount): array
    {
        return $this->move('wager_bonus', $settlement, $houseWallet, $playerWallet, $amount);
    }

    /**
     * Return a voided cash wager's stake from its escrow to the player.
     *
     * @return array{0: LedgerEntry, 1: LedgerEntry} [wager entry, player entry]
     */
    public function recordWagerRefund(WagerSettlement $settlement, Wager $wager, Wallet $playerWallet, float $amount): array
    {
        return $this->move('wager_refund', $settlement, $wager, $playerWallet, $amount);
    }

    /**
     * Withhold winnings tax from the player's wallet. Single-sided like excise duty: the tax is owed to the
     * tax authority, outside the ledger's wallets.
     */
    public function recordWinningsTax(WagerSettlement $settlement, Wallet $playerWallet, float $amount): LedgerEntry
    {
        $balanceBefore = $playerWallet->balance;
        $playerWallet->balance -= $amount;
        $playerWallet->save();

        return $this->createEntry(
            entryType: 'winnings_tax',
            referenceable: $settlement,
            wallet: $playerWallet,
            customerId: $playerWallet->customer_id,
            debit: $amount,
            credit: 0,
            balanceBefore: $balanceBefore,
            balanceAfter: $playerWallet->balance,
            metadata: ['wager_id' => $settlement->wager_id, 'tax_base' => (float) $settlement->tax_base, 'rate' => (float) $settlement->tax_rate]
        );
    }

    /**
     * Post the exact negation of a settlement's legs: tax back, bonus back, payout back, then the stake back
     * to escrow (from the house for a loss or win, from the player for a void). The player's wallet may go
     * below zero; the caller holds the locks and has already decided the reversal is allowed.
     *
     * Every balance is changed on the instance the caller's locking read returned, never on a fresh read: under
     * REPEATABLE READ a plain SELECT returns the transaction's snapshot, which can predate a balance another
     * transaction committed while this one waited for the lock, and saving from it would lose that update.
     * A leg on a wallet the caller did not pass (so did not lock) is refused before anything moves.
     *
     * @return list<LedgerEntry> the reversal entries, in posting order
     *
     * @throws \LogicException
     */
    public function reverseWagerEntries(WagerSettlement $settlement, Wager $wager, ?Wallet $playerWallet, ?Wallet $houseWallet): array
    {
        $order = ['winnings_tax', 'wager_bonus', 'wager_payout', 'wager_free_bet_payout', 'wager_stake_settled', 'wager_refund'];

        $entries = LedgerEntry::where('referenceable_type', WagerSettlement::class)
            ->where('referenceable_id', $settlement->id)
            ->where('status', 'settled')
            ->whereNotIn('entry_type', array_map(fn (string $type) => $type.'_reversal', $order))
            ->orderBy('id')
            ->get()
            ->sortBy(fn (LedgerEntry $entry) => array_search($entry->entry_type, $order, true))
            ->values();

        $locked = array_values(array_filter([$playerWallet, $houseWallet]));
        $legs = $entries->map(fn (LedgerEntry $entry) => [$entry, $this->lockedWagerLegWallet($entry, $wager, $locked)]);

        return $legs->map(fn (array $leg) => $this->reverseWagerEntry($leg[0], $leg[1]))->all();
    }

    /**
     * The caller's locked instance that holds an entry's balance.
     *
     * @param  list<Wallet>  $locked
     *
     * @throws \LogicException
     */
    private function lockedWagerLegWallet(LedgerEntry $entry, Wager $wager, array $locked): Wager|Wallet
    {
        if ($entry->wallet_type === LedgerEntry::WALLET_TYPE_WAGER) {
            if ((int) $entry->wallet_id !== (int) $wager->id) {
                throw new \LogicException("Wager entry {$entry->id} belongs to wager {$entry->wallet_id}, not the locked wager {$wager->id}");
            }

            return $wager;
        }

        foreach ($locked as $wallet) {
            if ((int) $wallet->id === (int) $entry->wallet_id) {
                return $wallet;
            }
        }

        throw new \LogicException("Wager entry {$entry->id} is on wallet {$entry->wallet_id}, which the caller did not lock");
    }

    private function reverseWagerEntry(LedgerEntry $entry, Wager|Wallet $wallet): LedgerEntry
    {
        $balanceBefore = $wallet->balance;
        $wallet->balance += (float) $entry->debit - (float) $entry->credit;
        $wallet->save();

        $entry->status = 'reversed';
        $entry->save();

        return $this->createEntry(
            entryType: $entry->entry_type.'_reversal',
            referenceable: $entry->referenceable,
            wallet: $wallet,
            customerId: $entry->customer_id,
            debit: $entry->credit,
            credit: $entry->debit,
            balanceBefore: $balanceBefore,
            balanceAfter: $wallet->balance,
            metadata: ['original_entry_id' => $entry->entry_id]
        );
    }

    public function reverseEntry(LedgerEntry $entry): LedgerEntry
    {
        $wallet = $entry->wallet;
        $balanceBefore = $wallet->balance;

        if ($entry->credit > 0) {
            $wallet->balance -= $entry->credit;
        } else {
            $wallet->balance += $entry->debit;
        }
        $wallet->save();

        $entry->status = 'reversed';
        $entry->save();

        return $this->createEntry(
            entryType: $entry->entry_type.'_reversal',
            referenceable: $entry->referenceable,
            wallet: $wallet,
            customerId: $wallet->customer_id,
            debit: $entry->credit,
            credit: $entry->debit,
            balanceBefore: $balanceBefore,
            balanceAfter: $wallet->balance,
            metadata: ['original_entry_id' => $entry->entry_id]
        );
    }

    /**
     * Return the money of a withdrawal that M-Pesa rejected or failed to pay out.
     * Safe to call more than once: only a still-settled withdrawal entry is reversed.
     */
    public function reverseWithdrawal(Withdraw $withdraw): ?LedgerEntry
    {
        return DB::transaction(function () use ($withdraw) {
            $entry = LedgerEntry::where('entry_type', 'withdrawal')
                ->where('referenceable_type', Withdraw::class)
                ->where('referenceable_id', $withdraw->id)
                ->where('status', 'settled')
                ->lockForUpdate()
                ->first();

            if (! $entry) {
                return null;
            }

            Wallet::lockForUpdate()->find($entry->wallet_id);

            return $this->reverseEntry($entry);
        });
    }

    public function getBalance(Wallet $wallet): array
    {
        $walletEntries = fn () => LedgerEntry::where('wallet_type', LedgerEntry::WALLET_TYPE_WALLET)
            ->where('wallet_id', $wallet->id)
            ->countable();

        $settled = $walletEntries()->sum('credit') - $walletEntries()->sum('debit');

        $pending = PendingBalance::where('wallet_id', $wallet->id)
            ->where('status', 'holding')
            ->sum('amount');

        return [
            'settled' => (float) $settled,
            'pending' => (float) $pending,
            'available' => (float) ($settled - $pending),
        ];
    }

    public function getStatement(Wallet $wallet, $from, $to): Collection
    {
        return LedgerEntry::where('wallet_type', LedgerEntry::WALLET_TYPE_WALLET)
            ->where('wallet_id', $wallet->id)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at', 'asc')
            ->get();
    }

    private function createEntry(
        string $entryType,
        $referenceable,
        $wallet,
        $customerId,
        float $debit,
        float $credit,
        float $balanceBefore,
        float $balanceAfter,
        ?array $metadata = null
    ): LedgerEntry {
        return LedgerEntry::create([
            'entry_id' => Uuid::uuid4()->toString(),
            'entry_type' => $entryType,
            'referenceable_type' => $referenceable ? get_class($referenceable) : null,
            'referenceable_id' => $referenceable?->id,
            'wallet_type' => $this->walletTypeFor($wallet),
            'wallet_id' => $wallet->id,
            'customer_id' => $customerId,
            'product_id' => $this->products->forPosting($referenceable, $wallet, $metadata ?? []),
            'debit' => $debit,
            'credit' => $credit,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'status' => 'settled',
            'metadata' => $metadata,
        ]);
    }
}
