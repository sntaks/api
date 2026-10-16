<?php

use App\Models\ApiKey;
use App\Models\IdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('different api keys generate different request hashes', function () {
    $apiKey1 = ApiKey::create(['key' => 'test-key-idem-1', 'name' => 'Test 1', 'is_active' => true]);
    $apiKey2 = ApiKey::create(['key' => 'test-key-idem-2', 'name' => 'Test 2', 'is_active' => true]);

    $payload = [
        'name' => 'Test Customer',
        'phone_no' => '254712345678',
        'id_no' => 'ID12345',
        'email' => 'test1@example.com',
        'account_no' => 'ACC001',
    ];

    $hash1 = hash('sha256', json_encode($payload).'store@v1/customers'.$apiKey1->key);
    $hash2 = hash('sha256', json_encode($payload).'store@v1/customers'.$apiKey2->key);

    expect($hash1)->not->toBe($hash2);
});

it('same api key same body generates same request hash', function () {
    $apiKey = ApiKey::create(['key' => 'test-key-idem-3', 'name' => 'Test 3', 'is_active' => true]);

    $payload = [
        'name' => 'Test Customer',
        'phone_no' => '254712345678',
        'id_no' => 'ID12345',
        'email' => 'test1@example.com',
        'account_no' => 'ACC001',
    ];

    $hash1 = hash('sha256', json_encode($payload).'store@v1/customers'.$apiKey->key);
    $hash2 = hash('sha256', json_encode($payload).'store@v1/customers'.$apiKey->key);

    expect($hash1)->toBe($hash2);
});

beforeEach(function () {
    $this->calls = 0;
    $this->status = 201;

    Route::post('/_test/idempotent', function () {
        $this->calls++;

        return response()->json(['call' => $this->calls], $this->status);
    })->middleware('idempotency');
});

it('replays a successful response for the same key without running again', function () {
    $headers = ['Idempotency-Key' => 'key-success'];

    $this->postJson('/_test/idempotent', ['a' => 1], $headers)->assertCreated()->assertJson(['call' => 1]);
    $this->postJson('/_test/idempotent', ['a' => 1], $headers)->assertCreated()->assertJson(['call' => 1]);

    expect($this->calls)->toBe(1);
});

it('replays a client error response for the same key', function () {
    $this->status = 409;
    $headers = ['Idempotency-Key' => 'key-conflict'];

    $this->postJson('/_test/idempotent', ['a' => 1], $headers)->assertStatus(409);
    $this->postJson('/_test/idempotent', ['a' => 1], $headers)->assertStatus(409)->assertJson(['call' => 1]);

    expect($this->calls)->toBe(1);
});

it('does not store a server error, so a retry with the same key runs again', function () {
    $this->status = 500;
    $headers = ['Idempotency-Key' => 'key-server-error'];

    $this->postJson('/_test/idempotent', ['a' => 1], $headers)->assertStatus(500);
    expect(IdempotencyKey::where('idempotency_key', 'key-server-error')->exists())->toBeFalse();

    $this->status = 201;
    $this->postJson('/_test/idempotent', ['a' => 1], $headers)->assertCreated()->assertJson(['call' => 2]);

    expect($this->calls)->toBe(2);
});
