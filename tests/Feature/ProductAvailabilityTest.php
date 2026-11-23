<?php

use App\Models\CompetitionTransaction;
use App\Models\CompetitionWallet;
use App\Models\Customer;
use App\Models\Game;
use App\Models\GameTransaction;
use App\Models\GameWallet;
use App\Models\Product;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->headers = apiHeaders(createApiKey('test-product-availability-key')->key);

    $house = Customer::factory()->create(['id' => 1]);
    Wallet::forceCreate(['id' => 1, 'customer_id' => $house->id, 'balance' => 0]);

    $this->player = Customer::factory()->create(['status' => 1]);
    Wallet::factory()->create(['customer_id' => $this->player->id, 'balance' => 500]);
});

function suspendProduct(string $code): void
{
    Product::where('code', $code)->update(['status' => Product::STATUS_SUSPENDED]);
}

function competitionWalletFor(Customer $player, int $gameType): CompetitionWallet
{
    $wallet = CompetitionWallet::create([
        'competition_id' => 'CMP-1', 'cmp_uid' => '900', 'game_type' => $gameType, 'customer_id' => $player->id,
        'level' => 1, 'jp_rounds' => 3, 'balance' => 0, 'status' => 1,
    ]);
    Wallet::forceCreate(['id' => $wallet->id + 1000, 'customer_id' => Customer::factory()->create()->id, 'balance' => 0]);

    return $wallet;
}

it('refuses a new kadi game and its stakes when the kadi product is suspended', function () {
    $game = GameWallet::create(['game_id' => 'GAME_OPEN', 'game_type' => 1, 'balance' => 0, 'status' => 1]);
    suspendProduct(Product::CODE_KADI);

    $this->postJson('/api/v1/game/wallets', ['game_id' => 'GAME_NEW', 'game_type' => 1], $this->headers)
        ->assertForbidden()
        ->assertJson(['code' => 'product_not_active']);
    $this->postJson('/api/v1/game/bets', ['game_wallet_id' => $game->id, 'customer_id' => $this->player->id, 'payment_type' => 'deposit', 'amount' => 100], $this->headers)
        ->assertForbidden()
        ->assertJson(['code' => 'product_not_active']);

    expect(GameWallet::count())->toBe(1)->and(GameTransaction::count())->toBe(0);
});

it('refuses kadi stakes when the kadi game is suspended though its products are active', function () {
    $game = GameWallet::create(['game_id' => 'GAME_OPEN', 'game_type' => 1, 'balance' => 0, 'status' => 1]);
    Game::where('code', Game::CODE_KADI)->update(['status' => Game::STATUS_SUSPENDED]);

    $this->postJson('/api/v1/game/bets', ['game_wallet_id' => $game->id, 'customer_id' => $this->player->id, 'payment_type' => 'deposit', 'amount' => 100], $this->headers)
        ->assertForbidden()
        ->assertJson(['code' => 'product_not_active']);
    $this->postJson('/api/v1/competition/wallets', ['competition_id' => 'CMP-2', 'cmp_uid' => '901', 'game_type' => 1, 'customer_id' => $this->player->id, 'jp_rounds' => 3], $this->headers)
        ->assertForbidden();
});

it('refuses competitions of a suspended product only', function () {
    suspendProduct(Product::CODE_KADI_JACKPOT);
    $tournament = competitionWalletFor($this->player, 1);
    $jackpot = competitionWalletFor($this->player, 2);
    $stake = fn (CompetitionWallet $wallet) => $this->postJson('/api/v1/competition/transactions', [
        'competition_wallet_id' => $wallet->id, 'customer_id' => $this->player->id, 'payment_type' => 'deposit', 'amount' => 50,
    ], $this->headers);

    $stake($jackpot)->assertForbidden()->assertJson(['code' => 'product_not_active']);
    $stake($tournament)->assertCreated();
    $this->postJson('/api/v1/competition/wallets', ['competition_id' => 'JP-2', 'cmp_uid' => '902', 'game_type' => 2, 'customer_id' => $this->player->id, 'jp_rounds' => 3], $this->headers)
        ->assertForbidden()
        ->assertJson(['code' => 'product_not_active']);

    expect(CompetitionTransaction::pluck('competition_wallet_id')->all())->toBe([$tournament->id]);
});

it('still pays out a running kadi game after the product is suspended', function () {
    $game = GameWallet::create(['game_id' => 'GAME_RUNNING', 'game_type' => 1, 'balance' => 0, 'status' => 1]);
    $opponent = Customer::factory()->create(['status' => 1]);
    Wallet::factory()->create(['customer_id' => $opponent->id, 'balance' => 500]);
    foreach ([$this->player, $opponent] as $player) {
        $this->postJson('/api/v1/game/bets', ['game_wallet_id' => $game->id, 'customer_id' => $player->id, 'payment_type' => 'deposit', 'amount' => 100], $this->headers)
            ->assertCreated();
    }
    suspendProduct(Product::CODE_KADI);

    $this->postJson('/api/v1/game/withdraw/'.encryptId($game->id), ['customer_id' => (string) $this->player->id], $this->headers)
        ->assertCreated();

    expect((float) $game->fresh()->balance)->toBe(0.0);
});
