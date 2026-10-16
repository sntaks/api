<?php

use App\Models\IdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

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

it('does not replay one api key\'s response to another sending the same body without a key', function () {
    $this->postJson('/_test/idempotent', ['a' => 1], ['X-API-KEY' => 'client-one'])->assertCreated()->assertJson(['call' => 1]);
    $this->postJson('/_test/idempotent', ['a' => 1], ['X-API-KEY' => 'client-two'])->assertCreated()->assertJson(['call' => 2]);

    expect($this->calls)->toBe(2);
});

it('replays the same body from the same api key without a key', function () {
    $this->postJson('/_test/idempotent', ['a' => 1], ['X-API-KEY' => 'client-one'])->assertCreated()->assertJson(['call' => 1]);
    $this->postJson('/_test/idempotent', ['a' => 1], ['X-API-KEY' => 'client-one'])->assertCreated()->assertJson(['call' => 1]);

    expect($this->calls)->toBe(1);
});

it('keeps the same idempotency key from two api keys apart', function () {
    $this->postJson('/_test/idempotent', ['a' => 1], ['X-API-KEY' => 'client-one', 'Idempotency-Key' => 'shared'])->assertCreated()->assertJson(['call' => 1]);
    $this->postJson('/_test/idempotent', ['a' => 2], ['X-API-KEY' => 'client-two', 'Idempotency-Key' => 'shared'])->assertCreated()->assertJson(['call' => 2]);

    $this->postJson('/_test/idempotent', ['a' => 1], ['X-API-KEY' => 'client-one', 'Idempotency-Key' => 'shared'])->assertCreated()->assertJson(['call' => 1]);
    $this->postJson('/_test/idempotent', ['a' => 2], ['X-API-KEY' => 'client-two', 'Idempotency-Key' => 'shared'])->assertCreated()->assertJson(['call' => 2]);

    expect($this->calls)->toBe(2)
        ->and(IdempotencyKey::where('idempotency_key', 'shared')->exists())->toBeFalse();
});

it('still refuses a different body under the same key from the same api key', function () {
    $this->postJson('/_test/idempotent', ['a' => 1], ['X-API-KEY' => 'client-one', 'Idempotency-Key' => 'mine'])->assertCreated();
    $this->postJson('/_test/idempotent', ['a' => 2], ['X-API-KEY' => 'client-one', 'Idempotency-Key' => 'mine'])->assertStatus(409);

    expect($this->calls)->toBe(1);
});

it('replays a response stored under the raw key before keys were scoped per api key', function () {
    $this->postJson('/_test/idempotent', ['a' => 1], ['X-API-KEY' => 'client-one', 'Idempotency-Key' => 'legacy'])->assertCreated();
    IdempotencyKey::query()->update(['idempotency_key' => 'legacy']);

    $this->postJson('/_test/idempotent', ['a' => 1], ['X-API-KEY' => 'client-one', 'Idempotency-Key' => 'legacy'])->assertCreated()->assertJson(['call' => 1]);

    expect($this->calls)->toBe(1);
});

it('does not store a server error, so a retry with the same key runs again', function () {
    $this->status = 500;
    $headers = ['Idempotency-Key' => 'key-server-error'];

    $this->postJson('/_test/idempotent', ['a' => 1], $headers)->assertStatus(500);
    expect(IdempotencyKey::count())->toBe(0);

    $this->status = 201;
    $this->postJson('/_test/idempotent', ['a' => 1], $headers)->assertCreated()->assertJson(['call' => 2]);

    expect($this->calls)->toBe(2);
});
