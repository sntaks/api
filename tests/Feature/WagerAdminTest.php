<?php

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Wager;
use App\Models\WagerSettlement;
use App\Models\Wallet;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->game = Game::factory()->create(['code' => 'crash']);
    $this->key = ApiKey::create(['key' => 'game_crash_key', 'name' => 'crash', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $this->game->id, 'is_active' => true]);
    $this->gms = createApiKey('gms-key');
    $this->site = ApiKey::create(['key' => 'site_key', 'name' => 'kadi', 'scope' => ApiKey::SCOPE_SITE, 'is_active' => true]);
    $this->product = Product::factory()->create(['game_id' => $this->game->id, 'code' => 'crash_classic']);
    $houseCustomer = Customer::factory()->create(['id' => 1]);
    $this->house = Wallet::forceCreate(['id' => 1, 'customer_id' => $houseCustomer->id, 'balance' => 10000]);
    $this->player = Customer::factory()->create(['status' => 1]);
    $this->wallet = Wallet::forceCreate(['customer_id' => $this->player->id, 'balance' => 1000]);
});

/** An open wager whose cash stake really sits in escrow. */
function adminOpenWager(object $test, float $stake = 100, string $reference = 'bet-1', ?Product $product = null, ?Customer $player = null, bool $freeBet = false): Wager
{
    $product ??= $test->product;
    $player ??= $test->player;
    $wallet = Wallet::where('customer_id', $player->id)->first() ?? Wallet::forceCreate(['customer_id' => $player->id, 'balance' => 1000]);
    $attributes = [
        'product_id' => $product->id, 'customer_id' => $player->id, 'wallet_id' => $wallet->id,
        'api_key_id' => $test->key->id, 'reference' => $reference, 'balance' => 0, 'placed_at' => now(),
    ];

    if ($freeBet) {
        return Wager::factory()->freeBet($stake)->create($attributes)->fresh();
    }

    $wager = Wager::factory()->create($attributes + ['stake' => $stake]);
    app(LedgerService::class)->recordWagerStake($wager, $wallet, $stake);

    return $wager->fresh();
}

/** @return list<string> every ledger entry that references the settlement, in posting order */
function settlementLegs(WagerSettlement $settlement): array
{
    return LedgerEntry::where('referenceable_type', WagerSettlement::class)->where('referenceable_id', $settlement->id)->orderBy('id')->get()
        ->map(fn ($e) => sprintf('%s %s:%d %s%.2f p%d', $e->entry_type, $e->wallet_type, $e->wallet_id, (float) $e->debit > 0 ? '-' : '+', (float) $e->debit > 0 ? $e->debit : $e->credit, $e->product_id))->all();
}

function adminVoid(object $test, int|string $id, array $overrides = [], ?string $apiKey = null)
{
    $payload = array_merge(['reason' => 'stuck bet', 'admin_user' => 'ann'], $overrides);

    return $test->postJson("/api/v1/wagers/{$id}/void", $payload, apiHeaders($apiKey ?? $test->gms->key));
}

function gameSettle(object $test, string $reference = 'bet-1', array $overrides = [])
{
    $payload = array_merge(['product' => 'crash_classic', 'version' => 1, 'outcome' => 'void', 'payout' => 0], $overrides);

    return $test->postJson("/api/v1/wagers/{$reference}/settle", $payload, apiHeaders($test->key->key));
}

it('lets an admin void a stuck open wager', function () {
    $wager = adminOpenWager($this);

    adminVoid($this, $wager->id)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.wager.status', 'void')
        ->assertJsonPath('data.wager.settlement_version', 1)
        ->assertJsonPath('data.settlement.version', 1)
        ->assertJsonPath('data.settlement.outcome', 'void')
        ->assertJsonPath('data.settlement.settled_by', 'admin')
        ->assertJsonPath('data.settlement.admin_user', 'ann')
        ->assertJsonPath('data.settlement.reason', 'stuck bet');

    $p = $this->product->id;
    $settlement = WagerSettlement::sole();
    expect($settlement->settled_by)->toBe('admin')->and($settlement->admin_user)->toBe('ann')->and($settlement->version)->toBe(1);
    expect(settlementLegs($settlement))->toBe([
        "wager_refund wager:{$wager->id} -100.00 p{$p}",
        "wager_refund wallet:{$this->wallet->id} +100.00 p{$p}",
    ]);
    expect((float) $this->wallet->fresh()->balance)->toBe(1000.0)
        ->and((float) $this->house->fresh()->balance)->toBe(10000.0)
        ->and((float) $wager->fresh()->balance)->toBe(0.0);

    // not open any more: 409 with the state, and no second refund
    adminVoid($this, $wager->id)
        ->assertStatus(409)
        ->assertJsonPath('code', 'wager_not_open')
        ->assertJsonPath('retryable', false)
        ->assertJsonPath('status', 'void')
        ->assertJsonPath('settlement_version', 1);
    expect(WagerSettlement::count())->toBe(1)->and((float) $this->wallet->fresh()->balance)->toBe(1000.0);
});

it('voids a free-bet wager without moving money', function () {
    $wager = adminOpenWager($this, 50, 'free-1', freeBet: true);

    adminVoid($this, $wager->id)
        ->assertOk()
        ->assertJsonPath('data.wager.status', 'void')
        ->assertJsonPath('data.settlement.version', 1)
        ->assertJsonPath('data.settlement.settled_by', 'admin');

    $settlement = WagerSettlement::sole();
    expect($settlement->settled_by)->toBe('admin')->and(settlementLegs($settlement))->toBe([])
        ->and(LedgerEntry::count())->toBe(0)
        ->and((float) $this->wallet->fresh()->balance)->toBe(1000.0)
        ->and((float) $this->house->fresh()->balance)->toBe(10000.0)
        ->and((float) $wager->fresh()->balance)->toBe(0.0);
});

it('voids a reopened wager at the next version and refuses a settled one', function () {
    $reopened = adminOpenWager($this, 100, 'reopened');
    $reverse = fn (int $version) => $this->postJson('/api/v1/wagers/reopened/reverse', ['product' => 'crash_classic', 'version' => $version, 'reason' => 'redo'], apiHeaders($this->key->key))->assertOk();
    gameSettle($this, 'reopened', ['version' => 1, 'outcome' => 'lost'])->assertOk();
    $reverse(1);
    gameSettle($this, 'reopened', ['version' => 2, 'outcome' => 'won', 'payout' => 150])->assertOk();
    $reverse(2);
    expect($reopened->fresh()->status)->toBe('open')->and($reopened->fresh()->settlement_version)->toBe(2)
        ->and((float) $this->wallet->fresh()->balance)->toBe(900.0)
        ->and((float) $reopened->fresh()->balance)->toBe(100.0)
        ->and((float) $this->house->fresh()->balance)->toBe(10000.0);

    adminVoid($this, $reopened->id)->assertOk()->assertJsonPath('data.settlement.version', 3)->assertJsonPath('data.wager.settlement_version', 3);

    $p = $this->product->id;
    $voidSettlement = WagerSettlement::where('wager_id', $reopened->id)->where('version', 3)->sole();
    expect($voidSettlement->settled_by)->toBe('admin')
        ->and(WagerSettlement::where('wager_id', $reopened->id)->where('status', 'reversed')->count())->toBe(2)
        ->and(settlementLegs($voidSettlement))->toBe([
            "wager_refund wager:{$reopened->id} -100.00 p{$p}",
            "wager_refund wallet:{$this->wallet->id} +100.00 p{$p}",
        ])
        ->and((float) $this->wallet->fresh()->balance)->toBe(1000.0)
        ->and((float) $reopened->fresh()->balance)->toBe(0.0)
        ->and((float) $this->house->fresh()->balance)->toBe(10000.0);
    $won = adminOpenWager($this, 100, 'won-bet');
    gameSettle($this, 'won-bet', ['outcome' => 'won', 'payout' => 200])->assertOk();
    adminVoid($this, $won->id)->assertStatus(409)->assertJsonPath('code', 'wager_not_open')->assertJsonPath('status', 'won');

    $tombstone = Wager::factory()->create(['product_id' => $this->product->id, 'api_key_id' => $this->key->id, 'reference' => 'ghost', 'status' => 'void', 'is_tombstone' => true, 'customer_id' => null, 'wallet_id' => null, 'stake' => 0]);
    adminVoid($this, $tombstone->id)->assertStatus(409)->assertJsonPath('code', 'wager_not_open');
});

it('refuses an admin void that has no reason or admin user, or an unknown id', function () {
    $wager = adminOpenWager($this);

    adminVoid($this, $wager->id, ['reason' => ''])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    adminVoid($this, $wager->id, ['admin_user' => str_repeat('a', 101)])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    adminVoid($this, 99999)->assertStatus(404)->assertJsonPath('code', 'wager_not_found');
    adminVoid($this, 'abc')->assertStatus(404)->assertJsonPath('code', 'wager_not_found');
    expect($wager->fresh()->status)->toBe('open');
});

it('refuses the admin void to a game key', function () {
    $wager = adminOpenWager($this);

    adminVoid($this, $wager->id, apiKey: $this->key->key)->assertForbidden()->assertJsonPath('code', 'route_not_allowed');
});

it('refuses the game\'s later settle of an admin-voided version', function () {
    $wager = adminOpenWager($this);
    adminVoid($this, $wager->id)->assertOk();

    // identical payload to the admin's void: still a conflict, because the admin settled that version
    gameSettle($this, 'bet-1', ['version' => 1, 'outcome' => 'void', 'payout' => 0, 'reason' => 'stuck bet'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'settlement_conflict');

    expect(WagerSettlement::count())->toBe(1)->and((float) $this->wallet->fresh()->balance)->toBe(1000.0);
});

it('lists a customer\'s wagers by game for kadi', function () {
    $other = Game::factory()->create(['code' => 'sports']);
    $sportsProduct = Product::factory()->create(['game_id' => $other->id, 'code' => 'sports_pre']);
    $old = adminOpenWager($this, 10, 'old');
    $old->update(['placed_at' => now()->subDay()]);
    adminOpenWager($this, 20, 'new');
    adminOpenWager($this, 30, 'sports-1', $sportsProduct);
    Wager::factory()->create(['product_id' => $this->product->id, 'api_key_id' => $this->key->id, 'reference' => 'ghost', 'status' => 'void', 'is_tombstone' => true, 'customer_id' => $this->player->id, 'wallet_id' => null, 'stake' => 0]);
    $stranger = Customer::factory()->create(['status' => 1]);
    adminOpenWager($this, 40, 'stranger-bet', null, $stranger);

    $url = '/api/v1/customers/'.encryptId($this->player->id).'/wagers';
    $headers = apiHeaders($this->site->key);

    $all = $this->getJson($url, $headers)->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.pagination.total', 3);
    expect(collect($all->json('data.items'))->pluck('reference')->all())->toBe(['sports-1', 'new', 'old'])
        ->and($all->json('data.items.0.game'))->toBe('sports');

    $byGame = $this->getJson($url.'?game=crash', $headers)->assertOk();
    expect(collect($byGame->json('data.items'))->pluck('reference')->all())->toBe(['new', 'old']);

    $byProduct = $this->getJson($url.'?product=sports_pre', $headers)->assertOk();
    expect(collect($byProduct->json('data.items'))->pluck('reference')->all())->toBe(['sports-1']);

    $this->getJson($url.'?status=won', $headers)->assertOk()->assertJsonPath('data.pagination.total', 0);
    $this->getJson($url.'?status=bogus', $headers)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    $this->getJson($url.'?per_page=1', $headers)->assertOk()->assertJsonPath('data.pagination.last_page', 3);
    $this->getJson('/api/v1/customers/'.encryptId(999999).'/wagers', $headers)->assertStatus(404);
});

it('searches wagers by product and status for GMS', function () {
    $other = Game::factory()->create(['code' => 'sports']);
    $sportsProduct = Product::factory()->create(['game_id' => $other->id, 'code' => 'sports_pre']);
    $a = adminOpenWager($this, 10, 'a');
    $a->update(['placed_at' => now()->subDays(3)]);
    $b = adminOpenWager($this, 20, 'b');
    $b->update(['status' => 'lost', 'settlement_version' => 1]);
    adminOpenWager($this, 30, 's1', $sportsProduct);
    $stranger = Customer::factory()->create(['status' => 1]);
    adminOpenWager($this, 40, 'x1', null, $stranger);

    $search = fn (string $query) => collect($this->getJson('/api/v1/wagers'.$query, apiHeaders($this->gms->key))->assertOk()->json('data.items'))->pluck('reference')->all();

    expect($search(''))->toBe(['x1', 's1', 'b', 'a']);
    expect($search('?product=crash_classic'))->toBe(['x1', 'b', 'a']);
    expect($search('?product=crash_classic&status=lost'))->toBe(['b']);
    expect($search('?customer='.$stranger->id))->toBe(['x1']);
    expect($search('?reference=s1'))->toBe(['s1']);
    expect($search('?from='.now()->subDay()->toDateString()))->toBe(['x1', 's1', 'b']);
    expect($search('?to='.now()->subDays(2)->toDateString()))->toBe(['a']);

    $this->getJson('/api/v1/wagers?status=bogus', apiHeaders($this->gms->key))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    $this->getJson('/api/v1/wagers', apiHeaders($this->site->key))->assertForbidden();
    $this->getJson('/api/v1/wagers', apiHeaders($this->key->key))->assertForbidden();
});

it('shows a wager with its settlements and ledger lines to GMS', function () {
    $wager = adminOpenWager($this);
    gameSettle($this, 'bet-1', ['outcome' => 'won', 'payout' => 200])->assertOk();

    $detail = $this->getJson("/api/v1/wagers/{$wager->id}/detail", apiHeaders($this->gms->key))->assertOk()
        ->assertJsonPath('data.wager.id', $wager->id)
        ->assertJsonPath('data.wager.reference', 'bet-1')
        ->assertJsonPath('data.wager.customer_id', $this->player->id)
        ->assertJsonPath('data.settlements.0.outcome', 'won')
        ->assertJsonPath('data.settlements.0.admin_user', null);

    expect(collect($detail->json('data.ledger_entries'))->pluck('entry_type')->all())
        ->toBe(['wager_stake', 'wager_stake', 'wager_stake_settled', 'wager_stake_settled', 'wager_payout', 'wager_payout']);

    $this->getJson("/api/v1/wagers/{$wager->id}/detail", apiHeaders($this->key->key))->assertForbidden();
    $this->getJson('/api/v1/wagers/99999/detail', apiHeaders($this->gms->key))->assertStatus(404)->assertJsonPath('code', 'wager_not_found');
    $this->getJson('/api/v1/wagers/abc/detail', apiHeaders($this->gms->key))->assertStatus(404)->assertJsonPath('code', 'wager_not_found');
});

it('looks up a wager by reference for its game only', function () {
    adminOpenWager($this);
    gameSettle($this, 'bet-1', ['outcome' => 'lost'])->assertOk();
    Wager::factory()->create(['product_id' => $this->product->id, 'api_key_id' => $this->key->id, 'reference' => 'ghost', 'status' => 'void', 'is_tombstone' => true, 'customer_id' => null, 'wallet_id' => null, 'stake' => 0]);

    $this->getJson('/api/v1/wagers/bet-1?product=crash_classic', apiHeaders($this->key->key))->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.wager.reference', 'bet-1')
        ->assertJsonPath('data.wager.status', 'lost')
        ->assertJsonPath('data.settlements.0.outcome', 'lost');

    $this->getJson('/api/v1/wagers/ghost?product=crash_classic', apiHeaders($this->key->key))->assertOk()
        ->assertJsonPath('data.wager.reference', 'ghost');

    $this->getJson('/api/v1/wagers/nope?product=crash_classic', apiHeaders($this->key->key))->assertStatus(404)
        ->assertJsonPath('code', 'wager_not_found')->assertJsonPath('retryable', false);
    $this->getJson('/api/v1/wagers/bet-1', apiHeaders($this->key->key))->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    // another game's key cannot see it, and a product outside the key's game is refused
    $otherGame = Game::factory()->create(['code' => 'sports']);
    $otherKey = ApiKey::create(['key' => 'game_sports_key', 'name' => 'sports', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $otherGame->id, 'is_active' => true]);
    $this->getJson('/api/v1/wagers/bet-1?product=crash_classic', apiHeaders($otherKey->key))->assertStatus(403)->assertJsonPath('code', 'product_not_allowed');

    $this->getJson('/api/v1/wagers/bet-1?product=crash_classic', apiHeaders($this->site->key))->assertForbidden();
});

it('refuses the GMS-only filters on the customer history route', function () {
    $url = '/api/v1/customers/'.encryptId($this->player->id).'/wagers';
    adminOpenWager($this);

    foreach (['customer=5', 'reference=bet-1', 'from=2026-01-01', 'to=2026-12-31'] as $query) {
        $this->getJson($url.'?'.$query, apiHeaders($this->site->key))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('retryable', false);
    }

    $this->getJson($url.'?game=crash&status=open&per_page=5', apiHeaders($this->site->key))->assertOk()->assertJsonPath('data.pagination.total', 1);
});
