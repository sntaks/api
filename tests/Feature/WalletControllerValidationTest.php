<?php

use App\Models\Customer;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->walletService = app(WalletService::class);
});

it('leaves the balance unchanged when reducing by zero at the service level', function () {
    $customer = Customer::factory()->create();
    $wallet = Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 100]);

    $result = $this->walletService->reduceBalance($wallet->id, 0);

    expect($result['success'])->toBeTrue();
    expect((float) $result['balance_after'])->toBe(100.0);
});

it('stores a negative balance at the service level, so the controller validation is the guard', function () {
    $customer = Customer::factory()->create();
    $wallet = Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 100]);

    $result = $this->walletService->setBalance($wallet->id, -50);

    expect($result['success'])->toBeTrue();
    expect((float) $wallet->fresh()->balance)->toBe(-50.0);
});

it('reduce balance succeeds with positive amount', function () {
    $customer = Customer::factory()->create();
    $wallet = Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 100]);

    $result = $this->walletService->reduceBalance($wallet->id, 50);

    expect($result['success'])->toBeTrue();
    expect((float) $result['balance_before'])->toBe(100.0);
    expect((float) $result['balance_after'])->toBe(50.0);
});
