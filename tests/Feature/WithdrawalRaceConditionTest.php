<?php

use App\Models\Customer;
use App\Models\Wallet;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('withdrawal service locks balance for update', function () {
    $customer = Customer::factory()->phoneVerified()->create();
    $wallet = Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 100]);

    $service = app(WithdrawalService::class);

    $result = $service->initiateWithdrawal($customer->id, 150);

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toBe('Insufficient wallet balance for withdrawal');
    expect((float) $wallet->fresh()->balance)->toBe(100.0);
});
