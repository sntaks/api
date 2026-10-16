<?php

use App\Models\Customer;
use App\Models\GameTransaction;
use App\Models\GameWallet;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Services\GameWalletService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00', config('app.timezone')));
    config(['finance.test_customer_ids' => []]);

    $this->headers = apiHeaders(createApiKey('test-game-credit-key')->key);

    $house = Customer::factory()->create(['id' => 1]);
    $this->houseWallet = Wallet::forceCreate(['id' => 1, 'customer_id' => $house->id, 'balance' => 0]);

    $this->game = GameWallet::create(['game_id' => 'GAME_SHARE_001', 'game_type' => 1, 'balance' => 0, 'status' => 1]);
    $this->players = collect(range(1, 4))->map(function () {
        $customer = Customer::factory()->create();
        $wallet = Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 100]);

        app(GameWalletService::class)->createGameTransaction([
            'game_wallet_id' => $this->game->id,
            'customer_id' => $customer->id,
            'payment_type' => 'deposit',
            'amount' => 20,
            'status' => 1,
        ]);

        return $wallet->fresh();
    });
});

function creditShare(object $test, Wallet $wallet, float $amount, ?GameWallet $game = null): TestResponse
{
    return $test->postJson('/api/v1/game/credit', [
        'customer_id' => $wallet->customer_id,
        'game_wallet_id' => ($game ?? $test->game)->id,
        'amount' => $amount,
    ], $test->headers);
}

it('pays each remaining player their share, sweeps the rest to the house and closes the game', function () {
    [$first, $second, $third] = $this->players;

    foreach ([$first, $second, $third] as $player) {
        creditShare($this, $player, 25)->assertCreated()->assertExactJson(['status' => 'success']);
    }

    foreach ([$first, $second, $third] as $player) {
        expect((float) $player->fresh()->balance)->toEqual(105.0);
    }
    expect((float) $this->players[3]->fresh()->balance)->toEqual(80.0);
    expect((float) $this->houseWallet->fresh()->balance)->toEqual(5.0);

    $game = $this->game->fresh();
    expect((float) $game->balance)->toEqual(0.0);
    expect($game->status)->toEqual(3);

    expect(LedgerEntry::where('entry_type', 'game_payout')->sum('credit'))->toEqual(75);
    expect(LedgerEntry::where('entry_type', 'escrow_release')->where('wallet_id', $game->id)->sum('debit'))->toEqual(80);

    $houseCut = LedgerEntry::where('entry_type', 'house_cut')->sole();
    expect((float) $houseCut->credit)->toEqual(5.0);
    expect($houseCut->metadata)->toMatchArray(['source' => 'game_drop_payout', 'game_wallet_id' => $game->id]);

    expect(GameTransaction::where('payment_type', 'payout|dropped')->where('customer_id', 1)->sum('amount'))->toEqual(5);
});

it('shows the split in the game results and the income statement', function () {
    foreach ($this->players->take(3) as $player) {
        creditShare($this, $player, 25)->assertCreated();
    }

    $result = app(GameWalletService::class)->getGameResults()[0];
    expect($result['settlement'])->toBe('dropped');
    expect($result['income'])->toEqual(5.0);
    expect(collect($result['participants'])->pluck('result', 'customer_id')->all())->toEqual([
        $this->players[0]->customer_id => 'win',
        $this->players[1]->customer_id => 'win',
        $this->players[2]->customer_id => 'win',
        $this->players[3]->customer_id => 'loss',
    ]);

    $revenue = $this->getJson('/api/v1/finance/income-statement', $this->headers)->assertOk()->json('data.revenue');
    expect($revenue['games'])->toEqual(5);
});

it('keeps the game in escrow when a share is still to come', function () {
    creditShare($this, $this->players[0], 25)->assertCreated();
    creditShare($this, $this->players[1], 25)->assertCreated();

    $game = $this->game->fresh();
    expect((float) $game->balance)->toEqual(30.0);
    expect($game->status)->toEqual(3);
    expect(LedgerEntry::where('entry_type', 'house_cut')->exists())->toBeFalse();

    creditShare($this, $this->players[2], 25)->assertCreated();

    expect((float) $this->game->fresh()->balance)->toEqual(0.0);
    expect((float) $this->houseWallet->fresh()->balance)->toEqual(5.0);
});

it('replays the first answer when the game server retries a share with the same key', function () {
    $player = $this->players[0];
    $payload = ['customer_id' => $player->customer_id, 'game_wallet_id' => $this->game->id, 'amount' => 25];
    $headers = $this->headers + ['Idempotency-Key' => hash('sha256', "game-share|{$this->game->id}|{$player->customer_id}")];

    $this->postJson('/api/v1/game/credit', $payload, $headers)->assertCreated();
    $this->postJson('/api/v1/game/credit', $payload, $headers)->assertCreated()->assertExactJson(['status' => 'success']);

    expect((float) $player->fresh()->balance)->toEqual(105.0);
    expect(GameTransaction::where('payment_type', 'payout|dropped')->where('customer_id', $player->customer_id)->count())->toBe(1);
});

it('refuses to pay the same player twice', function () {
    creditShare($this, $this->players[0], 25)->assertCreated();

    creditShare($this, $this->players[0], 26)->assertStatus(409)
        ->assertJson(['status' => 'error', 'error' => 'Customer has already been paid for this game']);

    expect((float) $this->players[0]->fresh()->balance)->toEqual(105.0);
});

it('refuses a customer who did not stake in the game', function () {
    $outsider = Wallet::factory()->create(['customer_id' => Customer::factory()->create()->id, 'balance' => 0]);

    creditShare($this, $outsider, 25)->assertStatus(422)
        ->assertJson(['status' => 'error', 'error' => 'Customer did not stake in this game']);

    expect((float) $outsider->fresh()->balance)->toEqual(0.0);
});

it('refuses a share larger than what is left in the game wallet', function () {
    creditShare($this, $this->players[0], 81)->assertStatus(409)
        ->assertJson(['status' => 'error', 'error' => 'Share is more than the game wallet balance']);

    expect((float) $this->players[0]->fresh()->balance)->toEqual(80.0);
    expect($this->game->fresh()->status)->toEqual(1);
});

it('refuses a game that was already paid out to a winner', function () {
    app(GameWalletService::class)->processGameWithdrawal($this->game->id, $this->players[0]->customer_id);

    creditShare($this, $this->players[1], 25)->assertStatus(409)
        ->assertJson(['status' => 'error', 'error' => 'Game Wallet is already closed']);
});

it('validates the request', function () {
    $this->postJson('/api/v1/game/credit', [], $this->headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['customer_id', 'game_wallet_id', 'amount']);
});
