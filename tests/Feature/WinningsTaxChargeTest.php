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
use App\Models\WinningsTaxCharge;
use App\Models\WinningsTaxRemittance;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->game = Game::factory()->create(['code' => 'crash']);
    $this->key = ApiKey::create(['key' => 'game_crash_key', 'name' => 'crash', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $this->game->id, 'is_active' => true]);
    $this->product = Product::factory()->create(['game_id' => $this->game->id, 'code' => 'crash_classic', 'winnings_tax_rate' => 0.2]);
    $houseCustomer = Customer::factory()->create(['id' => 1]);
    $this->house = Wallet::forceCreate(['id' => 1, 'customer_id' => $houseCustomer->id, 'balance' => 10000]);
    $this->player = Customer::factory()->create(['status' => 1]);
    $this->wallet = Wallet::forceCreate(['customer_id' => $this->player->id, 'balance' => 1000]);
});

/** An open cash wager whose stake really sits in escrow, optionally in a round. */
function taxedWager(object $test, string $reference = 'bet-1', ?WagerRound $round = null): Wager
{
    $wager = Wager::factory()->create([
        'product_id' => $test->product->id, 'customer_id' => $test->player->id, 'wallet_id' => $test->wallet->id,
        'api_key_id' => $test->key->id, 'reference' => $reference, 'balance' => 0, 'stake' => 100,
        'wager_round_id' => $round?->id,
    ]);
    app(LedgerService::class)->recordWagerStake($wager, $test->wallet->fresh(), 100);

    return $wager->fresh();
}

function settleTaxedWager(object $test, array $overrides = [], string $reference = 'bet-1')
{
    $payload = array_merge(['product' => 'crash_classic', 'version' => 1, 'outcome' => 'won', 'payout' => 280], $overrides);

    return $test->postJson("/api/v1/wagers/{$reference}/settle", $payload, apiHeaders($test->key->key));
}

function reverseTaxedWager(object $test, int $version = 1, string $reference = 'bet-1')
{
    return $test->postJson("/api/v1/wagers/{$reference}/reverse", ['product' => 'crash_classic', 'version' => $version, 'reason' => 'Wrong result'], apiHeaders($test->key->key));
}

it('records a charge when a won wager withholds tax', function () {
    $wager = taxedWager($this);

    settleTaxedWager($this)->assertOk()->assertJsonPath('data.settlement.tax', 36);

    $charge = WinningsTaxCharge::sole();
    $settlement = WagerSettlement::sole();

    expect($charge->kind)->toBe(WinningsTaxCharge::KIND_CHARGE)
        ->and($charge->wager_settlement_id)->toBe($settlement->id)
        ->and($charge->wager_id)->toBe($wager->id)
        ->and($charge->product_id)->toBe($this->product->id)
        ->and($charge->customer_id)->toBe($this->player->id)
        ->and($charge->wallet_id)->toBe($this->wallet->id)
        ->and((float) $charge->tax_base)->toBe(180.0)
        ->and((float) $charge->rate)->toBe(0.2)
        ->and((float) $charge->amount)->toBe(36.0)
        ->and($charge->remittance_id)->toBeNull()
        ->and($charge->ledgerEntry->entry_type)->toBe('winnings_tax')
        ->and((float) $charge->ledgerEntry->debit)->toBe(36.0);
});

it('records no charge when the tax is zero', function () {
    taxedWager($this, 'lost-1');
    taxedWager($this, 'small-1');
    settleTaxedWager($this, ['outcome' => 'lost', 'payout' => 0], 'lost-1')->assertOk();
    settleTaxedWager($this, ['payout' => 100], 'small-1')->assertOk()->assertJsonPath('data.settlement.tax', 0);

    $this->product->update(['winnings_tax_rate' => 0]);
    taxedWager($this, 'untaxed-1');
    settleTaxedWager($this, [], 'untaxed-1')->assertOk()->assertJsonPath('data.settlement.tax', 0);

    expect(WinningsTaxCharge::count())->toBe(0);
});

it('records a reversal row when a taxed settlement is reversed', function () {
    taxedWager($this);
    settleTaxedWager($this)->assertOk();

    reverseTaxedWager($this)->assertOk();

    $charge = WinningsTaxCharge::where('kind', WinningsTaxCharge::KIND_CHARGE)->sole();
    $reversal = WinningsTaxCharge::where('kind', WinningsTaxCharge::KIND_REVERSAL)->sole();

    expect($reversal->wager_settlement_id)->toBe($charge->wager_settlement_id)
        ->and((float) $reversal->amount)->toBe(-36.0)
        ->and((float) $reversal->tax_base)->toBe(180.0)
        ->and($reversal->ledgerEntry->entry_type)->toBe('winnings_tax_reversal')
        ->and((float) $reversal->ledgerEntry->credit)->toBe(36.0)
        ->and((float) WinningsTaxCharge::sum('amount'))->toBe(0.0);
});

it('records a new charge when a reversed wager is settled again', function () {
    taxedWager($this);
    settleTaxedWager($this)->assertOk();
    reverseTaxedWager($this)->assertOk();

    settleTaxedWager($this, ['version' => 2, 'payout' => 380])->assertOk()->assertJsonPath('data.settlement.tax', 56);

    expect(WinningsTaxCharge::orderBy('id')->pluck('amount')->map(fn ($amount) => (float) $amount)->all())->toBe([36.0, -36.0, 56.0])
        ->and((float) WinningsTaxCharge::sum('amount'))->toBe(56.0);
});

it('records charges from round settles', function () {
    $round = WagerRound::factory()->create(['product_id' => $this->product->id, 'reference' => 'round-1']);
    taxedWager($this, 'a', $round);
    taxedWager($this, 'b', $round);

    $this->postJson('/api/v1/rounds/round-1/settle', ['product' => 'crash_classic', 'winners' => [['reference' => 'a', 'payout' => 280]]], apiHeaders($this->key->key))
        ->assertOk()
        ->assertJsonPath('data.counts.won', 1)
        ->assertJsonPath('data.counts.lost', 1);

    $charge = WinningsTaxCharge::sole();

    expect($charge->wager->reference)->toBe('a')
        ->and((float) $charge->amount)->toBe(36.0)
        ->and($charge->ledger_entry_id)->toBe(LedgerEntry::where('entry_type', 'winnings_tax')->sole()->id);
});

it('keeps the original charge remitted when its reversal arrives later', function () {
    taxedWager($this);
    settleTaxedWager($this)->assertOk();
    $remittance = WinningsTaxRemittance::factory()->create(['amount_due' => 36, 'amount_paid' => 36]);
    WinningsTaxCharge::sole()->update(['remittance_id' => $remittance->id]);

    reverseTaxedWager($this)->assertOk();

    expect(WinningsTaxCharge::where('kind', WinningsTaxCharge::KIND_CHARGE)->sole()->remittance_id)->toBe($remittance->id)
        ->and(WinningsTaxCharge::where('kind', WinningsTaxCharge::KIND_REVERSAL)->sole()->remittance_id)->toBeNull();
});
