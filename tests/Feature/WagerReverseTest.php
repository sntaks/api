<?php

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Wager;
use App\Models\WagerSettlement;
use App\Models\Wallet;
use App\Models\Withdraw;
use App\Services\LedgerService;
use App\Services\MpesaService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->game = Game::factory()->create(['code' => 'crash']);
    $this->key = ApiKey::create(['key' => 'game_crash_key', 'name' => 'crash', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $this->game->id, 'is_active' => true]);
    $this->product = Product::factory()->create(['game_id' => $this->game->id, 'code' => 'crash_classic', 'winnings_tax_rate' => 0.2]);
    $houseCustomer = Customer::factory()->create(['id' => 1]);
    $this->house = Wallet::forceCreate(['id' => 1, 'customer_id' => $houseCustomer->id, 'balance' => 10000]);
    $this->player = Customer::factory()->phoneVerified()->create(['status' => 1, 'phone_no' => '254712345678']);
    $this->wallet = Wallet::forceCreate(['customer_id' => $this->player->id, 'balance' => 1000]);
});

/** An open wager whose cash stake really sits in escrow. */
function reversibleWager(object $test, float $stake = 100, string $reference = 'bet-1', bool $freeBet = false): Wager
{
    $attributes = [
        'product_id' => $test->product->id, 'customer_id' => $test->player->id, 'wallet_id' => $test->wallet->id,
        'api_key_id' => $test->key->id, 'reference' => $reference, 'balance' => 0,
    ];

    if ($freeBet) {
        return Wager::factory()->freeBet($stake)->create($attributes)->fresh();
    }

    $wager = Wager::factory()->create($attributes + ['stake' => $stake]);
    app(LedgerService::class)->recordWagerStake($wager, $test->wallet, $stake);

    return $wager->fresh();
}

function settleIt(object $test, array $overrides = [], string $reference = 'bet-1')
{
    $payload = array_merge(['product' => 'crash_classic', 'version' => 1, 'outcome' => 'lost', 'payout' => 0], $overrides);

    return $test->postJson("/api/v1/wagers/{$reference}/settle", $payload, apiHeaders($test->key->key));
}

function reverseIt(object $test, array $overrides = [], string $reference = 'bet-1')
{
    $payload = array_merge(['product' => 'crash_classic', 'version' => 1, 'reason' => 'game bug'], $overrides);

    return $test->postJson("/api/v1/wagers/{$reference}/reverse", $payload, apiHeaders($test->key->key));
}

/** The player spends money through the ledger, as a withdrawal would. */
function spendIt(object $test, float $amount): void
{
    $withdraw = Withdraw::create(['amount' => $amount, 'disburse' => 2, 'receipt' => 'R'.uniqid()]);
    app(LedgerService::class)->recordWithdrawal($withdraw, $test->wallet->fresh(), $amount);
}

/** @return list<string> the legs posted after the first $skip entries, in posting order */
function legsAfter(int $skip): array
{
    return LedgerEntry::orderBy('id')->get()->slice($skip)
        ->map(fn ($entry) => sprintf(
            '%s %s:%d %s%.2f p%d',
            $entry->entry_type, $entry->wallet_type, $entry->wallet_id,
            (float) $entry->debit > 0 ? '-' : '+', (float) $entry->debit > 0 ? $entry->debit : $entry->credit, $entry->product_id
        ))->values()->all();
}

function stateBalances(object $test): array
{
    return [
        'player' => (float) $test->wallet->fresh()->balance,
        'house' => (float) $test->house->fresh()->balance,
        'wager' => (float) Wager::firstOrFail()->balance,
    ];
}

it('reverses a win and reopens the wager', function () {
    $wager = reversibleWager($this);
    settleIt($this, ['outcome' => 'won', 'payout' => 250, 'bonus' => 30])->assertOk();
    // player 900 + 250 + 30 - 36 = 1144; house 10000 + 100 - 250 - 30 = 9820
    expect(stateBalances($this))->toBe(['player' => 1144.0, 'house' => 9820.0, 'wager' => 0.0]);
    $before = LedgerEntry::count();

    reverseIt($this)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.wager.status', 'open')
        ->assertJsonPath('data.wager.settlement_version', 1)
        ->assertJsonPath('data.settlement.version', 1)
        ->assertJsonPath('data.settlement.status', 'reversed');

    $p = $this->product->id;
    $w = $this->wallet->id;
    expect(legsAfter($before))->toBe([
        "winnings_tax_reversal wallet:{$w} +36.00 p{$p}",
        "wager_bonus_reversal wallet:1 +30.00 p{$p}",
        "wager_bonus_reversal wallet:{$w} -30.00 p{$p}",
        "wager_payout_reversal wallet:1 +250.00 p{$p}",
        "wager_payout_reversal wallet:{$w} -250.00 p{$p}",
        "wager_stake_settled_reversal wager:{$wager->id} +100.00 p{$p}",
        "wager_stake_settled_reversal wallet:1 -100.00 p{$p}",
    ]);
    // back to the placed state: player 900, house 10000, escrow 100
    expect(stateBalances($this))->toBe(['player' => 900.0, 'house' => 10000.0, 'wager' => 100.0]);

    $fresh = $wager->fresh();
    expect($fresh->status)->toBe('open')->and($fresh->settlement_version)->toBe(1)->and($fresh->settled_at)->toBeNull()
        ->and((float) $fresh->payout)->toBe(0.0)->and((float) $fresh->bonus)->toBe(0.0)->and((float) $fresh->tax)->toBe(0.0);

    $settlement = WagerSettlement::firstOrFail();
    expect($settlement->status)->toBe('reversed')->and($settlement->reversed_at)->not->toBeNull()->and($settlement->reversal_reason)->toBe('game bug');
    expect(LedgerEntry::where('entry_type', 'wager_payout')->pluck('status')->unique()->all())->toBe(['reversed']);
});

it('reverses a lost wager and a void wager', function () {
    $wager = reversibleWager($this);
    settleIt($this, ['outcome' => 'lost'])->assertOk();
    $before = LedgerEntry::count();

    reverseIt($this)->assertOk()->assertJsonPath('data.wager.status', 'open');

    $p = $this->product->id;
    expect(legsAfter($before))->toBe([
        "wager_stake_settled_reversal wager:{$wager->id} +100.00 p{$p}",
        "wager_stake_settled_reversal wallet:1 -100.00 p{$p}",
    ]);
    expect(stateBalances($this))->toBe(['player' => 900.0, 'house' => 10000.0, 'wager' => 100.0]);

    // a void at v2 refunds to the player; its reversal takes it back from the player into escrow
    settleIt($this, ['version' => 2, 'outcome' => 'void'])->assertOk();
    expect(stateBalances($this))->toBe(['player' => 1000.0, 'house' => 10000.0, 'wager' => 0.0]);
    $before = LedgerEntry::count();

    reverseIt($this, ['version' => 2])->assertOk()->assertJsonPath('data.settlement.version', 2);

    expect(legsAfter($before))->toBe([
        "wager_refund_reversal wager:{$wager->id} +100.00 p{$p}",
        "wager_refund_reversal wallet:{$this->wallet->id} -100.00 p{$p}",
    ]);
    expect(stateBalances($this))->toBe(['player' => 900.0, 'house' => 10000.0, 'wager' => 100.0]);
    expect($wager->fresh()->settlement_version)->toBe(2);
});

it('reverses a free-bet win and just reopens a free-bet loss', function () {
    $wager = reversibleWager($this, 50, freeBet: true);
    settleIt($this, ['outcome' => 'won', 'payout' => 80])->assertOk();
    // free-bet win: tax on the whole 80 at 0.2 = 16
    $before = LedgerEntry::count();

    reverseIt($this)->assertOk();

    $p = $this->product->id;
    $w = $this->wallet->id;
    expect(legsAfter($before))->toBe([
        "winnings_tax_reversal wallet:{$w} +16.00 p{$p}",
        "wager_free_bet_payout_reversal wallet:1 +80.00 p{$p}",
        "wager_free_bet_payout_reversal wallet:{$w} -80.00 p{$p}",
    ]);
    expect(stateBalances($this))->toBe(['player' => 1000.0, 'house' => 10000.0, 'wager' => 0.0]);

    settleIt($this, ['version' => 2, 'outcome' => 'lost'])->assertOk();
    $before = LedgerEntry::count();

    reverseIt($this, ['version' => 2])->assertOk()->assertJsonPath('data.wager.status', 'open');

    expect(LedgerEntry::count())->toBe($before)->and($wager->fresh()->status)->toBe('open')->and($wager->fresh()->settlement_version)->toBe(2);
});

it('lets a reversal take the balance below zero', function () {
    $wager = reversibleWager($this);
    settleIt($this, ['outcome' => 'won', 'payout' => 250])->assertOk();
    // player 900 + 250 - 30 tax (base 150 at 0.2) = 1120
    expect((float) $this->wallet->fresh()->balance)->toBe(1120.0);

    spendIt($this, 1100);
    expect((float) $this->wallet->fresh()->balance)->toBe(20.0);
    $before = LedgerEntry::count();

    reverseIt($this)->assertOk()->assertJsonPath('data.wager.status', 'open');

    $p = $this->product->id;
    $w = $this->wallet->id;
    expect(legsAfter($before))->toBe([
        "winnings_tax_reversal wallet:{$w} +30.00 p{$p}",
        "wager_payout_reversal wallet:1 +250.00 p{$p}",
        "wager_payout_reversal wallet:{$w} -250.00 p{$p}",
        "wager_stake_settled_reversal wager:{$wager->id} +100.00 p{$p}",
        "wager_stake_settled_reversal wallet:1 -100.00 p{$p}",
    ]);
    // 20 + 30 - 250 = -200; house back to 10000; escrow 100
    expect(stateBalances($this))->toBe(['player' => -200.0, 'house' => 10000.0, 'wager' => 100.0]);
});

it('re-settles under the next version', function () {
    $wager = reversibleWager($this);
    settleIt($this, ['outcome' => 'won', 'payout' => 250])->assertOk();
    reverseIt($this)->assertOk();
    expect(stateBalances($this))->toBe(['player' => 900.0, 'house' => 10000.0, 'wager' => 100.0]);
    $before = LedgerEntry::count();

    // the reversed version is never a fresh settle; v2 is
    settleIt($this, ['version' => 1, 'outcome' => 'lost'])->assertStatus(409)->assertJsonPath('code', 'version_out_of_order');
    settleIt($this, ['version' => 2, 'outcome' => 'lost'])
        ->assertOk()
        ->assertJsonPath('data.wager.status', 'lost')
        ->assertJsonPath('data.wager.settlement_version', 2)
        ->assertJsonPath('data.settlement.version', 2);

    $p = $this->product->id;
    expect(legsAfter($before))->toBe([
        "wager_stake_settled wager:{$wager->id} -100.00 p{$p}",
        "wager_stake_settled wallet:1 +100.00 p{$p}",
    ]);
    expect(stateBalances($this))->toBe(['player' => 900.0, 'house' => 10100.0, 'wager' => 0.0]);
    expect(WagerSettlement::orderBy('version')->pluck('status', 'version')->all())->toBe([1 => 'reversed', 2 => 'applied']);
});

it('replays an identical reversal', function () {
    reversibleWager($this);
    settleIt($this, ['outcome' => 'lost'])->assertOk();
    reverseIt($this)->assertOk()->assertJsonPath('data.replayed', false);
    $before = LedgerEntry::count();
    $balances = stateBalances($this);

    reverseIt($this, ['reason' => 'a different reason'])
        ->assertOk()
        ->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.wager.status', 'open')
        ->assertJsonPath('data.settlement.status', 'reversed');

    expect(LedgerEntry::count())->toBe($before)->and(stateBalances($this))->toBe($balances)
        ->and(WagerSettlement::firstOrFail()->reversal_reason)->toBe('game bug');
});

it('refuses reversing an open wager', function () {
    reversibleWager($this);
    $before = LedgerEntry::count();

    reverseIt($this)->assertStatus(409)->assertJson(['success' => false, 'code' => 'not_settled', 'retryable' => false]);
    expect(LedgerEntry::count())->toBe($before);

    // reopened at v1: another version is out of order, with the state
    settleIt($this, ['outcome' => 'lost'])->assertOk();
    reverseIt($this)->assertOk();
    reverseIt($this, ['version' => 2])->assertStatus(409)
        ->assertJson(['code' => 'version_out_of_order', 'status' => 'open', 'settlement_version' => 1]);

    // settled at v2: v1 is stale
    settleIt($this, ['version' => 2, 'outcome' => 'lost'])->assertOk();
    reverseIt($this, ['version' => 1])->assertStatus(409)
        ->assertJson(['code' => 'version_out_of_order', 'status' => 'lost', 'settlement_version' => 2]);
});

it('refuses another game\'s product and an unknown reference', function () {
    reversibleWager($this);
    settleIt($this, ['outcome' => 'lost'])->assertOk();
    Product::factory()->create(['game_id' => Game::factory()->create()->id, 'code' => 'other_product']);

    reverseIt($this, ['product' => 'other_product'])->assertStatus(403)->assertJsonPath('code', 'product_not_allowed');
    reverseIt($this, ['product' => 'nope'])->assertStatus(403)->assertJsonPath('code', 'product_not_allowed');
    reverseIt($this, [], 'unknown-ref')->assertStatus(404)->assertJson(['code' => 'wager_not_found', 'retryable' => false]);
    expect(WagerSettlement::firstOrFail()->status)->toBe('applied');
});

it('refuses a rake product of the key\'s own game', function () {
    $kadi = Game::where('code', 'kadi')->firstOrFail();
    $kadiKey = ApiKey::create(['key' => 'game_kadi_key', 'name' => 'kadi', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $kadi->id, 'is_active' => true]);
    $wager = Wager::factory()->create([
        'product_id' => Product::where('code', 'kadi')->value('id'), 'customer_id' => $this->player->id, 'wallet_id' => $this->wallet->id,
        'api_key_id' => $kadiKey->id, 'reference' => 'bet-1', 'balance' => 0,
    ]);

    $this->postJson('/api/v1/wagers/bet-1/reverse', ['product' => 'kadi', 'version' => 1, 'reason' => 'x'], apiHeaders($kadiKey->key))
        ->assertForbidden()->assertJson(['success' => false, 'code' => 'product_not_allowed', 'retryable' => false]);

    expect($wager->fresh()->status)->toBe('open')->and(LedgerEntry::count())->toBe(0);
});

it('refuses reversing with a full key or a site key', function () {
    reversibleWager($this);
    settleIt($this, ['outcome' => 'lost'])->assertOk();
    $fullKey = createApiKey('full-key');
    $siteKey = ApiKey::create(['key' => 'site-key', 'name' => 'site', 'scope' => ApiKey::SCOPE_SITE, 'is_active' => true]);
    $payload = ['product' => 'crash_classic', 'version' => 1, 'reason' => 'game bug'];

    $this->postJson('/api/v1/wagers/bet-1/reverse', $payload, apiHeaders($fullKey->key))
        ->assertForbidden()->assertJsonPath('code', 'product_not_allowed');
    $this->postJson('/api/v1/wagers/bet-1/reverse', $payload, apiHeaders($siteKey->key))
        ->assertForbidden()->assertJsonPath('code', 'route_not_allowed');

    expect(WagerSettlement::firstOrFail()->status)->toBe('applied')->and(Wager::firstOrFail()->status)->toBe('lost');
});

it('moves the reversal\'s money only on wallet rows it read with a lock', function () {
    reversibleWager($this);
    settleIt($this, ['outcome' => 'won', 'payout' => 250, 'bonus' => 30])->assertOk();
    $walletReads = [];
    DB::listen(function (QueryExecuted $query) use (&$walletReads) {
        if (preg_match('/^select .* from [`"]?wallets[`"]?/i', $query->sql)) {
            $walletReads[] = $query->sql;
        }
    });

    reverseIt($this)->assertOk();

    // a plain read under REPEATABLE READ may return the transaction's older snapshot and lose a committed update
    expect($walletReads)->not->toBeEmpty()->each->toContain('for update');
    expect(stateBalances($this))->toBe(['player' => 900.0, 'house' => 10000.0, 'wager' => 100.0]);
});

it('refuses reversing a version an admin settled', function () {
    $wager = reversibleWager($this);
    settleIt($this, ['outcome' => 'void'])->assertOk();
    WagerSettlement::firstOrFail()->update(['settled_by' => 'admin', 'admin_user' => 'ops']);
    $before = LedgerEntry::count();

    reverseIt($this)->assertStatus(409)->assertJsonPath('code', 'settlement_conflict');

    expect(LedgerEntry::count())->toBe($before)->and($wager->fresh()->status)->toBe('void')
        ->and(WagerSettlement::firstOrFail()->status)->toBe('applied');
});

it('refuses reversing a tombstone', function () {
    settleIt($this, ['outcome' => 'void'], 'ghost')->assertStatus(201);

    reverseIt($this, [], 'ghost')->assertStatus(409)->assertJson(['code' => 'wager_voided', 'retryable' => false]);

    expect(Wager::firstOrFail()->status)->toBe('void')->and(WagerSettlement::firstOrFail()->status)->toBe('applied');
});

it('validates the reference on settle and reverse', function () {
    $long = str_repeat('a', 101);

    settleIt($this, [], $long)->assertStatus(422)->assertJson(['success' => false, 'code' => 'validation_failed', 'retryable' => false])->assertJsonStructure(['errors']);
    reverseIt($this, [], $long)->assertStatus(422)->assertJson(['success' => false, 'code' => 'validation_failed', 'retryable' => false])->assertJsonStructure(['errors']);

    // exactly 100 characters passes validation (and is simply unknown)
    reverseIt($this, [], str_repeat('a', 100))->assertStatus(404);
    expect(Wager::count())->toBe(0);
});

it('validates the reverse body', function (array $body) {
    reversibleWager($this);
    settleIt($this, ['outcome' => 'lost'])->assertOk();

    $this->postJson('/api/v1/wagers/bet-1/reverse', $body, apiHeaders($this->key->key))
        ->assertStatus(422)->assertJson(['code' => 'validation_failed']);
})->with([
    'no product' => [['version' => 1, 'reason' => 'x']],
    'no version' => [['product' => 'crash_classic', 'reason' => 'x']],
    'version zero' => [['product' => 'crash_classic', 'version' => 0, 'reason' => 'x']],
    'no reason' => [['product' => 'crash_classic', 'version' => 1]],
    'reason too long' => [['product' => 'crash_classic', 'version' => 1, 'reason' => str_repeat('r', 256)]],
]);

it('the next deposit repays a negative balance first', function () {
    $this->wallet->update(['balance' => -200]);
    $deposit = Deposit::create(['trans_id' => 'REV-DEP-1', 'trans_time' => '20261030090000', 'trans_amount' => 500, 'bill_ref_no' => 'KK-REV', 'status' => 1]);

    app(LedgerService::class)->recordDeposit($deposit, $this->wallet->fresh(), 500);

    expect((float) $this->wallet->fresh()->balance)->toBe(300.0);
});

it('refuses a withdrawal while the balance is negative', function () {
    reversibleWager($this);
    settleIt($this, ['outcome' => 'won', 'payout' => 250])->assertOk();
    spendIt($this, 1100);
    reverseIt($this)->assertOk();
    expect((float) $this->wallet->fresh()->balance)->toBe(-200.0);

    $mpesa = Mockery::mock(MpesaService::class);
    $mpesa->shouldNotReceive('b2c');
    app()->instance(MpesaService::class, $mpesa);
    $before = LedgerEntry::count();

    $this->postJson('/api/v1/withdraw/'.encryptId($this->player->id), ['amount' => 100], apiHeaders(createApiKey('kadi-key')->key))
        ->assertStatus(400)
        ->assertJsonPath('status', 'Insufficient wallet balance for withdrawal');

    expect((float) $this->wallet->fresh()->balance)->toBe(-200.0)->and(LedgerEntry::count())->toBe($before);
});

it('has the right middleware and needs no player token', function () {
    $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === 'api/v1/wagers/{reference}/reverse');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:wagers')
        ->and($route->gatherMiddleware())->not->toContain('player.token')
        ->and($route->gatherMiddleware())->not->toContain('idempotency')
        ->and($route->gatherMiddleware())->not->toContain('throttle:write');
});
