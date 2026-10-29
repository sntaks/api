<?php

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects duplicate deposit with same trans_id', function () {
    $customer = Customer::factory()->create(['account_no' => 'CUST001']);
    Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 0]);

    $depositData = [
        'TransID' => 'MPESA_DUPLICATE_001',
        'TransactionType' => 'Pay Bill',
        'TransTime' => now()->toDateTimeString(),
        'TransAmount' => 500,
        'BusinessShortCode' => '12345',
        'BillRefNumber' => 'CUST001',
        'MSISDN' => '254712345678',
        'FirstName' => 'John',
    ];

    $apiKey = ApiKey::create(['key' => 'test-api-key-deposit', 'name' => 'Test', 'is_active' => true]);

    // First deposit should succeed
    $response = $this->postJson('/api/v1/deposits', $depositData, ['X-API-KEY' => $apiKey->key]);
    $response->assertStatus(201);

    // Second deposit with same trans_id returns cached response from idempotency middleware
    $response = $this->postJson('/api/v1/deposits', $depositData, ['X-API-KEY' => $apiKey->key]);
    $response->assertStatus(201);

    // Only one deposit record should exist
    expect(Deposit::where('trans_id', 'MPESA_DUPLICATE_001')->count())->toBe(1);
});

it('processes unique deposits with different trans_ids', function () {
    $customer = Customer::factory()->create(['account_no' => 'CUST002']);
    Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 0]);

    $apiKey = ApiKey::create(['key' => 'test-api-key-deposit2', 'name' => 'Test', 'is_active' => true]);

    $deposit1 = [
        'TransID' => 'MPESA_UNIQUE_001',
        'TransactionType' => 'Pay Bill',
        'TransTime' => now()->toDateTimeString(),
        'TransAmount' => 500,
        'BusinessShortCode' => '12345',
        'BillRefNumber' => 'CUST002',
        'MSISDN' => '254712345678',
        'FirstName' => 'John',
    ];

    $deposit2 = [
        'TransID' => 'MPESA_UNIQUE_002',
        'TransactionType' => 'Pay Bill',
        'TransTime' => now()->toDateTimeString(),
        'TransAmount' => 300,
        'BusinessShortCode' => '12345',
        'BillRefNumber' => 'CUST002',
        'MSISDN' => '254712345678',
        'FirstName' => 'John',
    ];

    $response1 = $this->postJson('/api/v1/deposits', $deposit1, ['X-API-KEY' => $apiKey->key]);
    $response1->assertStatus(201);

    $response2 = $this->postJson('/api/v1/deposits', $deposit2, ['X-API-KEY' => $apiKey->key]);
    $response2->assertStatus(201);

    expect(Deposit::where('bill_ref_no', 'CUST002')->count())->toBe(2);
    expect((float) Wallet::where('customer_id', $customer->id)->first()->balance)->toBe(800.0);
});
