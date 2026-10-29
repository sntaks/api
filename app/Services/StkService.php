<?php

namespace App\Services;

use App\Models\Customer;
use App\Util\PlayerNumber;
use Illuminate\Support\Facades\Log;

class StkService
{
    public function __construct(private MpesaService $mpesaService) {}

    /**
     * Prompt the customer's phone for a wallet top-up. $identifier is an id, account number or player number.
     * The M-Pesa account reference is the player number: 8 characters, check-digit protected and never
     * edited, so the confirmation always matches back (DepositAccountResolver).
     */
    public function initiateStkDeposit(string $identifier, float $amount): array
    {
        $playerNo = PlayerNumber::normalize($identifier);

        $customer = $playerNo !== null
            ? Customer::where('player_no', $playerNo)->first()
            : Customer::where('id', $identifier)->orWhere('account_no', $identifier)->first();

        if (! $customer) {
            throw new \InvalidArgumentException('Customer not found');
        }

        $params = [
            'Amount' => intval($amount),
            'AccountReference' => $customer->player_no,
            'PartyA' => $customer->phone_no,
            'PhoneNumber' => $customer->phone_no,
        ];

        $response = $this->mpesaService->stkPush($params);
        Log::channel('mpesa')->info('MPESA StkPush Request Response', $response);

        return ['status' => 'success'];
    }

    public function initiateStkLoad(
        string $identifier,
        float $amount,
        string $type,
        ?string $phoneNo = null,
        ?string $referralCode = null,
    ): array {
        $customer = Customer::where('id', $identifier)->orWhere('account_no', $identifier)->first();

        if (! $customer) {
            throw new \InvalidArgumentException('Customer not found');
        }

        $billRefNo = match ($type) {
            'gift' => $customer->account_no.'#'.$amount.'#gift',
            'emoji' => $customer->account_no.'#'.$amount.'#emoji',
            default => throw new \InvalidArgumentException("Unknown load type: {$type}"),
        };

        if ($referralCode) {
            $billRefNo .= '#'.$referralCode;
        } elseif (! empty($customer->referral_code)) {
            $billRefNo .= '#'.$customer->referral_code;
        }

        $params = [
            'Amount' => (float) $amount,
            'AccountReference' => $billRefNo,
            'PartyA' => $phoneNo ?? $customer->phone_no,
            'PhoneNumber' => $phoneNo ?? $customer->phone_no,
        ];

        $response = $this->mpesaService->stkPush($params);
        Log::channel('mpesa')->info('MPESA StkPush Load Request Response', $response);

        return ['status' => 'success'];
    }
}
