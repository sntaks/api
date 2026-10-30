<?php

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Wager;
use App\Models\WagerSettlement;
use App\Models\Wallet;
use App\Services\ChartOfAccounts;
use App\Services\LedgerService;
use App\Services\WalletWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->game = Game::factory()->create(['code' => 'crash']);
    $this->key = ApiKey::create(['key' => 'game_crash_key', 'name' => 'crash', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $this->game->id, 'is_active' => true]);
    $this->product = Product::factory()->create(['game_id' => $this->game->id, 'code' => 'crash_classic']);
    $houseCustomer = Customer::factory()->create(['id' => 1]);
    $this->house = Wallet::forceCreate(['id' => 1, 'customer_id' => $houseCustomer->id, 'balance' => 10000]);
    $this->player = Customer::factory()->create(['status' => 1]);
    $this->wallet = Wallet::forceCreate(['customer_id' => $this->player->id, 'balance' => 1000]);
});

/** An open wager whose cash stake really sits in escrow (the player wallet is debited through the ledger). */
function openWager(object $test, float $stake = 100, string $reference = 'bet-1', bool $freeBet = false): Wager
{
    $factory = Wager::factory();
    $attributes = [
        'product_id' => $test->product->id, 'customer_id' => $test->player->id, 'wallet_id' => $test->wallet->id,
        'api_key_id' => $test->key->id, 'reference' => $reference, 'balance' => 0,
    ];

    if ($freeBet) {
        $wager = $factory->freeBet($stake)->create($attributes);
    } else {
        $wager = $factory->create($attributes + ['stake' => $stake]);
        app(LedgerService::class)->recordWagerStake($wager, $test->wallet, $stake);
    }

    return $wager->fresh();
}

function settleWager(object $test, array $overrides = [], string $reference = 'bet-1', ?string $apiKey = null)
{
    $payload = array_merge(['product' => 'crash_classic', 'version' => 1, 'outcome' => 'lost', 'payout' => 0], $overrides);

    return $test->postJson("/api/v1/wagers/{$reference}/settle", $payload, apiHeaders($apiKey ?? $test->key->key));
}

/** @return list<string> "type wallet_type:id sign-amount product" for every settlement entry, in posting order */
function legs(): array
{
    return LedgerEntry::orderBy('id')->get()
        ->reject(fn ($entry) => $entry->entry_type === 'wager_stake')
        ->map(fn ($entry) => sprintf(
            '%s %s:%d %s%.2f p%d',
            $entry->entry_type, $entry->wallet_type, $entry->wallet_id,
            (float) $entry->debit > 0 ? '-' : '+', (float) $entry->debit > 0 ? $entry->debit : $entry->credit, $entry->product_id
        ))->values()->all();
}

function balances(object $test): array
{
    return [
        'player' => (float) $test->wallet->fresh()->balance,
        'house' => (float) $test->house->fresh()->balance,
        'wager' => (float) Wager::firstOrFail()->balance,
    ];
}

it('settles a lost cash wager to the house', function () {
    $wager = openWager($this);

    settleWager($this, ['outcome' => 'lost', 'reason' => 'crashed'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.wager.status', 'lost')
        ->assertJsonPath('data.wager.settlement_version', 1)
        ->assertJsonPath('data.settlement.version', 1)
        ->assertJsonPath('data.settlement.outcome', 'lost');

    $p = $this->product->id;
    expect(legs())->toBe([
        "wager_stake_settled wager:{$wager->id} -100.00 p{$p}",
        "wager_stake_settled wallet:1 +100.00 p{$p}",
    ]);
    expect(balances($this))->toBe(['player' => 900.0, 'house' => 10100.0, 'wager' => 0.0]);

    $fresh = $wager->fresh();
    expect($fresh->status)->toBe('lost')->and($fresh->settlement_version)->toBe(1)->and($fresh->settled_at)->not->toBeNull()
        ->and((float) $fresh->payout)->toBe(0.0);

    $settlement = WagerSettlement::firstOrFail();
    expect($settlement->settled_by)->toBe('game')->and($settlement->status)->toBe('applied')->and($settlement->reason)->toBe('crashed')
        ->and($settlement->wager_id)->toBe($wager->id);
});

it('pays a won cash wager from the house', function () {
    $wager = openWager($this);

    settleWager($this, ['outcome' => 'won', 'payout' => 250])
        ->assertOk()
        ->assertJsonPath('data.wager.status', 'won')
        ->assertJsonPath('data.settlement.payout', 250);

    $p = $this->product->id;
    expect(legs())->toBe([
        "wager_stake_settled wager:{$wager->id} -100.00 p{$p}",
        "wager_stake_settled wallet:1 +100.00 p{$p}",
        "wager_payout wallet:1 -250.00 p{$p}",
        "wager_payout wallet:{$this->wallet->id} +250.00 p{$p}",
    ]);
    // 1000 - 100 stake + 250; house 10000 + 100 - 250
    expect(balances($this))->toBe(['player' => 1150.0, 'house' => 9850.0, 'wager' => 0.0]);
    expect((float) $wager->fresh()->payout)->toBe(250.0)->and((float) $wager->fresh()->tax)->toBe(0.0);
});

it('pays a free-bet win as a promotion', function () {
    openWager($this, 50, freeBet: true);

    settleWager($this, ['outcome' => 'won', 'payout' => 80])->assertOk()->assertJsonPath('data.wager.status', 'won');

    $p = $this->product->id;
    expect(legs())->toBe([
        "wager_free_bet_payout wallet:1 -80.00 p{$p}",
        "wager_free_bet_payout wallet:{$this->wallet->id} +80.00 p{$p}",
    ]);
    expect(balances($this))->toBe(['player' => 1080.0, 'house' => 9920.0, 'wager' => 0.0]);
    expect(app(ChartOfAccounts::class)->categoryFor('wager_free_bet_payout'))->toBe('promotion');

    // a lost free bet posts nothing
    $second = openWager($this, 20, 'bet-2', freeBet: true);
    $before = LedgerEntry::count();
    settleWager($this, ['outcome' => 'lost'], 'bet-2')->assertOk();
    expect(LedgerEntry::count())->toBe($before)->and($second->fresh()->status)->toBe('lost');
});

it('refunds a void cash wager', function () {
    $wager = openWager($this);

    settleWager($this, ['outcome' => 'void', 'reason' => 'round cancelled'])
        ->assertOk()
        ->assertJsonPath('data.wager.status', 'void');

    $p = $this->product->id;
    expect(legs())->toBe([
        "wager_refund wager:{$wager->id} -100.00 p{$p}",
        "wager_refund wallet:{$this->wallet->id} +100.00 p{$p}",
    ]);
    expect(balances($this))->toBe(['player' => 1000.0, 'house' => 10000.0, 'wager' => 0.0]);
    expect($wager->fresh()->is_tombstone)->toBeFalse();
});

it('pays the bonus and withholds tax', function () {
    $this->product->update(['winnings_tax_rate' => 0.2]);
    $wager = openWager($this);

    settleWager($this, ['outcome' => 'won', 'payout' => 250, 'bonus' => 30])
        ->assertOk()
        ->assertJsonPath('data.settlement.tax', 36)
        ->assertJsonPath('data.settlement.tax_base', 180)
        ->assertJsonPath('data.settlement.bonus', 30);

    $p = $this->product->id;
    expect(legs())->toBe([
        "wager_stake_settled wager:{$wager->id} -100.00 p{$p}",
        "wager_stake_settled wallet:1 +100.00 p{$p}",
        "wager_payout wallet:1 -250.00 p{$p}",
        "wager_payout wallet:{$this->wallet->id} +250.00 p{$p}",
        "wager_bonus wallet:1 -30.00 p{$p}",
        "wager_bonus wallet:{$this->wallet->id} +30.00 p{$p}",
        "winnings_tax wallet:{$this->wallet->id} -36.00 p{$p}",
    ]);
    // player 900 + 250 + 30 - 36; house 10000 + 100 - 250 - 30
    expect(balances($this))->toBe(['player' => 1144.0, 'house' => 9820.0, 'wager' => 0.0]);

    $settlement = WagerSettlement::firstOrFail();
    expect((float) $settlement->tax)->toBe(36.0)->and((float) $settlement->tax_base)->toBe(180.0)->and((float) $settlement->tax_rate)->toBe(0.2);
    expect((float) $wager->fresh()->tax)->toBe(36.0)->and((float) $wager->fresh()->bonus)->toBe(30.0);
    expect(app(ChartOfAccounts::class)->categoryFor('winnings_tax'))->toBe('tax_withheld');
});

it('taxes a free-bet win on the whole winnings and a cash win on its profit only', function () {
    $this->product->update(['winnings_tax_rate' => 0.1]);
    openWager($this, 50, freeBet: true);
    settleWager($this, ['outcome' => 'won', 'payout' => 80, 'bonus' => 20])
        ->assertOk()->assertJsonPath('data.settlement.tax_base', 100)->assertJsonPath('data.settlement.tax', 10);

    // a cash win that returns only the stake has no profit and no tax
    openWager($this, 100, 'bet-2');
    settleWager($this, ['outcome' => 'won', 'payout' => 100], 'bet-2')
        ->assertOk()->assertJsonPath('data.settlement.tax_base', 0)->assertJsonPath('data.settlement.tax', 0);
    expect(LedgerEntry::where('entry_type', 'winnings_tax')->count())->toBe(1);
});

it('tells kadi a taxed win and its reversal are game changes', function () {
    $this->product->update(['winnings_tax_rate' => 0.2]);
    openWager($this);
    $webhooks = app(WalletWebhookService::class);

    settleWager($this, ['outcome' => 'won', 'payout' => 250])->assertOk();
    expect(LedgerEntry::latest('id')->value('entry_type'))->toBe('winnings_tax')
        ->and($webhooks->buildSnapshot($this->wallet->id, $this->player->id)['reason'])->toBe('game');

    // a reversal posts the tax back first; were it the player's latest entry, it reads as a game change too
    LedgerEntry::latest('id')->firstOrFail()->update(['entry_type' => 'winnings_tax_reversal']);
    expect($webhooks->buildSnapshot($this->wallet->id, $this->player->id)['reason'])->toBe('game');
});

it('does not tax a cash win that pays back less than the stake', function () {
    $this->product->update(['winnings_tax_rate' => 0.2]);
    openWager($this, 100);

    settleWager($this, ['outcome' => 'won', 'payout' => 40])
        ->assertOk()->assertJsonPath('data.settlement.tax_base', 0)->assertJsonPath('data.settlement.tax', 0);

    expect(LedgerEntry::where('entry_type', 'winnings_tax')->count())->toBe(0);
    // player 900 + 40; house 10000 + 100 - 40
    expect(balances($this))->toBe(['player' => 940.0, 'house' => 10060.0, 'wager' => 0.0]);
});

it('rounds the tax half up in whole cents', function () {
    // 0.90 profit at 35% is exactly 0.315: half up is 0.32 (a float product reads 31.4999… cents and rounds to 0.31)
    $this->product->update(['winnings_tax_rate' => 0.35]);
    openWager($this, 100);

    settleWager($this, ['outcome' => 'won', 'payout' => 100.90])
        ->assertOk()->assertJsonPath('data.settlement.tax_base', 0.9)->assertJsonPath('data.settlement.tax', 0.32);

    expect((float) LedgerEntry::where('entry_type', 'winnings_tax')->value('debit'))->toBe(0.32);
});

it('refuses a payout or bonus above what a wallet can hold', function (array $overrides) {
    openWager($this);

    settleWager($this, ['outcome' => 'won'] + $overrides)
        ->assertStatus(422)->assertJson(['code' => 'validation_failed', 'retryable' => false]);

    expect(Wager::firstOrFail()->status)->toBe('open')->and(WagerSettlement::count())->toBe(0);
})->with([
    'payout' => [['payout' => 100000000]],
    'bonus' => [['payout' => 10, 'bonus' => 100000000]],
]);

it('refuses a rake product of the key\'s own game', function () {
    $kadi = Game::where('code', 'kadi')->firstOrFail();
    $kadiKey = ApiKey::create(['key' => 'game_kadi_key', 'name' => 'kadi', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $kadi->id, 'is_active' => true]);
    $wager = Wager::factory()->create([
        'product_id' => Product::where('code', 'kadi')->value('id'), 'customer_id' => $this->player->id, 'wallet_id' => $this->wallet->id,
        'api_key_id' => $kadiKey->id, 'reference' => 'bet-1', 'balance' => 0,
    ]);

    settleWager($this, ['product' => 'kadi', 'outcome' => 'won', 'payout' => 250], apiKey: $kadiKey->key)
        ->assertForbidden()->assertJson(['success' => false, 'code' => 'product_not_allowed', 'retryable' => false]);
    settleWager($this, ['product' => 'kadi', 'outcome' => 'void'], 'ghost', $kadiKey->key)
        ->assertForbidden()->assertJsonPath('code', 'product_not_allowed');

    expect($wager->fresh()->status)->toBe('open')->and(Wager::count())->toBe(1)->and(LedgerEntry::count())->toBe(0);
});

it('refuses settling with a full key or a site key', function () {
    openWager($this);
    $fullKey = createApiKey('full-key');
    $siteKey = ApiKey::create(['key' => 'site-key', 'name' => 'site', 'scope' => ApiKey::SCOPE_SITE, 'is_active' => true]);

    settleWager($this, apiKey: $fullKey->key)->assertForbidden()->assertJsonPath('code', 'product_not_allowed');
    settleWager($this, apiKey: $siteKey->key)->assertForbidden()->assertJsonPath('code', 'route_not_allowed');

    expect(Wager::firstOrFail()->status)->toBe('open')->and(WagerSettlement::count())->toBe(0);
});

it('settles a win even when the house balance goes negative', function () {
    $this->house->update(['balance' => 50]);
    openWager($this);

    settleWager($this, ['outcome' => 'won', 'payout' => 250])->assertOk();

    // 50 + 100 stake - 250 payout
    expect(balances($this))->toBe(['player' => 1150.0, 'house' => -100.0, 'wager' => 0.0]);
});

it('refuses a settlement whose version skips one', function () {
    openWager($this);
    $before = LedgerEntry::count();

    settleWager($this, ['version' => 2])
        ->assertStatus(409)
        ->assertJson(['success' => false, 'code' => 'version_out_of_order', 'retryable' => false, 'status' => 'open', 'settlement_version' => 0]);

    expect(LedgerEntry::count())->toBe($before)->and(Wager::firstOrFail()->status)->toBe('open');
});

it('refuses a replayed version with a different payout', function () {
    openWager($this);
    settleWager($this, ['outcome' => 'won', 'payout' => 250])->assertOk();
    $before = LedgerEntry::count();

    settleWager($this, ['outcome' => 'won', 'payout' => 300])
        ->assertStatus(409)
        ->assertJson(['code' => 'settlement_conflict', 'retryable' => false]);
    settleWager($this, ['outcome' => 'lost', 'payout' => 0])->assertStatus(409)->assertJsonPath('code', 'settlement_conflict');

    expect(LedgerEntry::count())->toBe($before)->and(balances($this)['player'])->toBe(1150.0);
});

it('replays an identical settlement', function () {
    openWager($this);
    settleWager($this, ['outcome' => 'won', 'payout' => 250, 'bonus' => 10, 'reason' => 'first'])->assertOk();
    $before = LedgerEntry::count();
    $balances = balances($this);

    settleWager($this, ['outcome' => 'won', 'payout' => 250.00, 'bonus' => 10.0, 'reason' => 'a different reason'])
        ->assertOk()
        ->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.settlement.version', 1);

    expect(LedgerEntry::count())->toBe($before)->and(balances($this))->toBe($balances)->and(WagerSettlement::count())->toBe(1);
});

it('refuses settling another game\'s wager', function () {
    $other = Game::factory()->create(['code' => 'sports']);
    $otherProduct = Product::factory()->create(['game_id' => $other->id, 'code' => 'sports_pre']);
    $wager = Wager::factory()->create([
        'product_id' => $otherProduct->id, 'customer_id' => $this->player->id, 'wallet_id' => $this->wallet->id,
        'api_key_id' => $this->key->id, 'reference' => 'bet-1',
    ]);

    settleWager($this, ['product' => 'sports_pre'])
        ->assertStatus(403)
        ->assertJson(['success' => false, 'code' => 'product_not_allowed', 'retryable' => false]);

    expect($wager->fresh()->status)->toBe('open')->and(LedgerEntry::count())->toBe(0);
});

it('tombstones a void for an unknown reference', function () {
    settleWager($this, ['version' => 1, 'outcome' => 'void', 'reason' => 'game restarted'], 'ghost-1')
        ->assertCreated()
        ->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.wager.status', 'void')
        ->assertJsonPath('data.settlement.outcome', 'void');

    $wager = Wager::firstOrFail();
    expect($wager->is_tombstone)->toBeTrue()->and($wager->status)->toBe('void')->and((float) $wager->stake)->toBe(0.0)
        ->and($wager->settlement_version)->toBe(1)->and($wager->customer_id)->toBeNull()->and($wager->wallet_id)->toBeNull()
        ->and($wager->reference)->toBe('ghost-1')->and($wager->api_key_id)->toBe($this->key->id)
        ->and(WagerSettlement::firstOrFail()->settled_by)->toBe('game')
        ->and(LedgerEntry::count())->toBe(0);

    // repeating it replays
    settleWager($this, ['version' => 1, 'outcome' => 'void'], 'ghost-1')->assertOk()->assertJsonPath('data.replayed', true);
    expect(Wager::count())->toBe(1);

    // a win or a loss on an unknown reference is a 404
    settleWager($this, ['outcome' => 'lost'], 'nobody')->assertNotFound()->assertJson(['code' => 'wager_not_found', 'retryable' => false]);
    settleWager($this, ['outcome' => 'won', 'payout' => 5], 'nobody')->assertNotFound();
    expect(Wager::count())->toBe(1);
});

it('settles a wager of a suspended product', function () {
    openWager($this);
    $this->product->update(['status' => Product::STATUS_SUSPENDED]);

    settleWager($this, ['outcome' => 'lost'])->assertOk()->assertJsonPath('data.wager.status', 'lost');

    expect(balances($this))->toBe(['player' => 900.0, 'house' => 10100.0, 'wager' => 0.0]);
});

it('refuses a payout above the product limit and a paying loss', function () {
    $this->product->update(['max_payout' => 300]);
    openWager($this);

    settleWager($this, ['outcome' => 'won', 'payout' => 250, 'bonus' => 50.01])
        ->assertStatus(422)->assertJson(['code' => 'payout_above_limit', 'retryable' => false]);
    settleWager($this, ['outcome' => 'lost', 'payout' => 5])->assertStatus(422);
    settleWager($this, ['outcome' => 'void', 'bonus' => 5])->assertStatus(422);
    settleWager($this, ['outcome' => 'void', 'bonus' => 5], 'unknown')->assertStatus(422);

    expect(Wager::count())->toBe(1)->and(Wager::firstOrFail()->status)->toBe('open')->and(LedgerEntry::count())->toBe(2);

    settleWager($this, ['outcome' => 'won', 'payout' => 250, 'bonus' => 50])->assertOk();
});

it('refuses a settle on a wager that is already settled or whose settlement was reversed', function () {
    $wager = openWager($this);
    settleWager($this, ['outcome' => 'lost'])->assertOk();

    // settled at v1: v2 needs a reversal first
    settleWager($this, ['version' => 2, 'outcome' => 'won', 'payout' => 10])
        ->assertStatus(409)->assertJson(['code' => 'version_out_of_order', 'status' => 'lost', 'settlement_version' => 1]);

    // reopened at v1 (reversed): the same v1 is never a replay
    Wager::firstOrFail()->update(['status' => 'open']);
    WagerSettlement::firstOrFail()->update(['status' => 'reversed']);

    settleWager($this, ['version' => 1, 'outcome' => 'lost'])
        ->assertStatus(409)->assertJson(['code' => 'version_out_of_order', 'status' => 'open', 'settlement_version' => 1]);
    settleWager($this, ['version' => 2, 'outcome' => 'lost'])->assertOk();
});

it('refuses a game settle for a version an admin already settled', function () {
    openWager($this);
    settleWager($this, ['outcome' => 'void'])->assertOk();
    WagerSettlement::firstOrFail()->update(['settled_by' => 'admin', 'admin_user' => 'ops']);

    settleWager($this, ['outcome' => 'void'])->assertStatus(409)->assertJsonPath('code', 'settlement_conflict');
});

it('has the right middleware and needs no player token', function () {
    $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === 'api/v1/wagers/{reference}/settle');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:wagers')
        ->and($route->gatherMiddleware())->not->toContain('player.token')
        ->and($route->gatherMiddleware())->not->toContain('idempotency')
        ->and($route->gatherMiddleware())->not->toContain('throttle:write');
});
