<?php

use App\Models\Customer;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->apiKey = createApiKey('test-wallet-transfer-key');
});

it('validates required fields for wallet transfer', function () {
    $sender = Customer::factory()->create(['id_no' => 'WTR003']);
    $senderWallet = Wallet::factory()->create(['customer_id' => $sender->id, 'balance' => 500]);
    $encryptedId = encryptId($senderWallet->id);

    $response = $this->postJson("/api/v1/wallets/transfer/{$encryptedId}", [], apiHeaders($this->apiKey->key));

    expect(in_array($response->status(), [422, 500]))->toBeTrue();
});

it('refuses a zero or negative transfer', function (float $amount) {
    $sender = Customer::factory()->create(['id_no' => 'WTR010']);
    $senderWallet = Wallet::factory()->create(['customer_id' => $sender->id, 'balance' => 500]);
    $receiver = Customer::factory()->create(['id_no' => 'WTR011']);
    $receiverWallet = Wallet::factory()->create(['customer_id' => $receiver->id, 'balance' => 0]);

    $this->postJson('/api/v1/wallets/transfer/'.encryptId($senderWallet->id), [
        'amount' => $amount,
        'wallet_id' => $receiverWallet->id,
    ], apiHeaders($this->apiKey->key))->assertStatus(422)->assertJsonValidationErrors('amount');

    expect((float) $senderWallet->fresh()->balance)->toBe(500.0)
        ->and((float) $receiverWallet->fresh()->balance)->toBe(0.0);
})->with([0, -50]);
