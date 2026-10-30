<?php

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Wager;
use App\Models\WagerSettlement;
use App\Models\Wallet;
use App\Services\ChartOfAccounts;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $house = Customer::factory()->create(['id' => 1]);
    $this->houseWallet = Wallet::forceCreate(['id' => 1, 'customer_id' => $house->id, 'balance' => 10000]);
    $this->product = Product::factory()->create();
    $this->player = Customer::factory()->create();
    $this->playerWallet = Wallet::forceCreate(['customer_id' => $this->player->id, 'balance' => 1000]);
    $this->wager = Wager::factory()->create([
        'product_id' => $this->product->id,
        'customer_id' => $this->player->id,
        'wallet_id' => $this->playerWallet->id,
        'stake' => 100,
        'balance' => 0,
    ]);
    $this->settlement = WagerSettlement::factory()->create(['wager_id' => $this->wager->id]);
    $this->ledger = app(LedgerService::class);
});

it('posts each wager leg with the wager product', function () {
    $this->ledger->recordWagerStake($this->wager, $this->playerWallet, 100.0);
    $this->ledger->recordWagerStakeSettled($this->settlement, $this->wager, $this->houseWallet, 100.0);
    $this->ledger->recordWagerPayout($this->settlement, $this->houseWallet, $this->playerWallet, 250.0);
    $this->ledger->recordWagerFreeBetPayout($this->settlement, $this->houseWallet, $this->playerWallet, 20.0);
    $this->ledger->recordWagerBonus($this->settlement, $this->houseWallet, $this->playerWallet, 10.0);
    $this->ledger->recordWagerRefund($this->settlement, $this->wager, $this->playerWallet, 0.0);
    $this->ledger->recordWinningsTax($this->settlement, $this->playerWallet, 15.0);

    $entries = LedgerEntry::orderBy('id')->get();

    expect($entries->pluck('entry_type')->all())->toBe([
        'wager_stake', 'wager_stake',
        'wager_stake_settled', 'wager_stake_settled',
        'wager_payout', 'wager_payout',
        'wager_free_bet_payout', 'wager_free_bet_payout',
        'wager_bonus', 'wager_bonus',
        'wager_refund', 'wager_refund',
        'winnings_tax',
    ]);
    expect($entries->pluck('product_id')->unique()->all())->toBe([$this->product->id]);

    $stake = $entries->where('entry_type', 'wager_stake');
    expect($stake->first()->wallet_type)->toBe('wallet')
        ->and((float) $stake->first()->debit)->toBe(100.0)
        ->and($stake->last()->wallet_type)->toBe('wager')
        ->and($stake->last()->wallet_id)->toBe($this->wager->id)
        ->and((float) $stake->last()->credit)->toBe(100.0);

    $tax = $entries->firstWhere('entry_type', 'winnings_tax');
    expect((float) $tax->debit)->toBe(15.0)->and($tax->customer_id)->toBe($this->player->id);

    // 1000 - 100 stake + 250 + 20 + 10 - 0 refund - 15 tax
    expect((float) $this->playerWallet->fresh()->balance)->toBe(1165.0)
        ->and((float) $this->wager->fresh()->balance)->toBe(0.0)
        ->and((float) $this->houseWallet->fresh()->balance)->toBe(10000 + 100 - 250 - 20 - 10.0);
});

it('reports open wagers as their own account', function () {
    $chart = app(ChartOfAccounts::class);

    expect($chart->accountFor(LedgerEntry::WALLET_TYPE_WAGER, $this->wager->id))->toBe('open_wagers')
        ->and($chart->account('open_wagers'))->toBe(['label' => 'Open wagers (escrow)', 'type' => 'liability']);
});

it('maps wager entries to their finance categories', function () {
    $chart = app(ChartOfAccounts::class);

    expect($chart->categoryFor('wager_stake'))->toBe('stake')
        ->and($chart->categoryFor('wager_stake_settled'))->toBe('house_banked_win')
        ->and($chart->categoryFor('wager_payout'))->toBe('payout')
        ->and($chart->categoryFor('wager_free_bet_payout'))->toBe('promotion')
        ->and($chart->categoryFor('wager_bonus'))->toBe('promotion')
        ->and($chart->categoryFor('wager_refund'))->toBe('refund')
        ->and($chart->categoryFor('winnings_tax'))->toBe('tax_withheld')
        ->and($chart->categoryFor('wager_payout_reversal'))->toBe('payout')
        ->and($chart->entryTypesFor('house_banked_win'))->toBe(['wager_stake_settled', 'wager_stake_settled_reversal']);
});

it('reverses a settlement leg by leg', function () {
    $this->ledger->recordWagerStake($this->wager, $this->playerWallet, 100.0);
    $this->ledger->recordWagerStakeSettled($this->settlement, $this->wager, $this->houseWallet, 100.0);
    $this->ledger->recordWagerPayout($this->settlement, $this->houseWallet, $this->playerWallet, 250.0);
    $this->ledger->recordWagerBonus($this->settlement, $this->houseWallet, $this->playerWallet, 10.0);
    $this->ledger->recordWinningsTax($this->settlement, $this->playerWallet, 15.0);

    $this->ledger->reverseWagerEntries($this->settlement, $this->wager, $this->playerWallet, $this->houseWallet);

    $reversals = LedgerEntry::where('entry_type', 'like', '%_reversal')->orderBy('id')->get();

    expect($reversals->pluck('entry_type')->all())->toBe([
        'winnings_tax_reversal',
        'wager_bonus_reversal', 'wager_bonus_reversal',
        'wager_payout_reversal', 'wager_payout_reversal',
        'wager_stake_settled_reversal', 'wager_stake_settled_reversal',
    ]);
    expect($reversals->pluck('product_id')->unique()->all())->toBe([$this->product->id]);
    expect($reversals->every(fn (LedgerEntry $entry) => isset($entry->metadata['original_entry_id'])))->toBeTrue();
    expect(LedgerEntry::where('referenceable_type', WagerSettlement::class)->where('entry_type', 'not like', '%_reversal')->pluck('status')->unique()->all())->toBe(['reversed']);

    // back to the state after the stake alone: stake sits in escrow again, house is whole
    expect((float) $this->playerWallet->fresh()->balance)->toBe(900.0)
        ->and((float) $this->wager->fresh()->balance)->toBe(100.0)
        ->and((float) $this->houseWallet->fresh()->balance)->toBe(10000.0);
});

it('lets a reversal take the player below zero', function () {
    $this->playerWallet->update(['balance' => 0]);
    $this->ledger->recordWagerPayout($this->settlement, $this->houseWallet, $this->playerWallet, 50.0);
    $this->playerWallet->update(['balance' => 0]);

    $this->ledger->reverseWagerEntries($this->settlement, $this->wager, $this->playerWallet, $this->houseWallet);

    expect((float) $this->playerWallet->fresh()->balance)->toBe(-50.0);
});

it('reverses on the locked instances it is given, never on a fresh read', function () {
    $this->ledger->recordWagerStake($this->wager, $this->playerWallet, 100.0);
    $this->ledger->recordWagerStakeSettled($this->settlement, $this->wager, $this->houseWallet, 100.0);
    $this->ledger->recordWagerPayout($this->settlement, $this->houseWallet, $this->playerWallet, 250.0);

    // what the caller's locking reads returned: the latest committed rows (player 1150, house 9850, escrow 0)
    $wager = Wager::whereKey($this->wager->id)->lockForUpdate()->firstOrFail();
    $player = Wallet::whereKey($this->playerWallet->id)->lockForUpdate()->firstOrFail();
    $house = Wallet::whereKey($this->houseWallet->id)->lockForUpdate()->firstOrFail();

    // Under REPEATABLE READ a plain read can return an older snapshot than the locking read. Make every row
    // differ from its locked instance, so a re-read inside the reversal would show in the balances.
    DB::table('wallets')->whereIn('id', [$player->id, $house->id])->update(['balance' => 5]);
    DB::table('wagers')->where('id', $wager->id)->update(['balance' => 5]);

    $this->ledger->reverseWagerEntries($this->settlement, $wager, $player, $house);

    expect((float) $player->balance)->toBe(900.0)->and((float) $house->balance)->toBe(10000.0)->and((float) $wager->balance)->toBe(100.0)
        ->and((float) $player->fresh()->balance)->toBe(900.0)
        ->and((float) $house->fresh()->balance)->toBe(10000.0)
        ->and((float) $wager->fresh()->balance)->toBe(100.0);
});

it('refuses to reverse a leg on a wallet the caller did not lock', function () {
    $this->ledger->recordWagerPayout($this->settlement, $this->houseWallet, $this->playerWallet, 50.0);

    expect(fn () => $this->ledger->reverseWagerEntries($this->settlement, $this->wager, $this->playerWallet, null))
        ->toThrow(LogicException::class);

    // checked before anything moves
    expect((float) $this->playerWallet->fresh()->balance)->toBe(1050.0)
        ->and(LedgerEntry::where('entry_type', 'like', '%_reversal')->count())->toBe(0);
});
