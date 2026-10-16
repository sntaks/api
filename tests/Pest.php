<?php

use App\Models\ApiKey;
use App\Models\CompetitionWallet;
use App\Models\Customer;
use App\Models\GameTransaction;
use App\Models\GameWallet;
use App\Models\Wallet;
use App\Services\LedgerService;
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
