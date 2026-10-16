<?php

namespace App\Http\Resources;

use App\Models\BetShopLoad;
use App\Models\Deposit;
use App\Models\ShopWithdrawal;
use App\Models\Withdraw;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $customerArr = [
            'id' => $this->id,
            'google_id' => $this->google_id,
            'account_no' => $this->account_no,
            'player_no' => $this->player_no,
            'referral_code' => $this->referral_code,
            'name' => $this->name,
            'id_no' => $this->id_no,
            'phone_no' => $this->phone_no,
            'email' => $this->email,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];

        $wallet = $this->wallet;
        if ($wallet) {
            $customerArr = array_merge($customerArr, [
                'wallet_id' => $wallet->id,
                'balance' => $wallet->balance,
            ]);

            $walletTransactions = $wallet->transactions;
            $customerArr = array_merge($customerArr, [
                'deposits' => $walletTransactions->where('payment_type', Deposit::class)->sum('amount'),
                'shop_loads' => $walletTransactions->where('payment_type', BetShopLoad::class)->where('status', 2)->sum('amount'),
                'withdraws' => $walletTransactions->where('payment_type', Withdraw::class)->sum('amount'),
                'shop_withdrawals' => $walletTransactions->where('payment_type', ShopWithdrawal::class)->where('status', 2)->sum('amount'),
            ]);
        }

        $purchases = $this->purchases;
        if ($purchases) {
            $customerArr = array_merge($customerArr, [
                'purchases_load' => $purchases->where('purchase_type', 'load')->sum('amount'),
                'purchases_gift' => $purchases->where('purchase_type', 'gift')->sum('amount'),
                'purchases_emojis' => $purchases->where('purchase_type', 'emoji')->sum('amount'),
            ]);
        }

        $singlePlayed = $this->gameWalletTransactions;
        if ($singlePlayed) {
            $customerArr = array_merge($customerArr, [
                'single_played' => $singlePlayed->where('payment_type', 'deposit')->count(),
            ]);
        }

        $competitionPlayed = $this->allCompetitionTransactions;
        if ($competitionPlayed) {
            $customerArr = array_merge($customerArr, [
                'competition_played' => $competitionPlayed->where('payment_type', '!=', 'payout')->count(),
            ]);
        }

        $coin = $this->coin;
        if ($coin) {
            $customerArr = array_merge($customerArr, [
                'coin_wallet_id' => $coin->id,
                'coins' => $coin->coins,
            ]);
        }

        return $customerArr;
    }
}
