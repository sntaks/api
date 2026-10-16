<?php

use App\Models\BetShop;
use App\Models\CompetitionTransaction;
use App\Models\CompetitionWallet;
use App\Models\Customer;
use App\Models\GameTransaction;
use App\Models\GameWallet;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->headers = apiHeaders(createApiKey('test-shop-attribution-key')->key);

    $house = Customer::factory()->create(['id' => 1]);
    Wallet::forceCreate(['id' => 1, 'customer_id' => $house->id, 'balance' => 0]);

    $this->shop = BetShop::factory()->withFloat()->create(['shop_no' => 'S100']);
    $this->otherShop = BetShop::factory()->withFloat()->create(['shop_no' => 'S200']);
    $this->game = GameWallet::create(['game_id' => 'G-ATTR', 'game_type' => 1, 'balance' => 0, 'status' => 1]);
});

function stakingPlayer(float $balance = 500): Customer
{
    $customer = Customer::factory()->create();
    Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => $balance]);

    return $customer;
}

function shopStake(object $test, Customer $player, ?string $shopNo, float $amount = 100)
{
    return $test->postJson('/api/v1/game/bets', array_filter([
        'game_wallet_id' => $test->game->id,
        'customer_id' => $player->id,
        'payment_type' => 'deposit',
        'amount' => $amount,
        'bet_shop_no' => $shopNo,
    ], fn ($value) => $value !== null), $test->headers);
}

it('records the shop a game stake was made at, per stake', function () {
    $atShop = stakingPlayer();
    $atOtherShop = stakingPlayer();
    $online = stakingPlayer();

    shopStake($this, $atShop, 's100')->assertCreated();
    shopStake($this, $atOtherShop, 'S200')->assertCreated();
    shopStake($this, $online, null)->assertCreated();

    expect(GameTransaction::where('customer_id', $atShop->id)->sole()->bet_shop_id)->toBe($this->shop->id)
        ->and(GameTransaction::where('customer_id', $atOtherShop->id)->sole()->bet_shop_id)->toBe($this->otherShop->id)
        ->and(GameTransaction::where('customer_id', $online->id)->sole()->bet_shop_id)->toBeNull();

    $listed = collect($this->getJson('/api/v1/game/bets', $this->headers)->assertOk()->json('data'));
    expect($listed->firstWhere('customer_id', $atShop->id)['bet_shop_id'])->toBe($this->shop->id)
        ->and($listed->firstWhere('customer_id', $online->id)['bet_shop_id'])->toBeNull();
});

it('leaves payouts unattributed: only stakes carry the shop', function () {
    $winner = stakingPlayer();
    shopStake($this, $winner, 'S100')->assertCreated();
    shopStake($this, stakingPlayer(), 'S100')->assertCreated();

    $this->postJson('/api/v1/game/withdraw/'.encryptId($this->game->id), ['customer_id' => (string) $winner->id], $this->headers)->assertCreated();

    expect(GameTransaction::where('payment_type', 'payout')->pluck('bet_shop_id')->unique()->all())->toBe([null])
        ->and(GameTransaction::where('payment_type', 'deposit')->pluck('bet_shop_id')->unique()->all())->toBe([$this->shop->id]);
});

it('refuses an unknown or inactive shop and takes no stake', function (string $shopNo, string $message) {
    BetShop::factory()->suspended()->withFloat()->create(['shop_no' => 'S300']);
    BetShop::factory()->withFloat()->create(['shop_no' => 'S400', 'status' => BetShop::STATUS_CLOSED]);
    $player = stakingPlayer();

    shopStake($this, $player, $shopNo)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['bet_shop_no' => $message]);

    expect(GameTransaction::count())->toBe(0)
        ->and((float) Wallet::where('customer_id', $player->id)->value('balance'))->toBe(500.0);
})->with([
    'unknown' => ['NOPE', 'No bet shop has that number.'],
    'suspended' => ['S300', 'The bet shop is not active.'],
    'closed' => ['S400', 'The bet shop is not active.'],
]);

it('records the shop a competition entry was made at', function () {
    $player = stakingPlayer();
    $competitionWallet = CompetitionWallet::create([
        'competition_id' => 'TOURN-1', 'cmp_uid' => '777', 'game_type' => 1, 'customer_id' => $player->id,
        'level' => 1, 'jp_rounds' => 3, 'balance' => 0, 'status' => 1,
    ]);
    Wallet::forceCreate(['id' => $competitionWallet->id + 1000, 'customer_id' => Customer::factory()->create()->id, 'balance' => 0]);

    $this->postJson('/api/v1/competition/transactions', [
        'competition_wallet_id' => $competitionWallet->id,
        'customer_id' => $player->id,
        'payment_type' => 'deposit',
        'amount' => 50,
        'bet_shop_no' => 'S200',
    ], $this->headers)->assertCreated();

    expect(CompetitionTransaction::sole()->bet_shop_id)->toBe($this->otherShop->id);

    $this->getJson('/api/v1/competition/transactions', $this->headers)->assertOk()
        ->assertJsonPath('data.0.bet_shop_id', $this->otherShop->id);
});

it('refuses a competition entry for an inactive shop', function () {
    BetShop::factory()->suspended()->withFloat()->create(['shop_no' => 'S300']);
    $player = stakingPlayer();
    $competitionWallet = CompetitionWallet::create([
        'competition_id' => 'TOURN-2', 'cmp_uid' => '778', 'game_type' => 1, 'customer_id' => $player->id,
        'level' => 1, 'jp_rounds' => 3, 'balance' => 0, 'status' => 1,
    ]);

    $this->postJson('/api/v1/competition/transactions', [
        'competition_wallet_id' => $competitionWallet->id, 'customer_id' => $player->id,
        'payment_type' => 'deposit', 'amount' => 50, 'bet_shop_no' => 'S300',
    ], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('bet_shop_no');

    expect(CompetitionTransaction::count())->toBe(0);
});

it('does not change a stake\'s shop on update', function () {
    $player = stakingPlayer();
    shopStake($this, $player, 'S100')->assertCreated();
    $stake = GameTransaction::sole();

    $this->putJson('/api/v1/game/bets/'.encryptId($stake->id), [
        'game_wallet_id' => $this->game->id, 'payment_type' => 'deposit', 'amount' => 100, 'bet_shop_no' => 'S200',
    ], $this->headers)->assertSuccessful();

    expect($stake->fresh()->bet_shop_id)->toBe($this->shop->id);
});
