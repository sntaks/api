<?php

use App\Models\CompetitionTransaction;
use App\Models\CompetitionWallet;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\GameTransaction;
use App\Models\GameWallet;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Wallet;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $house = Customer::factory()->create(['id' => 1]);
    $this->houseWallet = Wallet::forceCreate(['id' => 1, 'customer_id' => $house->id, 'balance' => 0]);
    $this->wallet = Wallet::factory()->create(['customer_id' => Customer::factory()->create()->id, 'balance' => 500]);
    $this->game = GameWallet::create(['game_id' => 'GAME_BACKFILL_1', 'game_type' => 1, 'balance' => 0, 'status' => 1]);
    $this->ledger = app(LedgerService::class);
});

/**
 * Old entries were written before ledger_entries.product_id existed.
 */
function forgetProducts(): void
{
    LedgerEntry::query()->update(['product_id' => null]);
}

it('tags old kadi entries and leaves platform entries alone', function () {
    $stake = GameTransaction::create(['game_wallet_id' => $this->game->id, 'customer_id' => $this->wallet->customer_id, 'payment_type' => 'deposit', 'amount' => 50, 'status' => 1]);
    [$bet] = $this->ledger->recordGameBet($stake, $this->wallet, $this->game, 50);

    $jackpot = CompetitionWallet::create([
        'competition_id' => 'JACKPOT-9', 'cmp_uid' => '9191', 'game_type' => 2, 'customer_id' => $this->wallet->customer_id,
        'level' => 1, 'jp_rounds' => 13, 'balance' => 0, 'status' => 1,
    ]);
    $competitionCut = $this->ledger->recordHouseCut($this->houseWallet, 2.5, 'competition_bet', null, ['competition_wallet_id' => $jackpot->id]);

    $deposit = Deposit::create([
        'trans_id' => 'BACKFILL01', 'trans_type' => 'Pay Bill', 'trans_time' => now(), 'trans_amount' => 100,
        'short_code' => '12345', 'bill_ref_no' => 'KK-BACKFILL0001', 'msisdn' => '254700000001', 'name' => 'x', 'status' => 1,
    ]);
    $depositEntry = $this->ledger->recordDeposit($deposit, $this->wallet, 100);
    forgetProducts();

    $this->artisan('ledger:backfill-product')
        ->expectsOutputToContain('Backfill complete.')
        ->assertSuccessful();

    expect($bet->fresh()->product_id)->toBe(Product::idFor(Product::CODE_KADI))
        ->and($competitionCut->fresh()->product_id)->toBe(Product::idFor(Product::CODE_KADI_JACKPOT))
        ->and($depositEntry->fresh()->product_id)->toBeNull();
});

it('writes nothing on a dry run', function () {
    $stake = GameTransaction::create(['game_wallet_id' => $this->game->id, 'customer_id' => $this->wallet->customer_id, 'payment_type' => 'deposit', 'amount' => 50, 'status' => 1]);
    $this->ledger->recordGameBet($stake, $this->wallet, $this->game, 50);
    forgetProducts();

    $this->artisan('ledger:backfill-product', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run, nothing was written.')
        ->expectsTable(['product', 'entries'], [['kadi', 2]])
        ->assertSuccessful();

    expect(LedgerEntry::whereNotNull('product_id')->exists())->toBeFalse();
});

it('tags legacy game_credit stakes', function () {
    legacyGameCreditStake($this->wallet, $this->game, 40);
    forgetProducts();

    $this->artisan('ledger:backfill-product')->assertSuccessful();

    expect(LedgerEntry::count())->toBe(3)
        ->and(LedgerEntry::pluck('product_id')->unique()->all())->toBe([Product::idFor(Product::CODE_KADI)]);
});

it('tags September house cuts that carry only their source', function (string $source) {
    // 2026-09-02 to 2026-09-20 house cuts were written with no reference and no wallet context.
    $houseCut = $this->ledger->recordHouseCut($this->houseWallet, 5, $source);
    forgetProducts();

    $this->artisan('ledger:backfill-product')->assertSuccessful();

    expect($houseCut->fresh()->product_id)->toBe(Product::idFor(Product::CODE_KADI));
})->with(['game_withdrawal', 'game_drop_payout', 'game_credit']);

it('tags a September competition house cut by the bet it was taken from', function () {
    $competition = fn (int $gameType) => CompetitionWallet::create([
        'competition_id' => "COMP-{$gameType}", 'cmp_uid' => "77{$gameType}", 'game_type' => $gameType, 'customer_id' => $this->wallet->customer_id,
        'level' => 1, 'jp_rounds' => 13, 'balance' => 0, 'status' => 1,
    ]);
    $bet = function (CompetitionWallet $wallet, float $amount, float $cut) {
        $entry = CompetitionTransaction::create(['competition_wallet_id' => $wallet->id, 'customer_id' => $this->wallet->customer_id, 'payment_type' => 'deposit', 'amount' => $amount, 'status' => 1]);
        $this->ledger->recordCompetitionBet($entry, $this->wallet->fresh(), $wallet->fresh(), $amount, $cut);

        return $this->ledger->recordHouseCut($this->houseWallet->fresh(), $cut, 'competition_bet');
    };

    $tournamentCut = $bet($competition(1), 20, 2);
    $jackpotCut = $bet($competition(2), 50, 5);
    forgetProducts();

    $this->artisan('ledger:backfill-product')->assertSuccessful();

    expect($tournamentCut->fresh()->product_id)->toBe(Product::idFor(Product::CODE_KADI_TOURNAMENT))
        ->and($jackpotCut->fresh()->product_id)->toBe(Product::idFor(Product::CODE_KADI_JACKPOT));
});

it('fails and lists a house cut it cannot place, instead of calling it platform', function () {
    $orphan = $this->ledger->recordHouseCut($this->houseWallet, 3, 'competition_bet');
    forgetProducts();

    $this->artisan('ledger:backfill-product')
        ->expectsOutputToContain('1 house cuts could not be placed and were left null')
        ->expectsOutputToContain('0 entries left as platform')
        ->assertFailed();

    expect($orphan->fresh()->product_id)->toBeNull();
});

it('gives an old reversal the product of the entry it reversed', function () {
    $houseCut = $this->ledger->recordHouseCut($this->houseWallet, 5, 'game_withdraw', null, ['game_wallet_id' => $this->game->id]);
    $reversal = $this->ledger->reverseEntry($houseCut);
    forgetProducts();

    $this->artisan('ledger:backfill-product')->assertSuccessful();

    expect($reversal->fresh()->product_id)->toBe(Product::idFor(Product::CODE_KADI));
});

it('leaves entries that already have a product untouched', function () {
    $crash = Product::factory()->create(['code' => 'crash']);
    $houseCut = $this->ledger->recordHouseCut($this->houseWallet, 5, 'game_withdraw', null, ['game_wallet_id' => $this->game->id]);
    $houseCut->forceFill(['product_id' => $crash->id])->save();

    $this->artisan('ledger:backfill-product')->assertSuccessful();

    expect($houseCut->fresh()->product_id)->toBe($crash->id);
});
