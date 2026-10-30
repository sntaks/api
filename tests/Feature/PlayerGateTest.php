<?php

use App\Models\Customer;
use App\Services\PlayerGate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->gate = app(PlayerGate::class);
});

it('lets only an active player play', function (int $status, bool $allowed) {
    $result = $this->gate->canPlay(Customer::factory()->create(['status' => $status]));

    expect($result->allowed)->toBe($allowed);

    if (! $allowed) {
        expect($result->code)->toBe('player_inactive')->and($result->statusCode)->toBe(403);
    }
})->with([
    'active' => [Customer::STATUS_ACTIVE, true],
    'blocked' => [Customer::STATUS_BLOCKED, false],
    'confirmed' => [Customer::STATUS_CONFIRMED, false],
    'closed' => [Customer::STATUS_CLOSED, false],
    'fraud' => [Customer::STATUS_FRAUD, false],
    'lead' => [Customer::STATUS_LEAD, false],
]);

it('refuses a deleted player', function () {
    $customer = Customer::factory()->create(['status' => 1]);
    $customer->delete();

    expect($this->gate->canPlay($customer)->code)->toBe('player_inactive');
});

it('refuses withdrawals only to suspended players', function (int $status, bool $allowed) {
    $result = $this->gate->canWithdraw(Customer::factory()->create(['status' => $status]));

    expect($result->allowed)->toBe($allowed);

    if (! $allowed) {
        expect($result->code)->toBe('player_suspended')->and($result->statusCode)->toBe(403);
    }
})->with([
    'active' => [Customer::STATUS_ACTIVE, true],
    'blocked' => [Customer::STATUS_BLOCKED, false],
    'confirmed' => [Customer::STATUS_CONFIRMED, true],
    'closed' => [Customer::STATUS_CLOSED, true],
    'fraud' => [Customer::STATUS_FRAUD, false],
    'lead' => [Customer::STATUS_LEAD, true],
    'a code GMS does not define' => [0, false],
]);

it('turns a refusal into the error response', function () {
    $response = $this->gate->canPlay(Customer::factory()->create(['status' => 2]))->toResponse();

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true))->toMatchArray(['success' => false, 'code' => 'player_inactive']);
});
