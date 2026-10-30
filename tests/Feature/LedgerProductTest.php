<?php

use App\Models\Customer;
use App\Models\GameWallet;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Wallet;
use App\Services\GameWalletService;
use App\Services\LedgerService;
use App\Services\MpesaService;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config([
        'finance.test_customer_ids' => [],
        'referrals.enabled' => false,
    ]);

    $this->headers = apiHeaders(createApiKey('test-ledger-product-key')->key);

    $house = Customer::factory()->create(['id' => 1]);
    $this->houseWallet = Wallet::forceCreate(['id' => 1, 'customer_id' => $house->id, 'balance' => 0]);
});

/**
 * @return array<string, int|null>
 */
function productIdsByEntryType(): array
{
    return LedgerEntry::orderBy('id')->get()->groupBy('entry_type')
        ->map(fn ($entries) => $entries->pluck('product_id')->unique()->sole())
        ->all();
}

it('tags a kadi stake and its settlement with the kadi product', function () {
    $game = GameWallet::create(['game_id' => 'GAME_PRODUCT_001', 'game_type' => 1, 'balance' => 0, 'status' => 1]);
    $players = collect(range(1, 2))->map(function () use ($game) {
        $customer = Customer::factory()->create();
        Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 500]);

        $this->postJson('/api/v1/game/bets', [
            'game_wallet_id' => $game->id,
            'customer_id' => $customer->id,
            'payment_type' => 'deposit',
            'amount' => 100,
            'status' => 1,
        ], $this->headers)->assertCreated();

        return $customer;
    });

    $this->postJson('/api/v1/game/withdraw/'.encryptId($game->id), ['customer_id' => (string) $players[0]->id], $this->headers)
        ->assertCreated();

    $kadi = Product::idFor(Product::CODE_KADI);

    expect(productIdsByEntryType())->toMatchArray([
        'game_bet' => $kadi,
        'game_payout' => $kadi,
        'escrow_release' => $kadi,
        'house_cut' => $kadi,
    ])->and(LedgerEntry::whereNull('product_id')->exists())->toBeFalse();
});

it('tags tournament and jackpot entries by competition type', function (array $pair, string $productCode) {
    [$sender, $receiver] = $pair;
    Wallet::where('customer_id', $sender->customer_id)->update(['balance' => 100]);

    $this->postJson('/api/v1/competition/transactions', [
        'competition_wallet_id' => $sender->id,
        'customer_id' => $sender->customer_id,
        'payment_type' => 'deposit',
        'amount' => 50,
    ], $this->headers)->assertCreated();

    $this->postJson('/api/v1/competition/payout', [
        'sender_competition_wallet_id' => $sender->id,
        'receiver_competition_wallet_id' => $receiver->id,
        'receiver_withdraw' => true,
    ], $this->headers)->assertOk();

    $productId = Product::idFor($productCode);

    expect(LedgerEntry::count())->toBeGreaterThan(3)
        ->and(LedgerEntry::pluck('product_id')->unique()->all())->toBe([$productId])
        ->and(LedgerEntry::pluck('entry_type')->unique()->sort()->values()->all())
        ->toContain('competition_bet', 'house_cut', 'escrow_transfer', 'escrow_release');
})->with([
    'tournament' => [fn () => createTournamentWalletPair(), Product::CODE_KADI_TOURNAMENT],
    'jackpot' => [fn () => createJackpotWalletPair(), Product::CODE_KADI_JACKPOT],
]);

it('tags a dropped-game share and its sweep', function () {
    $game = GameWallet::create(['game_id' => 'GAME_PRODUCT_DROP', 'game_type' => 1, 'balance' => 0, 'status' => 1]);
    $wallets = collect(range(1, 4))->map(function () use ($game) {
        $customer = Customer::factory()->create();
        $wallet = Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 100]);

        app(GameWalletService::class)->createGameTransaction([
            'game_wallet_id' => $game->id,
            'customer_id' => $customer->id,
            'payment_type' => 'deposit',
            'amount' => 20,
            'status' => 1,
        ]);

        return $wallet;
    });

    foreach ($wallets->take(3) as $wallet) {
        $this->postJson('/api/v1/game/credit', ['customer_id' => $wallet->customer_id, 'game_wallet_id' => $game->id, 'amount' => 25], $this->headers)
            ->assertCreated();
    }

    $sweep = LedgerEntry::where('entry_type', 'house_cut')->where('metadata->source', 'game_drop_payout')->sole();

    expect($sweep->product_id)->toBe(Product::idFor(Product::CODE_KADI))
        ->and(LedgerEntry::pluck('product_id')->unique()->all())->toBe([Product::idFor(Product::CODE_KADI)]);
});

it('tags a full game refund with the kadi product', function () {
    $game = GameWallet::create(['game_id' => 'GAME_PRODUCT_REFUND', 'game_type' => 1, 'balance' => 0, 'status' => 1]);
    foreach (range(1, 2) as $player) {
        $customer = Customer::factory()->create();
        Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 100]);
        $this->postJson('/api/v1/game/bets', ['game_wallet_id' => $game->id, 'customer_id' => $customer->id, 'payment_type' => 'deposit', 'amount' => 40, 'status' => 1], $this->headers)
            ->assertCreated();
    }

    $this->postJson('/api/v1/game/refund', ['game_wallet_id' => $game->id], $this->headers)->assertSuccessful();

    expect(LedgerEntry::pluck('entry_type')->unique()->all())->toContain('refund', 'escrow_release')
        ->and(LedgerEntry::pluck('product_id')->unique()->all())->toBe([Product::idFor(Product::CODE_KADI)]);
});

it('leaves platform entries untagged', function () {
    config([
        'finance.excise_duty.enabled' => true,
        'finance.excise_duty.rate' => 0.05,
        'finance.excise_duty.effective_from' => null,
    ]);
    $customer = Customer::factory()->phoneVerified()->create(['account_no' => 'KK-PRODUCT00001', 'phone_no' => '254700000019']);
    Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 0]);

    payPaybill('KK-PRODUCT00001', '254700000019', 'PRODPLAT01', 200)->assertCreated();

    $mpesa = Mockery::mock(MpesaService::class);
    $mpesa->shouldReceive('b2c')->once()->andReturn(['ResponseCode' => '0', 'ConversationID' => 'AG_PROD_1', 'OriginatorConversationID' => '1-2-3']);
    app()->instance(MpesaService::class, $mpesa);
    expect(app(WithdrawalService::class)->initiateWithdrawal((string) $customer->id, 50)['success'])->toBeTrue();

    expect(LedgerEntry::pluck('entry_type')->all())->toContain('deposit', 'excise_duty', 'withdrawal')
        ->and(LedgerEntry::whereNotNull('product_id')->exists())->toBeFalse();
});

it('gives a reversal the product of the entry it reverses', function () {
    $game = GameWallet::create(['game_id' => 'GAME_PRODUCT_REV', 'game_type' => 1, 'balance' => 0, 'status' => 1]);
    $ledger = app(LedgerService::class);

    $houseCut = $ledger->recordHouseCut($this->houseWallet, 5, 'game_withdraw', null, ['game_wallet_id' => $game->id]);
    // The reversal's own metadata carries only original_entry_id, so the product must come from the original.
    $reversal = $ledger->reverseEntry($houseCut);

    expect($houseCut->product_id)->toBe(Product::idFor(Product::CODE_KADI))
        ->and($reversal->product_id)->toBe($houseCut->product_id);
});
