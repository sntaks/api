<?php

use App\Models\Customer;
use App\Models\Deposit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->apiKey = createApiKey('test-deposit-key');
});

it('lists deposits', function () {
    $response = $this->getJson('/api/v1/deposits', apiHeaders($this->apiKey->key));

    $response->assertStatus(200);
});

it('creates a deposit via C2B confirmation', function () {
    $customer = Customer::factory()->create(['account_no' => 'DEP001']);

    $payload = [
        'TransID' => 'MPESA_DEP_TEST_001',
        'TransactionType' => 'Pay Bill',
        'TransTime' => now()->toDateTimeString(),
        'TransAmount' => 500,
        'BusinessShortCode' => '12345',
        'BillRefNumber' => 'DEP001',
        'MSISDN' => '254712345678',
        'FirstName' => 'John',
    ];

    $response = $this->postJson('/api/v1/deposits', $payload, apiHeaders($this->apiKey->key));

    $response->assertStatus(201);
    $response->assertJsonStructure(['message', 'wallet', 'transaction']);
});

it('rejects deposit with missing required fields', function () {
    $response = $this->postJson('/api/v1/deposits', [], apiHeaders($this->apiKey->key));

    $response->assertStatus(422);
});

it('shows a specific deposit', function () {
    $customer = Customer::factory()->create(['id_no' => 'DEP_SHOW_001']);
    $deposit = Deposit::create([
        'trans_id' => 'MPESA_DEP_SHOW_001',
        'trans_type' => 'Pay Bill',
        'trans_time' => now()->toDateTimeString(),
        'trans_amount' => 500,
        'short_code' => '12345',
        'bill_ref_no' => 'DEP_SHOW_001',
        'msisdn' => '254712345678',
        'name' => 'John',
    ]);
    $encryptedId = encryptId($deposit->id);

    $response = $this->getJson("/api/v1/deposits/{$encryptedId}", apiHeaders($this->apiKey->key));

    $response->assertStatus(200);
});

it('returns 404 for nonexistent deposit', function () {
    $encryptedId = encryptId(9999);

    $response = $this->getJson("/api/v1/deposits/{$encryptedId}", apiHeaders($this->apiKey->key));

    $response->assertStatus(404);
});
