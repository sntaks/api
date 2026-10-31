<?php

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\CustomerExclusion;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Wager;
use App\Models\WagerSettlement;
use App\Models\Wallet;
use App\Models\WinningsTaxCharge;
use App\Services\PlayerTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->game = Game::factory()->create(['code' => 'slots']);
    $this->key = ApiKey::create(['key' => 'game_slots_key', 'name' => 'slots', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $this->game->id, 'is_active' => true]);
    $this->product = Product::factory()->create(['game_id' => $this->game->id, 'code' => 'slots_fruit_mania', 'max_stake' => 500, 'max_payout' => 10000]);
    $houseCustomer = Customer::factory()->create(['id' => 1]);
    $this->house = Wallet::forceCreate(['id' => 1, 'customer_id' => $houseCustomer->id, 'balance' => 10000]);
    $this->player = Customer::factory()->create(['status' => 1]);
    $this->wallet = Wallet::forceCreate(['customer_id' => $this->player->id, 'balance' => 300]);
    $this->token = app(PlayerTokenService::class)->issue($this->player, $this->game)->plainTextToken;
});

function spin(object $test, array $overrides = [])
{
    $payload = array_merge(['product' => 'slots_fruit_mania', 'reference' => 'spin-1', 'funding' => 'cash', 'stake' => 20, 'outcome' => 'lost', 'payout' => 0], $overrides);

    return $test->postJson('/api/v1/wagers/instant', array_filter($payload, fn ($value) => $value !== null), apiHeaders($test->key->key) + ['Authorization' => 'Bearer '.$test->token]);
}

/** @return list<string> "type wallet_type amount" for every entry after $afterId, in posting order */
function instantLegs(int $afterId = 0): array
{
    return LedgerEntry::where('id', '>', $afterId)->orderBy('id')->get()
        ->map(fn ($entry) => sprintf('%s %s %s%.2f', $entry->entry_type, $entry->wallet_type, (float) $entry->debit > 0 ? '-' : '+', (float) $entry->debit > 0 ? $entry->debit : $entry->credit))
        ->all();
}

it('settles a losing spin in one call', function () {
    spin($this)
        ->assertCreated()
        ->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.wager.status', 'lost')
        ->assertJsonPath('data.wager.settlement_version', 1)
        ->assertJsonPath('data.settlement.outcome', 'lost')
        ->assertJsonPath('data.balance.balance', 280);

    expect((float) $this->house->fresh()->balance)->toBe(10020.0)
        ->and((float) Wager::sole()->balance)->toBe(0.0)
        ->and(instantLegs())->toBe([
            'wager_stake wallet -20.00', 'wager_stake wager +20.00',
            'wager_stake_settled wager -20.00', 'wager_stake_settled wallet +20.00',
        ]);
});

it('pays a winning spin in one call', function () {
    spin($this, ['outcome' => 'won', 'payout' => 75, 'bonus' => 5])
        ->assertCreated()
        ->assertJsonPath('data.wager.status', 'won')
        ->assertJsonPath('data.settlement.payout', 75)
        ->assertJsonPath('data.balance.balance', 360);

    expect((float) $this->house->fresh()->balance)->toBe(9940.0)
        ->and(WagerSettlement::sole()->settled_by)->toBe(WagerSettlement::SETTLED_BY_GAME);
});

it('pays a free spin win as a promotion', function () {
    spin($this, ['funding' => 'free_bet', 'stake' => null, 'free_bet_amount' => 10, 'outcome' => 'won', 'payout' => 40])->assertCreated();

    expect((float) $this->wallet->fresh()->balance)->toBe(340.0)
        ->and(LedgerEntry::where('entry_type', 'wager_free_bet_payout')->count())->toBe(2)
        ->and(LedgerEntry::where('entry_type', 'wager_stake')->count())->toBe(0);
});

it('posts the same legs as place then settle', function () {
    spin($this, ['outcome' => 'won', 'payout' => 75])->assertCreated();
    $instant = instantLegs();
    LedgerEntry::query()->delete();

    $this->postJson('/api/v1/wagers', ['product' => 'slots_fruit_mania', 'reference' => 'spin-2', 'funding' => 'cash', 'stake' => 20], apiHeaders($this->key->key) + ['Authorization' => 'Bearer '.$this->token])->assertCreated();
    $this->postJson('/api/v1/wagers/spin-2/settle', ['product' => 'slots_fruit_mania', 'version' => 1, 'outcome' => 'won', 'payout' => 75], apiHeaders($this->key->key))->assertOk();

    expect(instantLegs())->toBe($instant);
});

it('replays an identical instant wager', function () {
    spin($this, ['outcome' => 'won', 'payout' => 75])->assertCreated();

    spin($this, ['outcome' => 'won', 'payout' => 75])
        ->assertOk()
        ->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.wager.status', 'won')
        ->assertJsonPath('data.balance.balance', 355);

    expect(Wager::count())->toBe(1)
        ->and(WagerSettlement::count())->toBe(1)
        ->and((float) $this->wallet->fresh()->balance)->toBe(355.0);
});

it('refuses the same reference with a different result', function () {
    spin($this)->assertCreated();

    spin($this, ['outcome' => 'won', 'payout' => 75])->assertStatus(409)->assertJsonPath('code', 'reference_conflict');
    spin($this, ['stake' => 30])->assertStatus(409)->assertJsonPath('code', 'reference_conflict');

    expect((float) $this->wallet->fresh()->balance)->toBe(280.0);
});

it('refuses a reference already placed through the two-step flow', function () {
    $this->postJson('/api/v1/wagers', ['product' => 'slots_fruit_mania', 'reference' => 'spin-1', 'funding' => 'cash', 'stake' => 20], apiHeaders($this->key->key) + ['Authorization' => 'Bearer '.$this->token])->assertCreated();

    spin($this)->assertStatus(409)->assertJsonPath('code', 'reference_conflict');

    expect(Wager::sole()->status)->toBe('open');
});

it('refuses a stake the player cannot cover and moves no money', function () {
    spin($this, ['stake' => 400])->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');

    expect(Wager::count())->toBe(0)->and(LedgerEntry::count())->toBe(0)->and((float) $this->wallet->fresh()->balance)->toBe(300.0);
});

it('refuses a payout above the product limit and moves no money', function () {
    spin($this, ['outcome' => 'won', 'payout' => 10001])->assertStatus(422)->assertJsonPath('code', 'payout_above_limit');

    expect(Wager::count())->toBe(0)->and(LedgerEntry::count())->toBe(0);
});

it('refuses an excluded player', function () {
    CustomerExclusion::factory()->create(['customer_id' => $this->player->id]);

    spin($this)->assertStatus(403)->assertJsonPath('code', 'player_excluded');

    expect(Wager::count())->toBe(0);
});

it('refuses a void, and a lost spin that pays', function () {
    spin($this, ['outcome' => 'void'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    spin($this, ['outcome' => 'lost', 'payout' => 5])->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    expect(Wager::count())->toBe(0);
});

it('lets a game reverse an instant wager', function () {
    spin($this, ['outcome' => 'won', 'payout' => 75])->assertCreated();

    $this->postJson('/api/v1/wagers/spin-1/reverse', ['product' => 'slots_fruit_mania', 'version' => 1, 'reason' => 'Machine fault'], apiHeaders($this->key->key))
        ->assertOk()
        ->assertJsonPath('data.wager.status', 'open');

    expect((float) $this->wallet->fresh()->balance)->toBe(280.0)
        ->and((float) Wager::sole()->balance)->toBe(20.0);
});

it('withholds winnings tax exactly as place then settle', function () {
    $this->product->update(['winnings_tax_rate' => 0.2]);

    spin($this, ['outcome' => 'won', 'payout' => 75])->assertCreated()->assertJsonPath('data.settlement.tax', 11);
    $instant = instantLegs();
    $lastInstantEntry = (int) LedgerEntry::max('id');

    $this->postJson('/api/v1/wagers', ['product' => 'slots_fruit_mania', 'reference' => 'spin-2', 'funding' => 'cash', 'stake' => 20], apiHeaders($this->key->key) + ['Authorization' => 'Bearer '.$this->token])->assertCreated();
    $this->postJson('/api/v1/wagers/spin-2/settle', ['product' => 'slots_fruit_mania', 'version' => 1, 'outcome' => 'won', 'payout' => 75], apiHeaders($this->key->key))->assertOk();

    expect(instantLegs($lastInstantEntry))->toBe($instant)
        ->and(WinningsTaxCharge::count())->toBe(2);
});

it('refuses a retry after the spin was reversed and moves no money', function () {
    spin($this, ['outcome' => 'won', 'payout' => 75])->assertCreated();
    $this->postJson('/api/v1/wagers/spin-1/reverse', ['product' => 'slots_fruit_mania', 'version' => 1, 'reason' => 'Machine fault'], apiHeaders($this->key->key))->assertOk();
    $entries = LedgerEntry::count();

    spin($this, ['outcome' => 'won', 'payout' => 75])->assertStatus(409)->assertJsonPath('code', 'reference_conflict');

    expect(LedgerEntry::count())->toBe($entries)->and((float) $this->wallet->fresh()->balance)->toBe(280.0);
});

it('pays a free spin win to a player who has no wallet yet', function () {
    $newcomer = Customer::factory()->create(['status' => 1]);
    $token = app(PlayerTokenService::class)->issue($newcomer, $this->game)->plainTextToken;

    $this->postJson('/api/v1/wagers/instant', ['product' => 'slots_fruit_mania', 'reference' => 'spin-new', 'funding' => 'free_bet', 'free_bet_amount' => 10, 'outcome' => 'won', 'payout' => 40],
        apiHeaders($this->key->key) + ['Authorization' => 'Bearer '.$token])
        ->assertCreated()
        ->assertJsonPath('data.balance.balance', 40);

    expect((float) Wallet::where('customer_id', $newcomer->id)->sole()->balance)->toBe(40.0);
});
