<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Deposit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class C2BConfirmationService
{
    public function __construct(
        private WalletDepositService $walletDepositService,
        private HouseFundingService $houseFunding,
        private BetShopFloatService $shopFloat,
        private DepositAccountResolver $resolver,
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

            $billRefParts = explode('#', (string) $deposit->bill_ref_no);
            $coinValue = $billRefParts[1] ?? null;
            $type = $billRefParts[2] ?? null;
            $referralCode = $billRefParts[3] ?? null;

            // The bill ref may be an account number, a player number or a verified phone (DepositAccountResolver).
            $match = $this->resolver->resolve($deposit->bill_ref_no, $deposit->msisdn);
            $customer = $match->customer;

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
                $deposit->update(['status' => Deposit::STATUS_UNMATCHED, 'unmatched_reason' => $match->unmatchedReason]);
                Log::channel('mpesa')->error('MPESA Confirmation Received [Invalid Customer]:', $depositData + ['unmatched_reason' => $match->unmatchedReason]);

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

            $deposit->update(['status' => Deposit::STATUS_COMPLETED, 'matched_by' => $match->matchedBy]);

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
        // A `#load` bill ref is an ordinary deposit: the wallet gets the amount paid, never a value the payer typed.
        if ($type === 'gift' || $type === 'emoji') {
            $this->processGiftOrEmoji($type, $coinValue, $deposit, $customer, $referralCode);
        } else {
            $this->processDefault($deposit, $customer);
        }
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
