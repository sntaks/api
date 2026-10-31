<?php

use App\Models\CompetitionWallet;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->apiKey = createApiKey('test-competition-correction-key');
    Customer::factory()->create(['id' => 1]);
    $this->player = Customer::factory()->create();
    $this->wallet = Wallet::factory()->create(['customer_id' => $this->player->id, 'balance' => 100]);
    $this->competition = CompetitionWallet::create([
        'competition_id' => 'TOURN-C', 'cmp_uid' => '4242', 'game_type' => 1, 'customer_id' => $this->player->id,
        'level' => 2, 'jp_rounds' => 3, 'balance' => 50, 'status' => 1,
    ]);
});

function correctCompetitionWallet(object $test, array $payload)
{
    return $test->putJson('/api/v1/competition/wallets/'.encryptId($test->competition->id), $payload, apiHeaders($test->apiKey->key));
}

it('records a corrected competition balance as an adjustment for the difference', function () {
    correctCompetitionWallet($this, ['customer_id' => $this->player->id, 'level' => 3, 'balance' => 80])
        ->assertOk()
        ->assertJsonPath('data.balance', '80.00')
        ->assertJsonPath('data.level', 3);

    $adjustment = LedgerEntry::where('entry_type', 'adjustment')->sole();

    expect($adjustment->wallet_type)->toBe(LedgerEntry::WALLET_TYPE_COMPETITION)
        ->and($adjustment->wallet_id)->toBe($this->competition->id)
        ->and((float) $adjustment->credit)->toBe(30.0)
        ->and((float) $adjustment->balance_before)->toBe(50.0)
        ->and((float) $adjustment->balance_after)->toBe(80.0)
        ->and($adjustment->metadata['reason'])->toBe('competition_wallet_correction')
        ->and($adjustment->metadata['actor'])->toBe('api_key:'.$this->apiKey->id);
});

it('records a lowered competition balance as a debit adjustment', function () {
    correctCompetitionWallet($this, ['customer_id' => $this->player->id, 'level' => 2, 'balance' => 20])->assertOk();

    expect((float) LedgerEntry::where('entry_type', 'adjustment')->sole()->debit)->toBe(30.0)
        ->and((float) $this->competition->fresh()->balance)->toBe(20.0);
});

it('posts nothing when the balance sent is the stored one', function () {
    correctCompetitionWallet($this, ['customer_id' => $this->player->id, 'level' => 4, 'balance' => 50])->assertOk();

    expect(LedgerEntry::count())->toBe(0)
        ->and($this->competition->fresh()->level)->toBe(4);
});

it('refuses a stake into a closed competition wallet', function () {
    $this->competition->update(['status' => 3, 'balance' => 0]);

    $this->postJson('/api/v1/competition/transactions', [
        'competition_wallet_id' => $this->competition->id,
        'customer_id' => $this->player->id,
        'payment_type' => 'deposit',
        'amount' => 50,
    ], apiHeaders($this->apiKey->key))->assertStatus(400)->assertJsonPath('status', 'Competition Wallet is not open');

    expect((float) $this->wallet->fresh()->balance)->toBe(100.0)
        ->and((float) $this->competition->fresh()->balance)->toBe(0.0)
        ->and(LedgerEntry::count())->toBe(0);
});

it('shows a competition correction as a competition wallet adjustment in the finance list', function () {
    config(['finance.test_customer_ids' => []]);
    correctCompetitionWallet($this, ['customer_id' => $this->player->id, 'level' => 2, 'balance' => 80])->assertOk();

    $item = $this->getJson('/api/v1/finance/adjustments', apiHeaders($this->apiKey->key))->assertOk()->json('data.items.0');

    expect($item)->toMatchArray([
        'reason' => 'competition_wallet_correction',
        'wallet_type' => LedgerEntry::WALLET_TYPE_COMPETITION,
        'wallet_id' => $this->competition->id,
        'amount' => 30,
    ]);
});
