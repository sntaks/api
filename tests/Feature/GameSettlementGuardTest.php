<?php

use App\Models\Customer;
use App\Models\GameTransaction;
use App\Models\GameWallet;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->headers = apiHeaders(createApiKey('test-settlement-guard-key')->key);

    $house = Customer::factory()->create(['id' => 1]);
    $this->house = Wallet::forceCreate(['id' => 1, 'customer_id' => $house->id, 'balance' => 0]);

    $this->players = collect(range(1, 3))->map(function () {
        $customer = Customer::factory()->create();
        Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 1000]);

        return $customer;
    });

    $this->game = GameWallet::create(['game_id' => 'G-GUARD', 'game_type' => 1, 'balance' => 0, 'status' => 1]);
});

function guardStake(object $test, Customer $player, float $amount = 100, ?GameWallet $game = null)
{
    return $test->postJson('/api/v1/game/bets', [
        'game_wallet_id' => ($game ?? $test->game)->id,
        'customer_id' => $player->id,
        'payment_type' => 'deposit',
        'amount' => $amount,
        'status' => 1,
    ], $test->headers);
}

function guardBalance(Customer $player): float
{
    return (float) Wallet::where('customer_id', $player->id)->value('balance');
}

function withdrawGame(object $test, Customer $winner)
{
    return $test->postJson('/api/v1/game/withdraw/'.encryptId($test->game->id), ['customer_id' => (string) $winner->id], $test->headers);
}

/**
 * Each call has its own Idempotency-Key, so a repeat reaches the service instead of replaying the first
 * stored response.
 */
function refundGame(object $test)
{
    return $test->postJson('/api/v1/game/refund', ['game_wallet_id' => $test->game->id], $test->headers + ['Idempotency-Key' => (string) Str::uuid()]);
}

function dropGame(object $test, array $active, array $dropped, int $started = 1)
{
    return $test->postJson('/api/v1/game/drop/'.encryptId($test->game->id), [
        'players' => array_merge($active, $dropped),
        'active' => $active,
        'dropped' => $dropped,
        'game' => $started,
    ], $test->headers);
}

it('pays a game out once: a second withdrawal is refused', function () {
    [$a, $b] = [$this->players[0], $this->players[1]];
    guardStake($this, $a)->assertCreated();
    guardStake($this, $b)->assertCreated();

    withdrawGame($this, $a)->assertCreated();
    withdrawGame($this, $a)->assertConflict()->assertJson(['code' => 'game_closed', 'error' => 'This game is already settled']);

    expect(guardBalance($a))->toBe(1090.0)
        ->and(GameTransaction::where('payment_type', 'payout')->where('customer_id', $a->id)->count())->toBe(1);
});

it('does not refund a game that was already paid out (issue #4)', function () {
    [$a, $b] = [$this->players[0], $this->players[1]];
    guardStake($this, $a)->assertCreated();
    guardStake($this, $b)->assertCreated();
    withdrawGame($this, $a)->assertCreated();

    refundGame($this)->assertConflict()->assertJson(['status' => 'error', 'code' => 'game_closed']);

    expect(guardBalance($a))->toBe(1090.0)
        ->and(guardBalance($b))->toBe(900.0)
        ->and(GameTransaction::where('payment_type', 'like', 'refund%')->count())->toBe(0);
});

it('refunds a game once', function () {
    [$a, $b] = [$this->players[0], $this->players[1]];
    guardStake($this, $a)->assertCreated();
    guardStake($this, $b)->assertCreated();

    refundGame($this)->assertOk();
    refundGame($this)->assertConflict()->assertJsonPath('code', 'game_closed');

    expect(guardBalance($a))->toBe(1000.0)->and(guardBalance($b))->toBe(1000.0);
});

it('settles a dropped game once: a repeated drop call pays nothing (issue #6)', function () {
    [$a, $b, $c] = [$this->players[0], $this->players[1], $this->players[2]];
    guardStake($this, $a)->assertCreated();
    guardStake($this, $b)->assertCreated();
    guardStake($this, $c)->assertCreated();

    dropGame($this, [$a->id, $b->id], [$c->id])->assertCreated();
    $afterFirst = [guardBalance($a), guardBalance($b), (float) $this->house->fresh()->balance];

    dropGame($this, [$a->id, $b->id], [$c->id])->assertConflict()->assertJsonPath('code', 'game_closed');

    expect([guardBalance($a), guardBalance($b), (float) $this->house->fresh()->balance])->toBe($afterFirst)
        ->and(GameTransaction::where('payment_type', 'payout|dropped')->count())->toBe(3);
});

it('does not pay out a game that was dropped', function () {
    [$a, $b] = [$this->players[0], $this->players[1]];
    guardStake($this, $a)->assertCreated();
    guardStake($this, $b)->assertCreated();
    dropGame($this, [$a->id], [$b->id], started: 0)->assertCreated();

    withdrawGame($this, $a)->assertConflict()->assertJsonPath('code', 'game_closed');
    refundGame($this)->assertConflict()->assertJsonPath('code', 'game_closed');
});

it('takes no stake into a settled game (issue #8)', function () {
    [$a, $b, $c] = [$this->players[0], $this->players[1], $this->players[2]];
    guardStake($this, $a)->assertCreated();
    guardStake($this, $b)->assertCreated();
    withdrawGame($this, $a)->assertCreated();

    guardStake($this, $c)->assertConflict()->assertJson(['code' => 'game_closed']);

    expect(guardBalance($c))->toBe(1000.0)
        ->and((float) $this->game->fresh()->balance)->toBe(0.0)
        ->and(GameTransaction::where('customer_id', $c->id)->count())->toBe(0);
});

it('never lets stakes spend more than the wallet holds', function () {
    $a = $this->players[0];
    $other = GameWallet::create(['game_id' => 'G-OTHER', 'game_type' => 1, 'balance' => 0, 'status' => 1]);

    guardStake($this, $a, 700)->assertCreated();
    guardStake($this, $a, 400, $other)->assertStatus(400);

    expect(guardBalance($a))->toBe(300.0)
        ->and((float) $other->fresh()->balance)->toBe(0.0);
});

it('pays the drop house share into the configured house wallet', function () {
    $owner = Customer::factory()->create();
    $houseWallet = Wallet::factory()->create(['customer_id' => $owner->id, 'balance' => 0]);
    config(['wallets.house_wallet_id' => $houseWallet->id]);
    [$a, $b] = [$this->players[0], $this->players[1]];
    guardStake($this, $a)->assertCreated();
    guardStake($this, $b)->assertCreated();

    dropGame($this, [$a->id], [$b->id])->assertCreated();

    expect((float) $houseWallet->fresh()->balance)->toBe(10.0)
        ->and((float) $this->house->fresh()->balance)->toBe(0.0)
        ->and(LedgerEntry::where('entry_type', 'house_cut')->sole()->wallet_id)->toBe($houseWallet->id);
});

it('locks every active player wallet before the house wallet when paying a dropped game', function () {
    [$a, $b, $c] = [$this->players[0], $this->players[1], $this->players[2]];
    guardStake($this, $a)->assertCreated();
    guardStake($this, $b)->assertCreated();
    guardStake($this, $c)->assertCreated();
    // Make wallet id order differ from customer id order: a's wallet is recreated with a higher id
    Wallet::where('customer_id', $a->id)->update(['id' => Wallet::max('id') + 1]);
    $walletLocks = [];
    $playerLockOrder = [];
    DB::listen(function (QueryExecuted $query) use (&$walletLocks, &$playerLockOrder) {
        if (preg_match('/^select .* from [`"]?wallets[`"]?.* for update$/i', $query->sql)) {
            $isPlayer = str_contains($query->sql, 'customer_id');
            $walletLocks[] = $isPlayer ? 'player' : 'house';
            if ($isPlayer) {
                $playerLockOrder[] = (int) $query->bindings[0];
            }
        }
    });

    dropGame($this, [$a->id, $b->id], [$c->id])->assertCreated();

    expect($walletLocks)->toBe(['player', 'player', 'house'])
        ->and($playerLockOrder)->toBe(Wallet::whereIn('customer_id', [$a->id, $b->id])->orderBy('id')->pluck('customer_id')->all())
        ->and(guardBalance($a))->toBe(945.0)
        ->and(guardBalance($b))->toBe(945.0)
        ->and((float) $this->house->fresh()->balance)->toBe(10.0);
});

it('keeps the house cut when the house customer is one of the active players', function () {
    $this->house->update(['balance' => 1000]);
    $houseCustomer = Customer::find(1);
    [$a, $c] = [$this->players[0], $this->players[2]];
    guardStake($this, $houseCustomer)->assertCreated();
    guardStake($this, $a)->assertCreated();
    guardStake($this, $c)->assertCreated();

    dropGame($this, [$houseCustomer->id, $a->id], [$c->id])->assertCreated();

    $houseLedger = LedgerEntry::where('wallet_type', LedgerEntry::WALLET_TYPE_WALLET)->where('wallet_id', $this->house->id)->get();
    expect((float) $this->house->fresh()->balance)->toBe(955.0)
        ->and(1000 + (float) $houseLedger->sum('credit') - (float) $houseLedger->sum('debit'))->toBe(955.0)
        ->and(guardBalance($a))->toBe(945.0);
});

it('keeps a game open for stakes while it is open', function () {
    guardStake($this, $this->players[0])->assertCreated();
    guardStake($this, $this->players[1])->assertCreated();

    expect((float) $this->game->fresh()->balance)->toBe(200.0)
        ->and($this->game->fresh()->status)->toBe(1);
});
