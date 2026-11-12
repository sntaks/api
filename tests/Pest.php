<?php

use App\Models\ApiKey;
use App\Models\BetShop;
use App\Models\CompetitionWallet;
use App\Models\Customer;
use App\Models\Game;
use App\Models\GameTransaction;
use App\Models\GameWallet;
use App\Models\PredictExposure;
use App\Models\PredictMarket;
use App\Models\PredictOutcome;
use App\Models\Product;
use App\Models\WagerRound;
use App\Models\Wallet;
use App\Services\LedgerService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may need some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function createApiKey(string $key = 'test-api-key', bool $active = true): ApiKey
{
    return ApiKey::create([
        'key' => $key,
        'name' => 'Test API Key',
        'is_active' => $active,
    ]);
}

/**
 * An active API key for a bet shop's system (scope `shop`).
 */
function createShopKey(BetShop $shop, ?string $key = null): ApiKey
{
    return ApiKey::create([
        'key' => $key ?? 'shop_test_'.$shop->shop_no,
        'name' => "Desk at {$shop->shop_no}",
        'scope' => ApiKey::SCOPE_SHOP,
        'bet_shop_id' => $shop->id,
        'is_active' => true,
    ]);
}

/**
 * Headers for a shop system request; writes need an Idempotency-Key.
 */
function shopHeaders(ApiKey $key, ?string $idempotencyKey = null): array
{
    return apiHeaders($key->key) + array_filter(['Idempotency-Key' => $idempotencyKey]);
}

function encryptId(mixed $id): string
{
    return encryptOpenSSL((string) $id);
}

function apiHeaders(?string $apiKey = null): array
{
    return [
        'X-API-KEY' => $apiKey ?? 'test-api-key',
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];
}

/**
 * Create an open, same-competition pair of tournament (game_type 1) competition wallets,
 * each backed by a customer with a real wallet, for payout/withdrawal tests.
 *
 * @return array{0: CompetitionWallet, 1: CompetitionWallet, 2: Wallet} [$sender, $receiver, $receiverWallet]
 */
function createTournamentWalletPair(float $senderBalance = 50): array
{
    $cmpUid = (string) fake()->unique()->numberBetween(1000, 999999);

    $senderCustomer = Customer::factory()->create();
    Wallet::factory()->create(['customer_id' => $senderCustomer->id, 'balance' => 0]);
    $sender = CompetitionWallet::create([
        'competition_id' => 'TOURN-1',
        'cmp_uid' => $cmpUid,
        'game_type' => 1,
        'customer_id' => $senderCustomer->id,
        'level' => 2,
        'jp_rounds' => 5,
        'balance' => $senderBalance,
        'status' => 1,
    ]);

    $receiverCustomer = Customer::factory()->create();
    $receiverWallet = Wallet::factory()->create(['customer_id' => $receiverCustomer->id, 'balance' => 0]);
    $receiver = CompetitionWallet::create([
        'competition_id' => 'TOURN-1',
        'cmp_uid' => $cmpUid,
        'game_type' => 1,
        'customer_id' => $receiverCustomer->id,
        'level' => 2,
        'jp_rounds' => 5,
        'balance' => 0,
        'status' => 1,
    ]);

    return [$sender, $receiver, $receiverWallet];
}

/**
 * Create an open, same-competition pair of jackpot (game_type 2) competition wallets,
 * each backed by a customer with a real wallet, for payout/withdrawal tests.
 *
 * @return array{0: CompetitionWallet, 1: CompetitionWallet, 2: Wallet} [$sender, $receiver, $receiverWallet]
 */
function createJackpotWalletPair(float $senderBalance = 50): array
{
    $cmpUid = (string) fake()->unique()->numberBetween(1000, 999999);

    $senderCustomer = Customer::factory()->create();
    Wallet::factory()->create(['customer_id' => $senderCustomer->id, 'balance' => 0]);
    $sender = CompetitionWallet::create([
        'competition_id' => 'JACKPOT-1',
        'cmp_uid' => $cmpUid,
        'game_type' => 2,
        'customer_id' => $senderCustomer->id,
        'level' => 2,
        'jp_rounds' => 13,
        'balance' => $senderBalance,
        'status' => 1,
    ]);

    $receiverCustomer = Customer::factory()->create();
    $receiverWallet = Wallet::factory()->create(['customer_id' => $receiverCustomer->id, 'balance' => 0]);
    $receiver = CompetitionWallet::create([
        'competition_id' => 'JACKPOT-1',
        'cmp_uid' => $cmpUid,
        'game_type' => 2,
        'customer_id' => $receiverCustomer->id,
        'level' => 2,
        'jp_rounds' => 13,
        'balance' => 0,
        'status' => 1,
    ]);

    return [$sender, $receiver, $receiverWallet];
}

/**
 * Book a stake the way /game/credit did before it became the dropped-game share endpoint: the full
 * amount leaves the player's wallet, the house takes its game_credit cut and the rest goes into escrow.
 * The ledger still holds these entries, so finance tests keep exercising them.
 */
function legacyGameCreditStake(Wallet $wallet, GameWallet $gameWallet, float $amount): GameTransaction
{
    $ledger = app(LedgerService::class);
    $houseCut = round($amount * config('finance.fees.game_credit'), 2);

    $stake = GameTransaction::create([
        'game_wallet_id' => $gameWallet->id,
        'customer_id' => $wallet->customer_id,
        'payment_type' => 'deposit',
        'amount' => $amount,
        'status' => 1,
    ]);

    $ledger->recordGameBetWithHouseCut($stake, $wallet->fresh(), $gameWallet->fresh(), $amount, $houseCut);
    $ledger->recordHouseCut(Wallet::find(config('wallets.house_wallet_id', 1)), $houseCut, 'game_credit', $stake, ['game_wallet_id' => $gameWallet->id]);

    return $stake;
}

/**
 * A paybill (C2B) confirmation from Safaricom for $billRef, paid from $msisdn (the sha256 of the 254
 * number in production, or a plain or masked number).
 */
function payPaybill(string $billRef, string $msisdn, string $transId, float $amount = 100): TestResponse
{
    return test()->postJson('/api/v1/c2b/confirm', [
        'TransID' => $transId,
        'TransactionType' => 'Pay Bill',
        'TransTime' => now()->format('YmdHis'),
        'TransAmount' => $amount,
        'BusinessShortCode' => '12345',
        'BillRefNumber' => $billRef,
        'MSISDN' => $msisdn,
        'FirstName' => 'Payer',
    ]);
}

/**
 * Prediction markets wiring: the `predict` game with its game key (used in-process to post wagers), the
 * `predict_pool` product, the house wallet (#1), a full GMS key and the Kadi site key. Sets $test->predictKey,
 * ->product, ->house, ->adminHeaders and ->siteHeaders.
 */
function setUpPredict(object $test): void
{
    $game = Game::factory()->create(['code' => 'predict']);
    $test->predictKey = ApiKey::create(['key' => 'game_predict_key', 'name' => 'predict', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $game->id, 'is_active' => true]);
    $test->product = Product::factory()->create(['game_id' => $game->id, 'code' => 'predict_pool']);
    $houseCustomer = Customer::factory()->create(['id' => 1]);
    $test->house = Wallet::forceCreate(['id' => 1, 'customer_id' => $houseCustomer->id, 'balance' => 100000]);
    ApiKey::create(['key' => 'gms_full_key', 'name' => 'GMS', 'scope' => ApiKey::SCOPE_FULL, 'is_active' => true]);
    ApiKey::create(['key' => 'kadi_site_key', 'name' => 'kadi', 'scope' => ApiKey::SCOPE_SITE, 'is_active' => true]);
    $test->adminHeaders = apiHeaders('gms_full_key');
    $test->siteHeaders = apiHeaders('kadi_site_key');
}

/**
 * An active player with a funded wallet.
 */
function predictPlayer(float $balance = 10000): Customer
{
    $player = Customer::factory()->create(['status' => 1]);
    Wallet::forceCreate(['customer_id' => $player->id, 'balance' => $balance]);

    return $player;
}

/**
 * The Kadi site places a prediction bet for $player.
 */
function placePredictBet(object $test, Customer $player, int $marketId, string $side, float|int|string $stake, ?string $reference = null): TestResponse
{
    return $test->postJson('/api/v1/customers/'.encryptId($player->id).'/predict/bets', [
        'market_id' => $marketId,
        'side' => $side,
        'stake' => $stake,
        'reference' => $reference ?? (string) Str::uuid(),
    ], $test->siteHeaders);
}

/**
 * An open imported market priced at $yesMid / (1 − $yesMid), with a book that passes the betting guards.
 */
function fixedMarket(object $test, string $yesMid = '0.60', array $overrides = []): PredictMarket
{
    $market = PredictMarket::factory()->create(array_merge([
        'source' => PredictMarket::SOURCE_POLYMARKET,
        'engine' => PredictMarket::ENGINE_REFERENCE,
        'product_id' => $test->fixedProduct->id,
        'external_market_id' => (string) Str::random(8),
        'external_volume' => 250000,
        'status' => PredictMarket::STATUS_OPEN,
        'opened_at' => now(),
        'close_at' => now()->addDays(2),
        'margin_bp' => 500,
        'max_liability_minor' => 50000000,
        'max_customer_stake_minor' => 2000000,
    ], $overrides));

    WagerRound::create(['product_id' => $market->product_id, 'reference' => $market->round_reference, 'status' => WagerRound::STATUS_OPEN]);

    foreach (['yes' => $yesMid, 'no' => bcsub('1', $yesMid, 2)] as $side => $mid) {
        PredictExposure::create(['predict_market_id' => $market->id, 'side' => $side, 'stakes_minor' => 0, 'payouts_minor' => 0]);
        PredictOutcome::create(['predict_market_id' => $market->id, 'side' => $side, 'label' => ucfirst($side), 'external_token_id' => "tok-{$market->id}-{$side}"]);
        setPrice($market, $side, $mid);
    }

    return $market;
}

function setPrice(PredictMarket $market, string $side, string $mid, string $spread = '0.02', string $depth = '50000'): void
{
    PredictOutcome::where('predict_market_id', $market->id)->where('side', $side)->update([
        'midpoint' => $mid, 'best_bid' => bcsub($mid, bcdiv($spread, '2', 6), 6), 'best_ask' => bcadd($mid, bcdiv($spread, '2', 6), 6),
        'spread' => $spread, 'depth_usd' => $depth, 'price_reliable' => true, 'price_captured_at' => now(),
    ]);
}

function quoteFor(object $test, PredictMarket $market, string $side = 'yes', float $stake = 100, $player = null): TestResponse
{
    return $test->postJson('/api/v1/customers/'.encryptId(($player ?? $test->player)->id).'/predict/quotes', ['market_id' => $market->id, 'side' => $side, 'stake' => $stake], $test->siteHeaders);
}

function betWithQuote(object $test, PredictMarket $market, string $quoteId, string $side = 'yes', float $stake = 100, $player = null): TestResponse
{
    return $test->postJson('/api/v1/customers/'.encryptId(($player ?? $test->player)->id).'/predict/bets', [
        'market_id' => $market->id, 'side' => $side, 'stake' => $stake, 'reference' => (string) Str::uuid(), 'quote_id' => $quoteId,
    ], $test->siteHeaders);
}

function quoteAndBet(object $test, PredictMarket $market, string $side = 'yes', float $stake = 100, $player = null): TestResponse
{
    $quoteId = quoteFor($test, $market, $side, $stake, $player)->assertCreated()->json('data.quote.quote_id');

    return betWithQuote($test, $market, $quoteId, $side, $stake, $player);
}
