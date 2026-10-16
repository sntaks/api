<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class C2BConfirmationService
{
    public function __construct(
        private LedgerService $ledgerService,
        private WalletDepositService $walletDepositService,
        private HouseFundingService $houseFunding,
        private BetShopFloatService $shopFloat,
    ) {}

    public function processCallback(array $depositData): array
    {
        $transId = $depositData['trans_id'];

        $existingDeposit = Deposit::where('trans_id', $transId)->first();
        if ($existingDeposit) {
            Log::channel('mpesa')->info('MPESA Confirmation Duplicate Skipped', ['trans_id' => $transId]);

            return [
                'ResultCode' => '0',
                'ResultDesc' => 'Already processed',
                'status' => 200,
            ];
        }

        // One transaction, so a crash part way never leaves a deposit recorded without its wallet credit and excise duty.
        return DB::transaction(function () use ($depositData) {
            $deposit = Deposit::create($depositData);

            $billRefParts = explode('#', $deposit->bill_ref_no);
            $rawAccountNo = $billRefParts[0];
            $coinValue = $billRefParts[1] ?? null;
            $type = $billRefParts[2] ?? null;
            $referralCode = $billRefParts[3] ?? null;

            $normalizedAccountNo = $this->walletDepositService->normalizeAccountNo($rawAccountNo);

            $customer = Customer::where('account_no', $normalizedAccountNo)->first();

            // A shop owner buying float (bill ref SHOP-<shop_no>): credited only when automatic crediting is on.
            if (! $customer && $this->shopFloat->creditFromCallback($deposit)) {
                return [
                    'ResultCode' => '0',
                    'ResultDesc' => 'Accepted',
                    'status' => 200,
                ];
            }

            // The money has arrived either way: it is kept as unmatched and resolved through /deposits/unmatched.
            if (! $customer) {
                $deposit->update(['status' => Deposit::STATUS_UNMATCHED]);
                Log::channel('mpesa')->error('MPESA Confirmation Received [Invalid Customer]:', $depositData);

                return [
                    'ResultCode' => '0',
                    'ResultDesc' => 'Accepted',
                    'status' => 200,
                ];
            }

            // Money paid to the house account is owner funding, not a deposit: finance records it as house funding.
            if ((int) $customer->id === $this->houseFunding->houseCustomerId()) {
                $deposit->update(['status' => Deposit::STATUS_UNMATCHED]);
                Log::channel('mpesa')->warning('MPESA Confirmation to the house account left unmatched for house funding', ['trans_id' => $deposit->trans_id]);

                return [
                    'ResultCode' => '0',
                    'ResultDesc' => 'Accepted',
                    'status' => 201,
                ];
            }

            $this->processByType($type, $coinValue, $deposit, $customer, $referralCode);

            $deposit->update(['status' => 2]);

            return [
                'ResultCode' => '0',
                'ResultDesc' => 'Accepted',
                'status' => 201,
            ];
        });
    }

    private function processByType(
        ?string $type,
        ?string $coinValue,
        Deposit $deposit,
        Customer $customer,
        ?string $referralCode
    ): void {
        if ($type === 'load') {
            $this->processLoad($coinValue, $deposit, $customer, $referralCode);
        } elseif ($type === 'gift' || $type === 'emoji') {
            $this->processGiftOrEmoji($type, $coinValue, $deposit, $customer, $referralCode);
        } else {
            $this->processDefault($deposit, $customer);
        }
    }

    private function processLoad(
        ?string $coinValue,
        Deposit $deposit,
        Customer $customer,
        ?string $referralCode
    ): void {
        $wallet = Wallet::firstOrCreate(
            ['customer_id' => $customer->id],
            ['balance' => 0]
        );

        $amountToAdd = (float) $coinValue;

        $ledgerEntry = $this->ledgerService->recordDeposit($deposit, $wallet, $amountToAdd);

        $wallet->transactions()->create([
            'payment_id' => $deposit->id,
            'payment_ref' => $deposit->trans_id,
            'payment_type' => Deposit::class,
            'amount' => $amountToAdd,
            'status' => 2,
            'balance_before' => $ledgerEntry->balance_before,
            'balance_after' => $ledgerEntry->balance_after,
        ]);

        $customer->purchases()->create([
            'deposit_id' => $deposit->id,
            'purchase_type' => 'load',
            'amount' => $deposit->trans_amount,
            'value' => $amountToAdd,
            'referral_code' => $referralCode,
        ]);
    }

    private function processGiftOrEmoji(
        string $type,
        ?string $coinValue,
        Deposit $deposit,
        Customer $customer,
        ?string $referralCode
    ): void {
        $amountToAdd = (float) $coinValue;

        $customer->purchases()->create([
            'deposit_id' => $deposit->id,
            'purchase_type' => $type,
            'amount' => $deposit->trans_amount,
            'value' => $amountToAdd,
            'referral_code' => $referralCode,
        ]);
    }

    private function processDefault(Deposit $deposit, Customer $customer): void
    {
        $this->walletDepositService->credit($deposit, $customer);
    }
}
