<?php

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Wager;
use App\Models\WagerRound;
use App\Models\WagerSettlement;
use App\Models\Wallet;
use App\Services\LedgerService;
use App\Services\PlayerTokenService;
use App\Services\WagerRoundService;
use App\Services\WagerService;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->game = Game::factory()->create(['code' => 'crash']);
    $this->key = ApiKey::create(['key' => 'game_crash_key', 'name' => 'crash', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $this->game->id, 'is_active' => true]);
    $this->product = Product::factory()->create(['game_id' => $this->game->id, 'code' => 'crash_classic']);
    $houseCustomer = Customer::factory()->create(['id' => 1]);
    $this->house = Wallet::forceCreate(['id' => 1, 'customer_id' => $houseCustomer->id, 'balance' => 10000]);
    $this->player = Customer::factory()->create(['status' => 1]);
    $this->wallet = Wallet::forceCreate(['customer_id' => $this->player->id, 'balance' => 1000]);
    $this->otherPlayer = Customer::factory()->create(['status' => 1]);
    $this->otherWallet = Wallet::forceCreate(['customer_id' => $this->otherPlayer->id, 'balance' => 1000]);
    $this->round = WagerRound::factory()->create(['product_id' => $this->product->id, 'reference' => 'round-1']);
});

/** An open wager in a round whose cash stake really sits in escrow (the player wallet is debited through the ledger). */
function roundWager(object $test, string $reference, float $stake = 100, bool $freeBet = false, bool $otherPlayer = false, ?WagerRound $round = null): Wager
{
    $wallet = $otherPlayer ? $test->otherWallet : $test->wallet;
    $attributes = [
        'product_id' => $test->product->id, 'customer_id' => $wallet->customer_id, 'wallet_id' => $wallet->id,
        'api_key_id' => $test->key->id, 'reference' => $reference, 'balance' => 0, 'wager_round_id' => ($round ?? $test->round)->id,
    ];

    if ($freeBet) {
        return Wager::factory()->freeBet($stake)->create($attributes)->fresh();
    }

    $wager = Wager::factory()->create($attributes + ['stake' => $stake]);
    app(LedgerService::class)->recordWagerStake($wager, $wallet->fresh(), $stake);

    return $wager->fresh();
}

function settleRound(object $test, array $overrides = [], string $round = 'round-1')
{
    $payload = array_merge(['product' => 'crash_classic', 'winners' => []], $overrides);

    return $test->postJson("/api/v1/rounds/{$round}/settle", $payload, apiHeaders($test->key->key));
}

function voidRound(object $test, array $overrides = [], string $round = 'round-1')
{
    return $test->postJson("/api/v1/rounds/{$round}/void", array_merge(['product' => 'crash_classic'], $overrides), apiHeaders($test->key->key));
}

/** @return list<string> "type wallet_type:id sign-amount" for every entry after the stakes, in posting order */
function roundLegs(): array
{
    return LedgerEntry::orderBy('id')->get()
        ->reject(fn ($entry) => $entry->entry_type === 'wager_stake')
        ->map(fn ($entry) => sprintf(
            '%s %s:%d %s%.2f',
            $entry->entry_type, $entry->wallet_type, $entry->wallet_id,
            (float) $entry->debit > 0 ? '-' : '+', (float) $entry->debit > 0 ? $entry->debit : $entry->credit
        ))->values()->all();
}

function roundBalances(object $test): array
{
    return [
        'player' => (float) $test->wallet->fresh()->balance,
        'other' => (float) $test->otherWallet->fresh()->balance,
        'house' => (float) $test->house->fresh()->balance,
        'escrow' => (float) Wager::sum('balance'),
    ];
}

function roundCounts(int $won = 0, int $lost = 0, int $voided = 0, int $alreadySettled = 0, int $leftOpen = 0): array
{
    return ['won' => $won, 'lost' => $lost, 'voided' => $voided, 'already_settled' => $alreadySettled, 'left_open' => $leftOpen];
}

it('settles listed winners and every other open wager as lost', function () {
    $this->product->update(['winnings_tax_rate' => 0.2]);
    $a = roundWager($this, 'a', 100);
    $b = roundWager($this, 'b', 50, otherPlayer: true);
    $c = roundWager($this, 'c', 20, freeBet: true);
    $d = roundWager($this, 'd', 30);

    settleRound($this, ['winners' => [
        ['reference' => 'c', 'payout' => 40],
        ['reference' => 'a', 'payout' => 250, 'bonus' => 10],
    ]])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.round.reference', 'round-1')
        ->assertJsonPath('data.round.product', 'crash_classic')
        ->assertJsonPath('data.round.status', 'settled')
        ->assertJsonPath('data.counts', roundCounts(won: 2, lost: 2));

    // winners first (in id order), then the rest; cash win taxed on profit (160 x 20%), free bet on all of it (40 x 20%)
    expect(roundLegs())->toBe([
        "wager_stake_settled wager:{$a->id} -100.00",
        'wager_stake_settled wallet:1 +100.00',
        'wager_payout wallet:1 -250.00',
        "wager_payout wallet:{$this->wallet->id} +250.00",
        'wager_bonus wallet:1 -10.00',
        "wager_bonus wallet:{$this->wallet->id} +10.00",
        "winnings_tax wallet:{$this->wallet->id} -32.00",
        'wager_free_bet_payout wallet:1 -40.00',
        "wager_free_bet_payout wallet:{$this->wallet->id} +40.00",
        "winnings_tax wallet:{$this->wallet->id} -8.00",
        "wager_stake_settled wager:{$b->id} -50.00",
        'wager_stake_settled wallet:1 +50.00',
        "wager_stake_settled wager:{$d->id} -30.00",
        'wager_stake_settled wallet:1 +30.00',
    ]);
    // player 1000 - 100 - 30 + 250 + 10 - 32 + 40 - 8; other 1000 - 50; house 10000 + 100 - 250 - 10 - 40 + 50 + 30
    expect(roundBalances($this))->toBe(['player' => 1130.0, 'other' => 950.0, 'house' => 9880.0, 'escrow' => 0.0]);

    expect(Wager::orderBy('id')->pluck('status', 'reference')->all())->toBe(['a' => 'won', 'b' => 'lost', 'c' => 'won', 'd' => 'lost']);
    expect(WagerSettlement::count())->toBe(4)
        ->and(WagerSettlement::where('version', 1)->where('settled_by', 'game')->where('status', 'applied')->count())->toBe(4);
    expect((float) $a->fresh()->payout)->toBe(250.0)->and((float) $a->fresh()->tax)->toBe(32.0)->and($a->fresh()->settlement_version)->toBe(1);

    $round = $this->round->fresh();
    expect($round->status)->toBe('settled')->and($round->closed_at)->not->toBeNull();
});

it('voids every open wager in a round', function () {
    $a = roundWager($this, 'a', 100);
    $b = roundWager($this, 'b', 50, otherPlayer: true);
    roundWager($this, 'c', 20, freeBet: true);

    voidRound($this, ['reason' => 'server crash'])
        ->assertOk()
        ->assertJsonPath('data.round.status', 'voided')
        ->assertJsonPath('data.counts', roundCounts(voided: 3))
        ->assertJsonMissingPath('data.skipped_winners');

    expect(roundLegs())->toBe([
        "wager_refund wager:{$a->id} -100.00",
        "wager_refund wallet:{$this->wallet->id} +100.00",
        "wager_refund wager:{$b->id} -50.00",
        "wager_refund wallet:{$this->otherWallet->id} +50.00",
    ]);
    expect(roundBalances($this))->toBe(['player' => 1000.0, 'other' => 1000.0, 'house' => 10000.0, 'escrow' => 0.0]);
    expect(Wager::where('status', 'void')->count())->toBe(3)
        ->and(WagerSettlement::where('outcome', 'void')->where('reason', 'server crash')->where('version', 1)->count())->toBe(3);
    expect($this->round->fresh()->status)->toBe('voided')->and($this->round->fresh()->closed_at)->not->toBeNull();
});

it('refuses a place into a settled round', function () {
    $token = app(PlayerTokenService::class)->issue($this->player, $this->game)->plainTextToken;
    $place = fn (string $reference) => $this->postJson('/api/v1/wagers', [
        'product' => 'crash_classic', 'reference' => $reference, 'funding' => 'cash', 'stake' => 100, 'round_reference' => 'round-1',
    ], apiHeaders($this->key->key) + ['Authorization' => 'Bearer '.$token]);

    $place('bet-1')->assertCreated();
    settleRound($this)->assertOk()->assertJsonPath('data.counts', roundCounts(lost: 1));

    $place('bet-2')->assertStatus(409)->assertJson(['success' => false, 'code' => 'round_closed', 'retryable' => false]);
    // the bet that went in before the settlement still replays
    $place('bet-1')->assertOk()->assertJsonPath('data.replayed', true);

    expect(Wager::count())->toBe(1)->and(roundBalances($this))->toBe(['player' => 900.0, 'other' => 1000.0, 'house' => 10100.0, 'escrow' => 0.0]);
});

it('is safe to rerun a round settlement', function () {
    $a = roundWager($this, 'a', 100);
    $b = roundWager($this, 'b', 50, otherPlayer: true);
    $winners = ['winners' => [['reference' => 'a', 'payout' => 250]]];

    settleRound($this, $winners)->assertOk()->assertJsonPath('data.counts', roundCounts(won: 1, lost: 1));
    $entries = LedgerEntry::count();
    $balances = roundBalances($this);

    settleRound($this, ['winners' => [['reference' => 'a', 'payout' => 250.00, 'bonus' => 0]]])
        ->assertOk()
        ->assertJsonPath('data.round.status', 'settled')
        ->assertJsonPath('data.counts', roundCounts(alreadySettled: 2));

    expect(LedgerEntry::count())->toBe($entries)->and(roundBalances($this))->toBe($balances)->and(WagerSettlement::count())->toBe(2);

    // a call that died between chunks left a wager open in the settled round: the rerun finishes it
    $late = roundWager($this, 'late', 40);
    settleRound($this, $winners)->assertOk()->assertJsonPath('data.counts', roundCounts(lost: 1, alreadySettled: 2));

    expect(array_slice(roundLegs(), -2))->toBe([
        "wager_stake_settled wager:{$late->id} -40.00",
        'wager_stake_settled wallet:1 +40.00',
    ]);
    // player 1000 - 100 - 40 + 250; other 1000 - 50; house 10000 + 100 - 250 + 50 + 40
    expect(roundBalances($this))->toBe(['player' => 1110.0, 'other' => 950.0, 'house' => 9940.0, 'escrow' => 0.0]);
    expect($a->fresh()->status)->toBe('won')->and($b->fresh()->status)->toBe('lost')->and($late->fresh()->status)->toBe('lost');
});

it('caps winners per call at 1000', function () {
    $winners = array_map(fn (int $i) => ['reference' => "w-{$i}", 'payout' => 10], range(1, 1001));
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    settleRound($this, ['winners' => $winners])
        ->assertStatus(422)
        ->assertJson(['success' => false, 'code' => 'validation_failed', 'retryable' => false])
        ->assertJsonValidationErrors('winners');

    expect(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'wager') || str_contains($sql, 'products'))->all())->toBe([]);
    expect($this->round->fresh()->closed_at)->toBeNull();

    // 1,000 passes validation (and is refused only because none of them are in the round)
    settleRound($this, ['winners' => array_slice($winners, 0, 1000)])->assertStatus(422)->assertJsonPath('code', 'wager_not_in_round');
});

it('does not validate the winners one by one once there are more than 1000', function () {
    // every item repeats one reference: checked one by one, each would be a distinct error (a quadratic check)
    $winners = array_fill(0, 1001, ['reference' => 'same', 'payout' => 10]);

    $response = settleRound($this, ['winners' => $winners])->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    expect(array_keys($response->json('errors')))->toBe(['winners']);
});

it('settles winners in non-final batches and closes the round to places', function () {
    $a = roundWager($this, 'a', 100);
    $b = roundWager($this, 'b', 50, otherPlayer: true);
    $c = roundWager($this, 'c', 30);

    settleRound($this, ['winners' => [['reference' => 'a', 'payout' => 200]], 'final' => false])
        ->assertOk()
        ->assertJsonPath('data.round.status', 'open')
        ->assertJsonPath('data.counts', roundCounts(won: 1));

    $round = $this->round->fresh();
    expect($round->status)->toBe('open')->and($round->closed_at)->not->toBeNull()
        ->and($b->fresh()->status)->toBe('open')->and($c->fresh()->status)->toBe('open');

    $token = app(PlayerTokenService::class)->issue($this->player, $this->game)->plainTextToken;
    $this->postJson('/api/v1/wagers', ['product' => 'crash_classic', 'reference' => 'late', 'funding' => 'cash', 'stake' => 10, 'round_reference' => 'round-1'],
        apiHeaders($this->key->key) + ['Authorization' => 'Bearer '.$token])
        ->assertStatus(409)->assertJsonPath('code', 'round_closed');

    settleRound($this, ['winners' => [['reference' => 'b', 'payout' => 120, 'bonus' => 5]], 'final' => false])
        ->assertOk()->assertJsonPath('data.counts', roundCounts(won: 1, alreadySettled: 1));

    settleRound($this, ['final' => true])
        ->assertOk()
        ->assertJsonPath('data.round.status', 'settled')
        ->assertJsonPath('data.counts', roundCounts(lost: 1, alreadySettled: 2));

    expect(roundLegs())->toBe([
        "wager_stake_settled wager:{$a->id} -100.00",
        'wager_stake_settled wallet:1 +100.00',
        'wager_payout wallet:1 -200.00',
        "wager_payout wallet:{$this->wallet->id} +200.00",
        "wager_stake_settled wager:{$b->id} -50.00",
        'wager_stake_settled wallet:1 +50.00',
        'wager_payout wallet:1 -120.00',
        "wager_payout wallet:{$this->otherWallet->id} +120.00",
        'wager_bonus wallet:1 -5.00',
        "wager_bonus wallet:{$this->otherWallet->id} +5.00",
        "wager_stake_settled wager:{$c->id} -30.00",
        'wager_stake_settled wallet:1 +30.00',
    ]);
    // player 1000 - 100 - 30 + 200; other 1000 - 50 + 125; house 10000 + 100 - 200 + 50 - 125 + 30
    expect(roundBalances($this))->toBe(['player' => 1070.0, 'other' => 1075.0, 'house' => 9855.0, 'escrow' => 0.0]);
    expect(Wager::count())->toBe(3);
});

it('leaves a reversed and reopened wager open', function () {
    $a = roundWager($this, 'a', 100);
    $b = roundWager($this, 'b', 50, otherPlayer: true);
    $this->postJson('/api/v1/wagers/a/settle', ['product' => 'crash_classic', 'version' => 1, 'outcome' => 'won', 'payout' => 250], apiHeaders($this->key->key))->assertOk();
    $this->postJson('/api/v1/wagers/a/reverse', ['product' => 'crash_classic', 'version' => 1, 'reason' => 'wrong result'], apiHeaders($this->key->key))->assertOk();
    $before = roundLegs();
    $balances = roundBalances($this);

    // listed as a winner it is a conflict: the game re-settles it per wager at v2
    settleRound($this, ['winners' => [['reference' => 'a', 'payout' => 250]]])
        ->assertStatus(409)->assertJson(['code' => 'settlement_conflict', 'references' => ['a']]);
    expect(roundLegs())->toBe($before)->and($this->round->fresh()->closed_at)->toBeNull();

    settleRound($this)->assertOk()->assertJsonPath('data.counts', roundCounts(lost: 1, leftOpen: 1));

    expect(array_slice(roundLegs(), count($before)))->toBe([
        "wager_stake_settled wager:{$b->id} -50.00",
        'wager_stake_settled wallet:1 +50.00',
    ]);
    expect(roundBalances($this))->toBe(['player' => $balances['player'], 'other' => 950.0, 'house' => $balances['house'] + 50, 'escrow' => 100.0]);
    expect($a->fresh()->status)->toBe('open')->and($a->fresh()->settlement_version)->toBe(1)->and(WagerSettlement::where('wager_id', $a->id)->count())->toBe(1);

    // the settled round can no longer be voided; the game settles the reopened wager per wager at v2
    voidRound($this, [], 'round-1')->assertStatus(409)->assertJsonPath('code', 'round_closed');
    $this->postJson('/api/v1/wagers/a/settle', ['product' => 'crash_classic', 'version' => 2, 'outcome' => 'lost', 'payout' => 0], apiHeaders($this->key->key))->assertOk();
});

it('leaves a reversed and reopened wager open when the round is voided', function () {
    $a = roundWager($this, 'a', 100);
    $b = roundWager($this, 'b', 50, otherPlayer: true);
    $this->postJson('/api/v1/wagers/a/settle', ['product' => 'crash_classic', 'version' => 1, 'outcome' => 'won', 'payout' => 250], apiHeaders($this->key->key))->assertOk();
    $this->postJson('/api/v1/wagers/a/reverse', ['product' => 'crash_classic', 'version' => 1, 'reason' => 'wrong result'], apiHeaders($this->key->key))->assertOk();
    $before = roundLegs();
    $balances = roundBalances($this);

    voidRound($this, ['reason' => 'aborted'])
        ->assertOk()
        ->assertJsonPath('data.round.status', 'voided')
        ->assertJsonPath('data.counts', roundCounts(voided: 1, leftOpen: 1));

    // only b is refunded: no refund leg for a, whose stake stays in its escrow
    expect(array_slice(roundLegs(), count($before)))->toBe([
        "wager_refund wager:{$b->id} -50.00",
        "wager_refund wallet:{$this->otherWallet->id} +50.00",
    ]);
    expect(roundBalances($this))->toBe(['player' => $balances['player'], 'other' => 1000.0, 'house' => $balances['house'], 'escrow' => 100.0]);
    expect($a->fresh()->status)->toBe('open')->and($a->fresh()->settlement_version)->toBe(1)
        ->and((float) $a->fresh()->balance)->toBe(100.0)
        ->and(WagerSettlement::where('wager_id', $a->id)->count())->toBe(1);
});

it('reports a winner voided by an admin between the opening transaction and its chunk', function () {
    $a = roundWager($this, 'a', 100);
    $b = roundWager($this, 'b', 50, otherPlayer: true);
    $c = roundWager($this, 'c', 30);

    // GMS voids winner a right after the settle's opening transaction checked it as eligible
    $voided = false;
    Event::listen(TransactionCommitted::class, function () use (&$voided, $a) {
        if (! $voided) {
            $voided = true;
            expect(app(WagerService::class)->adminVoid($a->fresh(), 'stuck', 'ops')->isRefused())->toBeFalse();
        }
    });

    settleRound($this, ['winners' => [['reference' => 'a', 'payout' => 250], ['reference' => 'b', 'payout' => 120]]])
        ->assertOk()
        ->assertJsonPath('data.skipped_winners', ['a'])
        ->assertJsonPath('data.counts', roundCounts(won: 1, lost: 1));

    expect($voided)->toBeTrue();
    // a's admin refund, b's win, c's loss: nothing paid to a
    expect(roundLegs())->toBe([
        "wager_refund wager:{$a->id} -100.00",
        "wager_refund wallet:{$this->wallet->id} +100.00",
        "wager_stake_settled wager:{$b->id} -50.00",
        'wager_stake_settled wallet:1 +50.00',
        'wager_payout wallet:1 -120.00',
        "wager_payout wallet:{$this->otherWallet->id} +120.00",
        "wager_stake_settled wager:{$c->id} -30.00",
        'wager_stake_settled wallet:1 +30.00',
    ]);
    // player 1000 - 30 (a refunded); other 1000 - 50 + 120; house 10000 + 50 - 120 + 30
    expect(roundBalances($this))->toBe(['player' => 970.0, 'other' => 1070.0, 'house' => 9960.0, 'escrow' => 0.0]);
    expect($a->fresh()->status)->toBe('void')->and(WagerSettlement::where('wager_id', $a->id)->where('outcome', 'won')->count())->toBe(0);

    // with nothing skipped the list is present and empty
    settleRound($this, ['winners' => [['reference' => 'b', 'payout' => 120]]])
        ->assertOk()->assertJsonPath('data.skipped_winners', [])->assertJsonPath('data.counts', roundCounts(alreadySettled: 3));
});

it('refuses a non-final batch when the round is voided between its transactions', function () {
    $a = roundWager($this, 'a', 100);
    $b = roundWager($this, 'b', 50, otherPlayer: true);
    $service = app(WagerRoundService::class);

    // the void lands right after the settle's opening transaction commits, before its winner chunk
    $voided = false;
    Event::listen(TransactionCommitted::class, function () use (&$voided, $service) {
        if (! $voided) {
            $voided = true;
            expect($service->void($this->key, 'crash_classic', 'round-1', 'aborted')->isRefused())->toBeFalse();
        }
    });

    $result = $service->settle($this->key, 'crash_classic', 'round-1', [['reference' => 'a', 'payout' => 250]], final: false);

    expect($voided)->toBeTrue()
        ->and($result->isRefused())->toBeTrue()
        ->and($result->refusal['code'])->toBe('round_closed')
        ->and($result->refusal['status'])->toBe(409)
        ->and($result->refusal['message'])->toBe('This round was voided and cannot be settled');

    // the void refunded both; the winner was never paid into the voided round
    expect(roundLegs())->toBe([
        "wager_refund wager:{$a->id} -100.00",
        "wager_refund wallet:{$this->wallet->id} +100.00",
        "wager_refund wager:{$b->id} -50.00",
        "wager_refund wallet:{$this->otherWallet->id} +50.00",
    ]);
    expect(roundBalances($this))->toBe(['player' => 1000.0, 'other' => 1000.0, 'house' => 10000.0, 'escrow' => 0.0])
        ->and(Wager::where('status', 'void')->count())->toBe(2)
        ->and(WagerSettlement::where('outcome', 'won')->count())->toBe(0)
        ->and($this->round->fresh()->status)->toBe('voided');
});

it('refuses winners that are not in the round without moving money', function () {
    roundWager($this, 'a', 100);
    $otherRound = WagerRound::factory()->create(['product_id' => $this->product->id, 'reference' => 'round-2']);
    roundWager($this, 'x', 20, round: $otherRound);
    $otherProduct = Product::factory()->create(['game_id' => $this->game->id, 'code' => 'crash_turbo']);
    Wager::factory()->create([
        'product_id' => $otherProduct->id, 'customer_id' => $this->player->id, 'wallet_id' => $this->wallet->id,
        'api_key_id' => $this->key->id, 'reference' => 'y', 'wager_round_id' => WagerRound::factory()->create(['product_id' => $otherProduct->id, 'reference' => 'round-1'])->id,
    ]);
    $entries = LedgerEntry::count();
    $balances = roundBalances($this);

    settleRound($this, ['winners' => [
        ['reference' => 'a', 'payout' => 200],
        ['reference' => 'x', 'payout' => 40],
        ['reference' => 'y', 'payout' => 40],
        ['reference' => 'ghost', 'payout' => 40],
    ]])
        ->assertStatus(422)
        ->assertJson(['success' => false, 'code' => 'wager_not_in_round', 'retryable' => false, 'references' => ['x', 'y', 'ghost']]);

    expect(LedgerEntry::count())->toBe($entries)->and(roundBalances($this))->toBe($balances)->and(WagerSettlement::count())->toBe(0)
        ->and(Wager::where('status', 'open')->count())->toBe(3);
    $round = $this->round->fresh();
    expect($round->status)->toBe('open')->and($round->closed_at)->toBeNull();
});

it('refuses a winner settled differently without moving money', function () {
    roundWager($this, 'a', 100);
    roundWager($this, 'b', 50);
    roundWager($this, 'c', 30, otherPlayer: true);
    $this->postJson('/api/v1/wagers/a/settle', ['product' => 'crash_classic', 'version' => 1, 'outcome' => 'won', 'payout' => 250], apiHeaders($this->key->key))->assertOk();
    $this->postJson('/api/v1/wagers/b/settle', ['product' => 'crash_classic', 'version' => 1, 'outcome' => 'lost', 'payout' => 0], apiHeaders($this->key->key))->assertOk();
    $entries = LedgerEntry::count();
    $balances = roundBalances($this);

    settleRound($this, ['winners' => [
        ['reference' => 'a', 'payout' => 300],
        ['reference' => 'b', 'payout' => 80],
        ['reference' => 'c', 'payout' => 60],
    ]])
        ->assertStatus(409)
        ->assertJson(['success' => false, 'code' => 'settlement_conflict', 'retryable' => false, 'references' => ['a', 'b']]);

    expect(LedgerEntry::count())->toBe($entries)->and(roundBalances($this))->toBe($balances)
        ->and(Wager::where('reference', 'c')->value('status'))->toBe('open')
        ->and($this->round->fresh()->closed_at)->toBeNull();

    // an admin-settled winner is a conflict even with the same amounts
    WagerSettlement::where('wager_id', Wager::where('reference', 'a')->value('id'))->update(['settled_by' => 'admin', 'admin_user' => 'ops']);
    settleRound($this, ['winners' => [['reference' => 'a', 'payout' => 250]]])
        ->assertStatus(409)->assertJson(['code' => 'settlement_conflict', 'references' => ['a']]);
    expect(LedgerEntry::count())->toBe($entries);
});

it('closes an unknown round', function () {
    settleRound($this, [], 'nobody-bet')
        ->assertOk()
        ->assertJsonPath('data.round.reference', 'nobody-bet')
        ->assertJsonPath('data.round.status', 'settled')
        ->assertJsonPath('data.counts', roundCounts());

    voidRound($this, ['reason' => 'aborted'], 'nobody-bet-2')
        ->assertOk()
        ->assertJsonPath('data.round.status', 'voided')
        ->assertJsonPath('data.counts', roundCounts());

    $settled = WagerRound::where('reference', 'nobody-bet')->firstOrFail();
    $voided = WagerRound::where('reference', 'nobody-bet-2')->firstOrFail();
    expect($settled->product_id)->toBe($this->product->id)->and($settled->closed_at)->not->toBeNull()
        ->and($voided->status)->toBe('voided')->and($voided->closed_at)->not->toBeNull()
        ->and(LedgerEntry::count())->toBe(0);

    // a late place into it is refused
    $token = app(PlayerTokenService::class)->issue($this->player, $this->game)->plainTextToken;
    $this->postJson('/api/v1/wagers', ['product' => 'crash_classic', 'reference' => 'late', 'funding' => 'cash', 'stake' => 10, 'round_reference' => 'nobody-bet'],
        apiHeaders($this->key->key) + ['Authorization' => 'Bearer '.$token])
        ->assertStatus(409)->assertJsonPath('code', 'round_closed');

    // winners in a round nobody bet on are not in it
    settleRound($this, ['winners' => [['reference' => 'ghost', 'payout' => 10]]], 'nobody-bet-3')
        ->assertStatus(422)->assertJson(['code' => 'wager_not_in_round', 'references' => ['ghost']]);
});

it('refuses voiding a settled round', function () {
    $a = roundWager($this, 'a', 100);
    settleRound($this)->assertOk();
    $entries = LedgerEntry::count();

    voidRound($this, ['reason' => 'too late'])
        ->assertStatus(409)
        ->assertJson(['success' => false, 'code' => 'round_closed', 'retryable' => false])
        ->assertJsonPath('message', 'This round was settled and cannot be voided');

    expect(LedgerEntry::count())->toBe($entries)->and($a->fresh()->status)->toBe('lost')->and($this->round->fresh()->status)->toBe('settled');

    // and settling a voided round
    $other = WagerRound::factory()->create(['product_id' => $this->product->id, 'reference' => 'round-2']);
    $b = roundWager($this, 'b', 50, round: $other);
    voidRound($this, [], 'round-2')->assertOk()->assertJsonPath('data.counts', roundCounts(voided: 1));
    settleRound($this, ['winners' => [['reference' => 'b', 'payout' => 100]]], 'round-2')
        ->assertStatus(409)
        ->assertJson(['code' => 'round_closed'])
        ->assertJsonPath('message', 'This round was voided and cannot be settled');

    // rerunning the same void is allowed
    voidRound($this, [], 'round-2')->assertOk()->assertJsonPath('data.counts', roundCounts(alreadySettled: 1));
    expect($b->fresh()->status)->toBe('void')->and(roundBalances($this)['player'])->toBe(900.0);
});

it('voids a round a non-final settle closed, counting its winners as already settled', function () {
    roundWager($this, 'a', 100);
    $b = roundWager($this, 'b', 50, otherPlayer: true);
    settleRound($this, ['winners' => [['reference' => 'a', 'payout' => 150]], 'final' => false])->assertOk();

    voidRound($this)->assertOk()->assertJsonPath('data.round.status', 'voided')->assertJsonPath('data.counts', roundCounts(voided: 1, alreadySettled: 1));

    expect(array_slice(roundLegs(), -2))->toBe([
        "wager_refund wager:{$b->id} -50.00",
        "wager_refund wallet:{$this->otherWallet->id} +50.00",
    ]);
    // player 1000 - 100 + 150; house 10000 + 100 - 150
    expect(roundBalances($this))->toBe(['player' => 1050.0, 'other' => 1000.0, 'house' => 9950.0, 'escrow' => 0.0]);
});

it('settles a round in chunks', function () {
    $this->app->instance(WagerRoundService::class, app()->make(WagerRoundService::class, ['chunkSize' => 2]));
    foreach (range(1, 7) as $i) {
        roundWager($this, "w{$i}", 10, otherPlayer: $i % 2 === 0);
    }
    $transactions = 0;
    DB::listen(function ($query) use (&$transactions) {
        $transactions += str_contains($query->sql, 'from `wager_rounds`') && str_contains($query->sql, 'for update') ? 1 : 0;
    });

    // winners in 2 chunks, the other 4 in 2 full chunks and an empty one, after the opening transaction
    settleRound($this, ['winners' => [
        ['reference' => 'w1', 'payout' => 20], ['reference' => 'w2', 'payout' => 20], ['reference' => 'w3', 'payout' => 20],
    ]])->assertOk()->assertJsonPath('data.counts', roundCounts(won: 3, lost: 4));

    expect($transactions)->toBe(6);
    expect(Wager::where('status', 'won')->pluck('reference')->sort()->values()->all())->toBe(['w1', 'w2', 'w3'])
        ->and(Wager::where('status', 'lost')->pluck('reference')->sort()->values()->all())->toBe(['w4', 'w5', 'w6', 'w7']);
    // player w1, w3, w5, w7: 1000 - 40 + 40; other w2, w4, w6: 1000 - 30 + 20; house 10000 + 70 - 60
    expect(roundBalances($this))->toBe(['player' => 1000.0, 'other' => 990.0, 'house' => 10010.0, 'escrow' => 0.0]);
});

it('refuses a winner payout above the product limit', function () {
    $this->product->update(['max_payout' => 300]);
    roundWager($this, 'a', 100);
    roundWager($this, 'b', 100);

    settleRound($this, ['winners' => [['reference' => 'a', 'payout' => 300], ['reference' => 'b', 'payout' => 250, 'bonus' => 50.01]]])
        ->assertStatus(422)
        ->assertJson(['code' => 'payout_above_limit', 'retryable' => false, 'max_payout' => 300, 'references' => ['b']]);

    expect(WagerSettlement::count())->toBe(0)->and($this->round->fresh()->closed_at)->toBeNull();
});

it('refuses another game\'s product or a rake product', function () {
    roundWager($this, 'a', 100);
    $other = Game::factory()->create(['code' => 'sports']);
    Product::factory()->create(['game_id' => $other->id, 'code' => 'sports_pre']);

    settleRound($this, ['product' => 'sports_pre'])->assertForbidden()->assertJson(['success' => false, 'code' => 'product_not_allowed', 'retryable' => false]);
    voidRound($this, ['product' => 'kadi'])->assertForbidden()->assertJsonPath('code', 'product_not_allowed');

    expect($this->round->fresh()->closed_at)->toBeNull()->and(WagerRound::count())->toBe(1);
});

it('settles a round of a suspended product', function () {
    roundWager($this, 'a', 100);
    $this->product->update(['status' => Product::STATUS_SUSPENDED]);

    settleRound($this)->assertOk()->assertJsonPath('data.counts', roundCounts(lost: 1));
});

it('validates the payload', function (array $payload, string $round = 'round-1') {
    $this->postJson("/api/v1/rounds/{$round}/settle", $payload, apiHeaders($this->key->key))
        ->assertStatus(422)
        ->assertJson(['success' => false, 'code' => 'validation_failed', 'retryable' => false])
        ->assertJsonStructure(['message', 'errors']);

    expect($this->round->fresh()->closed_at)->toBeNull();
})->with([
    'no product' => [['winners' => []]],
    'no winners' => [['product' => 'crash_classic']],
    'winners not a list' => [['product' => 'crash_classic', 'winners' => 'a']],
    'duplicate references' => [['product' => 'crash_classic', 'winners' => [['reference' => 'a', 'payout' => 1], ['reference' => 'a', 'payout' => 2]]]],
    'no payout' => [['product' => 'crash_classic', 'winners' => [['reference' => 'a']]]],
    'three decimals' => [['product' => 'crash_classic', 'winners' => [['reference' => 'a', 'payout' => 1.005]]]],
    'payout above a wallet' => [['product' => 'crash_classic', 'winners' => [['reference' => 'a', 'payout' => 100000000]]]],
    'final not a boolean' => [['product' => 'crash_classic', 'winners' => [], 'final' => 'yes']],
    'round reference too long' => [['product' => 'crash_classic', 'winners' => []], str_repeat('r', 101)],
]);

it('has the right middleware on both round routes', function (string $uri) {
    $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === $uri);

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:wagers')
        ->and($route->gatherMiddleware())->not->toContain('player.token')
        ->and($route->gatherMiddleware())->not->toContain('idempotency')
        ->and(config('api_scopes.game'))->toContain("POST {$uri}");
})->with(['api/v1/rounds/{reference}/settle', 'api/v1/rounds/{reference}/void']);
