<?php

use App\Models\BetShop;
use App\Models\BetShopFloatPurchase;
use App\Models\BetShopWallet;
use App\Models\Deposit;
use App\Models\DepositResolution;
use App\Models\ExciseDutyCharge;
use App\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->gms = apiHeaders(createApiKey('test-shop-float-key')->key);
    $this->shop = BetShop::factory()->withFloat()->create(['shop_no' => 'S100']);
    config(['finance.excise_duty.enabled' => true, 'finance.excise_duty.rate' => 0.05]);
});

/**
 * A paybill payment from the shop owner, through the C2B confirmation.
 */
function shopFloatPayment(object $test, float $amount, string $billRef = 'SHOP-S100', string $transId = 'SFP0000001'): Deposit
{
    $test->postJson('/api/v1/c2b/confirm', [
        'TransID' => $transId, 'TransactionType' => 'Pay Bill', 'TransTime' => now()->format('YmdHis'),
        'TransAmount' => $amount, 'BusinessShortCode' => '4007279', 'BillRefNumber' => $billRef,
        'MSISDN' => '254712345678', 'FirstName' => 'Peter',
    ])->assertOk()->assertExactJson(['ResultCode' => '0', 'ResultDesc' => 'Accepted']);

    return Deposit::where('trans_id', $transId)->sole();
}

function shopFloat(BetShop $shop): float
{
    return (float) BetShopWallet::where('bet_shop_id', $shop->id)->value('balance');
}

it('leaves a SHOP- paybill payment unmatched while automatic crediting is off, naming the shop', function () {
    $deposit = shopFloatPayment($this, 10000, ' shop-s100 ');

    expect((int) $deposit->status)->toBe(Deposit::STATUS_UNMATCHED)
        ->and(shopFloat($this->shop))->toBe(0.0)
        ->and(LedgerEntry::count())->toBe(0);

    $this->getJson('/api/v1/deposits/unmatched', $this->gms)->assertOk()
        ->assertJsonPath('data.items.0.bet_shop', ['id' => $this->shop->id, 'shop_no' => 'S100', 'name' => $this->shop->name, 'status' => 'active']);
});

it('credits an unmatched payment to the shop float in full, with no excise duty', function () {
    $deposit = shopFloatPayment($this, 10000);

    $this->postJson('/api/v1/deposits/'.encryptId($deposit->id).'/shop-float', ['shop_no' => 's100', 'note' => 'Checked M-Pesa statement'], $this->gms)
        ->assertOk()
        ->assertJsonPath('data.float_purchase.amount', 10000)
        ->assertJsonPath('data.float_purchase.status', 'active')
        ->assertJsonPath('data.deposit.status', Deposit::STATUS_COMPLETED)
        ->assertJsonPath('data.deposit.resolution.action', DepositResolution::ACTION_SHOP_FLOAT);

    $entry = LedgerEntry::sole();
    expect(shopFloat($this->shop))->toBe(10000.0)
        ->and($entry)->toMatchArray(['entry_type' => 'shop_float_purchase', 'wallet_type' => 'bet_shop', 'customer_id' => null])
        ->and((float) $entry->credit)->toBe(10000.0)
        ->and(ExciseDutyCharge::count())->toBe(0);

    $this->postJson('/api/v1/deposits/'.encryptId($deposit->id).'/shop-float', ['shop_no' => 'S100', 'note' => 'Again'], $this->gms)
        ->assertConflict()->assertJsonPath('code', 'already_resolved');
});

it('refuses to credit an unknown or closed shop', function () {
    $deposit = shopFloatPayment($this, 500);
    $closed = BetShop::factory()->withFloat()->create(['shop_no' => 'S900', 'status' => BetShop::STATUS_CLOSED]);

    $this->postJson('/api/v1/deposits/'.encryptId($deposit->id).'/shop-float', ['shop_no' => 'NOPE', 'note' => 'Checked'], $this->gms)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['shop_no' => 'No bet shop has that number.']);
    $this->postJson('/api/v1/deposits/'.encryptId($deposit->id).'/shop-float', ['shop_no' => 'S900', 'note' => 'Checked'], $this->gms)
        ->assertConflict()->assertJsonPath('code', 'shop_closed');

    expect((int) $deposit->fresh()->status)->toBe(Deposit::STATUS_UNMATCHED)
        ->and(shopFloat($closed))->toBe(0.0);
});

it('voids a float purchase back to an unmatched deposit, unless the float was spent', function () {
    $deposit = shopFloatPayment($this, 1000);
    $this->postJson('/api/v1/deposits/'.encryptId($deposit->id).'/shop-float', ['shop_no' => 'S100', 'note' => 'Checked'], $this->gms)->assertOk();
    $purchase = BetShopFloatPurchase::sole();

    BetShopWallet::where('bet_shop_id', $this->shop->id)->update(['balance' => 400]);
    $this->postJson('/api/v1/bet-shops/float-purchases/'.encryptId($purchase->id).'/void', ['reason' => 'Wrong shop'], $this->gms)
        ->assertConflict()->assertJsonPath('code', 'insufficient_float');

    BetShopWallet::where('bet_shop_id', $this->shop->id)->update(['balance' => 1000]);
    $this->postJson('/api/v1/bet-shops/float-purchases/'.encryptId($purchase->id).'/void', ['reason' => 'Wrong shop'], $this->gms)
        ->assertOk()->assertJsonPath('data.float_purchase.status', 'voided');

    expect(shopFloat($this->shop))->toBe(0.0)
        ->and((int) $deposit->fresh()->status)->toBe(Deposit::STATUS_UNMATCHED)
        ->and(DepositResolution::count())->toBe(0)
        ->and(LedgerEntry::where('entry_type', 'shop_float_purchase_reversal')->count())->toBe(1);

    $this->getJson('/api/v1/bet-shops/'.encryptId($this->shop->id).'/float-purchases', $this->gms)->assertOk()
        ->assertJsonPath('data.items.0.status', 'voided')
        ->assertJsonPath('data.items.0.void_reason', 'Wrong shop');
});

it('credits the float straight from the C2B confirmation once automatic crediting is on', function () {
    config(['bet_shops.auto_credit_float' => true]);

    $deposit = shopFloatPayment($this, 7500);

    expect((int) $deposit->status)->toBe(Deposit::STATUS_COMPLETED)
        ->and(shopFloat($this->shop))->toBe(7500.0)
        ->and(BetShopFloatPurchase::sole()->recorded_by)->toBe('c2b:auto');
});

it('leaves a payment for a suspended or unknown shop unmatched even with automatic crediting on', function (string $billRef) {
    config(['bet_shops.auto_credit_float' => true]);
    BetShop::factory()->suspended()->withFloat()->create(['shop_no' => 'S555']);

    $deposit = shopFloatPayment($this, 300, $billRef);

    expect((int) $deposit->status)->toBe(Deposit::STATUS_UNMATCHED)
        ->and(BetShopFloatPurchase::count())->toBe(0);
})->with(['suspended shop' => ['SHOP-S555'], 'unknown shop' => ['SHOP-NOPE']]);

it('reports float purchases as shop_float cash in, not customer deposits, and keeps reconciliation clean', function () {
    $deposit = shopFloatPayment($this, 2000);
    $this->postJson('/api/v1/deposits/'.encryptId($deposit->id).'/shop-float', ['shop_no' => 'S100', 'note' => 'Checked'], $this->gms)->assertOk();

    $cashIn = $this->getJson('/api/v1/finance/cash-flow', $this->gms)->assertOk()->json('data.totals.cash_in');
    expect($cashIn)->toMatchArray(['wallet_deposit' => 0, 'shop_float' => 2000, 'unmatched' => 0, 'total' => 2000]);

    $deposits = $this->getJson('/api/v1/finance/deposits', $this->gms)->assertOk()->json('data');
    expect($deposits['items'][0]['kind'])->toBe('shop_float');

    $trial = $this->getJson('/api/v1/finance/trial-balance', $this->gms)->assertOk()->json('data.check');
    expect($trial['balanced'])->toBeTrue();

    $checks = collect($this->getJson('/api/v1/finance/reconciliation', $this->gms)->assertOk()->json('data.checks'))->keyBy('key');
    foreach (['bet_shop_float_drift', 'deposit_resolutions', 'deposits_without_ledger', 'shop_float_ledger', 'unclassified_entries'] as $key) {
        expect($checks[$key]['status'])->toBe('pass', $key);
    }

    $liabilities = $this->getJson('/api/v1/finance/balance-sheet', $this->gms)->assertOk()->json('data.liabilities');
    expect($liabilities['bet_shop_floats'])->toEqual(2000);
});
