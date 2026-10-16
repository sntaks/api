<?php

use App\Models\CompetitionWallet;
use App\Models\Customer;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\CompetitionWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records a competition entry whose competition wallet id matches no customer wallet', function () {
    $apiKey = createApiKey('test-competition-income-key');
    $house = Customer::factory()->create(['id' => 1]);
    $houseWallet = Wallet::forceCreate(['id' => 1, 'customer_id' => $house->id, 'balance' => 0]);
    $player = Customer::factory()->create();
    $playerWallet = Wallet::factory()->create(['customer_id' => $player->id, 'balance' => 100]);
    $competitionWallet = CompetitionWallet::forceCreate([
        'id' => 90000, 'competition_id' => 'TOURN-9', 'cmp_uid' => '9000', 'game_type' => 1, 'customer_id' => $player->id,
        'level' => 1, 'jp_rounds' => 3, 'balance' => 0, 'status' => 1,
    ]);
    expect(Wallet::find($competitionWallet->id))->toBeNull();

    $this->postJson('/api/v1/competition/transactions', [
        'competition_wallet_id' => $competitionWallet->id,
        'customer_id' => $player->id,
        'payment_type' => 'deposit',
        'amount' => 50,
    ], apiHeaders($apiKey->key))->assertCreated();

    expect(WalletTransaction::where('transaction_type', 'c2w')->sole())->sender_id->toBe($competitionWallet->id)
        ->and((float) $playerWallet->fresh()->balance)->toBe(50.0)
        ->and((float) $houseWallet->fresh()->balance)->toBe(5.0)
        ->and((float) $competitionWallet->fresh()->balance)->toBe(45.0);
});

it('counts only the house share taken from competition wallets as competition income', function () {
    $competitionWallet = CompetitionWallet::create([
        'competition_id' => 'TOURN-1', 'cmp_uid' => '555', 'game_type' => 1, 'customer_id' => Customer::factory()->create()->id,
        'level' => 1, 'jp_rounds' => 3, 'balance' => 0, 'status' => 1,
    ]);

    // A customer wallet whose id happens to match the competition wallet's, as c2w rows store the competition wallet id in sender_id.
    $customer = Customer::factory()->create();
    $collidingWallet = Wallet::forceCreate(['id' => $competitionWallet->id, 'customer_id' => $customer->id, 'balance' => 0]);
    $receiverWallet = Wallet::factory()->create(['customer_id' => Customer::factory()->create()->id]);

    $transfer = ['sender_id' => $collidingWallet->id, 'receiver_id' => $receiverWallet->id, 'initiator_id' => $customer->id];
    WalletTransaction::create(['transaction_type' => 'c2w', 'amount' => 10] + $transfer);
    WalletTransaction::create(['transaction_type' => 'c2w', 'amount' => 10] + $transfer);
    WalletTransaction::create(['transaction_type' => 'w2w', 'amount' => 500] + $transfer);

    $income = app(CompetitionWalletService::class)->getCompetitionIncome('1', now()->subDay()->toDateTimeString(), now()->addDay()->toDateTimeString());

    expect($income)->toHaveCount(1)
        ->and((int) $income[0]->jp_rounds)->toBe(3)
        ->and((float) $income[0]->total_income)->toBe(20.0)
        ->and((int) $income[0]->games_played)->toBe(1);
});
