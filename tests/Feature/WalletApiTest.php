<?php

use App\Models\Customer;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->apiKey = createApiKey('test-wallet-key');
});

it('lists wallets', function () {
    $response = $this->getJson('/api/v1/wallets', apiHeaders($this->apiKey->key));

    $response->assertStatus(200);
});

it('shows a specific wallet', function () {
    $customer = Customer::factory()->create(['id_no' => 'WLT001']);
    $wallet = Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 100]);
    $encryptedId = encryptId($wallet->id);

    $response = $this->getJson("/api/v1/wallets/{$encryptedId}", apiHeaders($this->apiKey->key));

    $response->assertStatus(200);
});

it('returns 404 for nonexistent wallet', function () {
    $encryptedId = encryptId(9999);

    $response = $this->getJson("/api/v1/wallets/{$encryptedId}", apiHeaders($this->apiKey->key));

    $response->assertStatus(404);
});

it('updates wallet balance', function () {
    $customer = Customer::factory()->create(['id_no' => 'WLT002']);
    $wallet = Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 100]);
    $encryptedId = encryptId($wallet->id);

    $response = $this->putJson("/api/v1/wallets/{$encryptedId}/balance", [
        'amount' => 200,
    ], apiHeaders($this->apiKey->key));

    $response->assertStatus(201);
    $response->assertJson(['status' => 'success']);
});

it('validates balance amount minimum', function () {
    $customer = Customer::factory()->create(['id_no' => 'WLT003']);
    $wallet = Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 100]);
    $encryptedId = encryptId($wallet->id);

    $response = $this->putJson("/api/v1/wallets/{$encryptedId}/balance", [
        'amount' => -0.01,
    ], apiHeaders($this->apiKey->key));

    $response->assertStatus(422);
    expect((float) $wallet->fresh()->balance)->toBe(100.0);
});

it('withdraws from wallet', function () {
    $customer = Customer::factory()->create(['id_no' => 'WLT004']);
    $wallet = Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 100]);
    $encryptedId = encryptId($wallet->id);

    $response = $this->putJson("/api/v1/wallets/{$encryptedId}/withdraw", [
        'amount' => 50,
    ], apiHeaders($this->apiKey->key));

    $response->assertStatus(201);
    $response->assertJson(['status' => 'success']);
});

it('rejects withdrawal exceeding balance', function () {
    $customer = Customer::factory()->create(['id_no' => 'WLT005']);
    $wallet = Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 100]);
    $encryptedId = encryptId($wallet->id);

    $response = $this->putJson("/api/v1/wallets/{$encryptedId}/withdraw", [
        'amount' => 500,
    ], apiHeaders($this->apiKey->key));

    $response->assertStatus(400);
});

it('deposits to wallet', function () {
    $customer = Customer::factory()->create(['id_no' => 'WLT006']);
    $wallet = Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 100]);
    $encryptedId = encryptId($wallet->id);

    $response = $this->putJson("/api/v1/wallets/{$encryptedId}/deposit", [
        'amount' => 50,
    ], apiHeaders($this->apiKey->key));

    $response->assertStatus(201);
    $response->assertJson(['status' => 'success']);
});
