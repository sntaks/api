<?php

use App\Models\CompetitionWallet;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\DisputedTransaction;
use App\Models\GameWallet;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Wallet;
use App\Services\CompetitionPayoutService;
use App\Services\CompetitionWalletService;
use App\Services\GameWalletService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00', config('app.timezone')));
    config([
        'finance.fees.game_credit' => 0.05,
        'finance.fees.game_withdrawal' => 0.05,
        'finance.test_customer_ids' => [],
        'finance.reconciliation.aged_dispute_days' => 3,
    ]);

    $this->apiKey = createApiKey('test-complaint-resolution-key');
    $this->headers = apiHeaders($this->apiKey->key);

    $house = Customer::factory()->create(['id' => 1]);
    $this->houseWallet = Wallet::forceCreate(['id' => 1, 'customer_id' => $house->id, 'balance' => 0]);
});

/**
 * Two players stake 100 each the way /game/credit used to (5 house cut each) and the winner is paid out
 * (10 house cut): the winner gets 180, the house holds 20.
 *
 * @return array{game: GameWallet, complainant: Customer, winner: Customer, complainantWallet: Wallet, winnerWallet: Wallet}
 */
function settledGame(object $test): array
{
    $complainant = Customer::factory()->create();
    $complainantWallet = Wallet::factory()->create(['customer_id' => $complainant->id, 'balance' => 100]);
    $winner = Customer::factory()->create();
    $winnerWallet = Wallet::factory()->create(['customer_id' => $winner->id, 'balance' => 100]);
    $game = GameWallet::create(['game_id' => 'GAME-'.fake()->unique()->numberBetween(1, 99999), 'balance' => 0, 'status' => 1]);

    foreach ([$complainantWallet, $winnerWallet] as $playerWallet) {
        legacyGameCreditStake($playerWallet, $game, 100);
    }
    app(GameWalletService::class)->processGameWithdrawal($game->id, $winner->id);

    return [
        'game' => $game, 'complainant' => $complainant, 'winner' => $winner,
        'complainantWallet' => $complainantWallet->fresh(), 'winnerWallet' => $winnerWallet->fresh(),
    ];
}

/**
 * The receiver wins a round worth 50 from the sender.
 *
 * @return array{0: CompetitionWallet, 1: CompetitionWallet, 2: Wallet, 3: Wallet}
 */
function settledRound(string $kind = 'tournament', float $receiverOwnBalance = 0): array
{
    [$sender, $receiver, $receiverWallet] = $kind === 'jackpot' ? createJackpotWalletPair() : createTournamentWalletPair();
    $receiver->update(['balance' => $receiverOwnBalance]);

    app(CompetitionPayoutService::class)->processPayout($sender->id, $receiver->id);

    return [$sender->fresh(), $receiver->fresh(), $receiverWallet, Wallet::where('customer_id', $sender->customer_id)->sole()];
}

function openComplaint(object $test, array $payload): Complaint
{
    $id = $test->postJson('/api/v1/complaints', $payload + ['reason' => 'Unfair result'], $test->headers)->assertCreated()->json('data.id');

    return Complaint::findOrFail($id);
}

function closeComplaint(object $test, Complaint $complaint, string $action, string $note = 'Reviewed the game logs')
{
    return $test->postJson('/api/v1/complaints/'.encryptId($complaint->id)."/{$action}", ['note' => $note], $test->headers);
}

// resolving games

it('refunds every game player their full stake and reverses the house cuts', function () {
    ['game' => $game, 'complainant' => $complainant, 'winner' => $winner, 'complainantWallet' => $complainantWallet, 'winnerWallet' => $winnerWallet] = settledGame($this);
    expect((float) $winnerWallet->balance)->toBe(181.0)->and((float) $this->houseWallet->fresh()->balance)->toBe(19.0);
    $complaint = openComplaint($this, ['customer_id' => $complainant->id, 'game_wallet_id' => $game->id]);

    $response = closeComplaint($this, $complaint, 'resolve', 'Winner used a bot');

    $response->assertOk()
        ->assertJsonPath('data.status', 'resolved')
        ->assertJsonPath('data.refunded_amount', 200)
        ->assertJsonPath('data.house_cuts_reversed', 19)
        ->assertJsonPath('data.resolution_note', 'Winner used a bot')
        ->assertJsonPath('data.closed_by', 'api_key:'.$this->apiKey->id)
        ->assertJsonPath('data.disputed_transactions.0.status', 'reversed')
        ->assertJsonPath('data.disputed_transactions.0.balance', 0)
        ->assertJsonCount(2, 'data.refunds');
    expect((float) $complainantWallet->fresh()->balance)->toBe(100.0);
    expect((float) $winnerWallet->fresh()->balance)->toBe(100.0);
    expect((float) $this->houseWallet->fresh()->balance)->toBe(0.0);
    expect(LedgerEntry::where('entry_type', 'house_cut')->where('status', 'settled')->count())->toBe(0);
});

it('keeps the kadi product on reversed game house cuts and leaves the dispute refunds as platform', function () {
    ['game' => $game, 'complainant' => $complainant] = settledGame($this);
    $complaint = openComplaint($this, ['customer_id' => $complainant->id, 'game_wallet_id' => $game->id]);

    closeComplaint($this, $complaint, 'resolve', 'Winner used a bot')->assertOk();

    $productOf = fn (string $type) => LedgerEntry::where('entry_type', $type)->pluck('product_id')->unique()->values()->all();

    expect($productOf('house_cut_reversal'))->toBe([Product::idFor(Product::CODE_KADI)])
        ->and($productOf('dispute_refund'))->toBe([null]);
});

it('removes reversed game house cuts from revenue and keeps the ledger balanced', function () {
    ['game' => $game, 'complainant' => $complainant] = settledGame($this);
    $complaint = openComplaint($this, ['customer_id' => $complainant->id, 'game_wallet_id' => $game->id]);
    closeComplaint($this, $complaint, 'resolve')->assertOk();
    Cache::flush();

    $income = $this->getJson('/api/v1/finance/income-statement', $this->headers)->assertOk()->json('data.revenue');
    $trial = $this->getJson('/api/v1/finance/trial-balance', $this->headers)->assertOk()->json('data.check');
    $balanceSheet = $this->getJson('/api/v1/finance/balance-sheet', $this->headers)->assertOk()->json('data.liabilities');

    expect($income['games'])->toEqual(0);
    expect($trial['balanced'])->toBeTrue();
    expect($balanceSheet['disputed_funds'])->toEqual(0)->and($balanceSheet['customer_wallets'])->toEqual(200);
});

it('shares what there is in proportion to stakes when the winner had spent some', function () {
    ['game' => $game, 'complainant' => $complainant, 'complainantWallet' => $complainantWallet, 'winnerWallet' => $winnerWallet] = settledGame($this);
    $winnerWallet->update(['balance' => 100]);
    $complaint = openComplaint($this, ['customer_id' => $complainant->id, 'game_wallet_id' => $game->id]);

    $response = closeComplaint($this, $complaint, 'resolve');

    $response->assertOk()->assertJsonPath('data.refunded_amount', 119)->assertJsonPath('data.shortfall_amount', 81);
    expect((float) $complainantWallet->fresh()->balance)->toBe(59.5);
    expect((float) $winnerWallet->fresh()->balance)->toBe(59.5);
});

// resolving tournaments and jackpots

it('gives a disputed round win back to the player who lost it', function () {
    [$sender, $receiver, $receiverWallet, $senderWallet] = settledRound();
    $complaint = openComplaint($this, ['customer_id' => $sender->customer_id, 'competition_wallet_id' => $receiver->id]);

    $response = closeComplaint($this, $complaint, 'resolve');

    $response->assertOk()
        ->assertJsonPath('data.refunded_amount', 50)
        ->assertJsonPath('data.house_cuts_reversed', 0)
        ->assertJsonPath('data.refunds.0.customer_id', $sender->customer_id)
        ->assertJsonPath('data.refunds.0.amount', 50);
    expect((float) $senderWallet->fresh()->balance)->toBe(50.0);
    expect((float) $receiver->fresh()->balance)->toBe(0.0);
    expect((float) $receiverWallet->fresh()->balance)->toBe(0.0);
    expect($receiver->fresh()->isUnderDispute())->toBeFalse();
});

it('splits a disputed jackpot payout between the round loser and the winner own stake', function () {
    [$sender, $receiver, $receiverWallet, $senderWallet] = settledRound('jackpot', 20);
    app(CompetitionWalletService::class)->processWithdrawal($receiver->id, $receiver->customer_id);
    $complaint = openComplaint($this, ['customer_id' => $sender->customer_id, 'competition_wallet_id' => $receiver->id]);

    closeComplaint($this, $complaint, 'resolve')->assertOk()->assertJsonPath('data.refunded_amount', 70);

    expect((float) $senderWallet->fresh()->balance)->toBe(50.0);
    expect((float) $receiverWallet->fresh()->balance)->toBe(20.0);
});

// rejecting and cancelling

it('gives the held money back to the winner when a complaint is rejected', function () {
    ['game' => $game, 'complainant' => $complainant, 'winnerWallet' => $winnerWallet] = settledGame($this);
    $complaint = openComplaint($this, ['customer_id' => $complainant->id, 'game_wallet_id' => $game->id]);

    $response = closeComplaint($this, $complaint, 'reject', 'Game logs show a fair win');

    $response->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.released_amount', 181)
        ->assertJsonPath('data.refunded_amount', 0)
        ->assertJsonPath('data.disputed_transactions.0.status', 'released')
        ->assertJsonCount(0, 'data.refunds');
    expect((float) $winnerWallet->fresh()->balance)->toBe(181.0);
    expect((float) $this->houseWallet->fresh()->balance)->toBe(19.0);
    expect(DisputedTransaction::sole()->balance)->toBe('0.00');
});

it('returns the money to an open competition wallet and unfreezes it when cancelled', function () {
    [$sender, $receiver] = settledRound();
    $complaint = openComplaint($this, ['customer_id' => $sender->customer_id, 'competition_wallet_id' => $receiver->id]);

    closeComplaint($this, $complaint, 'cancel', 'Customer withdrew it')->assertOk()->assertJsonPath('data.status', 'cancelled');

    expect((float) $receiver->fresh()->balance)->toBe(50.0);
    $this->postJson('/api/v1/competition/withdraw/'.encryptId($receiver->id), ['customer_id' => (string) $receiver->customer_id], $this->headers)
        ->assertOk();
});

it('refuses to close a complaint twice', function () {
    ['game' => $game, 'complainant' => $complainant, 'winnerWallet' => $winnerWallet] = settledGame($this);
    $complaint = openComplaint($this, ['customer_id' => $complainant->id, 'game_wallet_id' => $game->id]);
    closeComplaint($this, $complaint, 'reject')->assertOk();

    $response = closeComplaint($this, $complaint, 'resolve');

    $response->assertConflict()->assertJsonPath('message', 'Complaint is already rejected.');
    expect((float) $winnerWallet->fresh()->balance)->toBe(181.0);
});

it('requires a note to close a complaint', function () {
    $complaint = Complaint::factory()->create();

    $response = $this->postJson('/api/v1/complaints/'.encryptId($complaint->id).'/reject', [], $this->headers);

    $response->assertUnprocessable()->assertJsonValidationErrors('note');
    expect($complaint->fresh()->status)->toBe('pending_dispute');
});

it('returns 404 when closing an unknown complaint', function () {
    $response = $this->postJson('/api/v1/complaints/'.encryptId(999).'/cancel', ['note' => 'not ours'], $this->headers);

    $response->assertNotFound()->assertJsonPath('message', 'Complaint not found');
});

// finance

it('flags complaints pending longer than the configured days', function () {
    ['game' => $game, 'complainant' => $complainant] = settledGame($this);
    openComplaint($this, ['customer_id' => $complainant->id, 'game_wallet_id' => $game->id]);
    $this->travel(4)->days();

    $checks = collect($this->getJson('/api/v1/finance/reconciliation', $this->headers)->assertOk()->json('data.checks'))->keyBy('key');

    expect($checks['aged_disputes'])->toMatchArray(['status' => 'warn', 'count' => 1, 'amount' => 181]);
    expect($checks['dispute_escrow_drift']['status'])->toBe('pass');
    expect($checks['held_on_closed_complaints']['status'])->toBe('pass');
});

it('flags money left in escrow for a closed complaint', function () {
    $complaint = Complaint::factory()->closed()->create();
    DisputedTransaction::factory()->for($complaint)->create(['balance' => 30]);

    $checks = collect($this->getJson('/api/v1/finance/reconciliation', $this->headers)->assertOk()->json('data.checks'))->keyBy('key');

    expect($checks['held_on_closed_complaints'])->toMatchArray(['status' => 'fail', 'count' => 1, 'amount' => 30]);
});

it('lists complaints with what they held, refunded and released', function () {
    ['game' => $game, 'complainant' => $complainant] = settledGame($this);
    $complaint = openComplaint($this, ['customer_id' => $complainant->id, 'game_wallet_id' => $game->id]);
    closeComplaint($this, $complaint, 'resolve')->assertOk();
    Complaint::factory()->create();

    $data = $this->getJson('/api/v1/finance/disputes', $this->headers)->assertOk()->json('data');

    expect($data['summary']['by_status']['resolved'])->toMatchArray(['complaints' => 1, 'held' => 181, 'refunded' => 200, 'house_cuts_reversed' => 19]);
    expect($data['summary']['by_status']['pending_dispute']['complaints'])->toBe(1);
    expect($data['summary']['currently_held'])->toEqual(0);
    expect(collect($data['items'])->firstWhere('id', $complaint->id))->toMatchArray(['status' => 'resolved', 'still_held' => 0, 'refunded_amount' => 200]);
});

it('exports the disputes report as CSV', function () {
    Complaint::factory()->create(['reason' => 'Bot suspected']);

    $response = $this->get('/api/v1/finance/export/disputes', $this->headers);

    $response->assertOk()->assertDownload();
    expect($response->streamedContent())->toContain('complaint_id,filed_at')->toContain('Bot suspected');
});
