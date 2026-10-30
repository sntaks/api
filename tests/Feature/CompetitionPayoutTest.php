<?php

use App\Models\CompetitionWallet;
use App\Models\Complaint;
use App\Models\LedgerEntry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->apiKey = createApiKey('test-comp-payout-key');
});

it('competition payout returns error for invalid wallets', function () {
    $response = $this->postJson('/api/v1/competition/payout', [
        'sender_competition_wallet_id' => 9999,
        'receiver_competition_wallet_id' => 9998,
    ], apiHeaders($this->apiKey->key));

    $response->assertStatus(404);
});

it('rejects a non-boolean receiver_withdraw value', function () {
    $response = $this->postJson('/api/v1/competition/payout', [
        'sender_competition_wallet_id' => 9999,
        'receiver_competition_wallet_id' => 9998,
        'receiver_withdraw' => 'maybe',
    ], apiHeaders($this->apiKey->key));

    $response->assertStatus(422);
});

it('does not withdraw the receiver when receiver_withdraw is omitted', function () {
    [$sender, $receiver, $receiverWallet] = createTournamentWalletPair();

    $response = $this->postJson('/api/v1/competition/payout', [
        'sender_competition_wallet_id' => $sender->id,
        'receiver_competition_wallet_id' => $receiver->id,
    ], apiHeaders($this->apiKey->key));

    $response->assertOk();
    $response->assertJsonMissing(['withdrawal']);

    $receiver->refresh();
    expect((float) $receiver->balance)->toBe(50.0);
    expect($receiver->status)->toBe(1);
    expect((float) $receiverWallet->fresh()->balance)->toBe(0.0);
});

it('withdraws the receiver funds to their wallet when receiver_withdraw is true', function () {
    [$sender, $receiver, $receiverWallet] = createTournamentWalletPair();

    $response = $this->postJson('/api/v1/competition/payout', [
        'sender_competition_wallet_id' => $sender->id,
        'receiver_competition_wallet_id' => $receiver->id,
        'receiver_withdraw' => true,
    ], apiHeaders($this->apiKey->key));

    $response->assertOk();
    $response->assertJsonPath('withdrawal.status', 'success');

    $receiver->refresh();
    expect((float) $receiver->balance)->toBe(0.0);
    expect($receiver->status)->toBe(3);
    expect((float) $receiverWallet->fresh()->balance)->toBe(50.0);
});

it('pays out a jackpot round like a tournament round', function () {
    [$sender, $receiver, $receiverWallet] = createJackpotWalletPair();

    $response = $this->postJson('/api/v1/competition/payout', [
        'sender_competition_wallet_id' => $sender->id,
        'receiver_competition_wallet_id' => $receiver->id,
    ], apiHeaders($this->apiKey->key));

    $response->assertOk();
    $response->assertJsonMissing(['withdrawal']);

    $sender->refresh();
    $receiver->refresh();
    expect((float) $sender->balance)->toBe(0.0);
    expect($sender->level)->toBe(1);
    expect((float) $receiver->balance)->toBe(50.0);
    expect($receiver->level)->toBe(3);
    expect($receiver->status)->toBe(1);
    expect((float) $receiverWallet->fresh()->balance)->toBe(0.0);
});

it('withdraws the jackpot receiver funds when receiver_withdraw is true', function () {
    [$sender, $receiver, $receiverWallet] = createJackpotWalletPair();

    $response = $this->postJson('/api/v1/competition/payout', [
        'sender_competition_wallet_id' => $sender->id,
        'receiver_competition_wallet_id' => $receiver->id,
        'receiver_withdraw' => true,
    ], apiHeaders($this->apiKey->key));

    $response->assertOk();
    $response->assertJsonPath('withdrawal.status', 'success');

    $receiver->refresh();
    expect((float) $receiver->balance)->toBe(0.0);
    expect($receiver->status)->toBe(3);
    expect((float) $receiverWallet->fresh()->balance)->toBe(50.0);
});

it('pays out a jackpot round whose jp_rounds is not a milestone value', function () {
    [$sender, $receiver] = createJackpotWalletPair();
    $sender->update(['jp_rounds' => 7]);
    $receiver->update(['jp_rounds' => 7]);

    $response = $this->postJson('/api/v1/competition/payout', [
        'sender_competition_wallet_id' => $sender->id,
        'receiver_competition_wallet_id' => $receiver->id,
    ], apiHeaders($this->apiKey->key));

    $response->assertOk();
    expect((float) $receiver->fresh()->balance)->toBe(50.0);
});

it('rejects a payout where sender and receiver are the same wallet', function () {
    [$sender] = createTournamentWalletPair();

    $response = $this->postJson('/api/v1/competition/payout', [
        'sender_competition_wallet_id' => $sender->id,
        'receiver_competition_wallet_id' => $sender->id,
    ], apiHeaders($this->apiKey->key));

    $response->assertStatus(400);
    $response->assertJsonFragment(['Sender and Receiver Competition Wallets must be different']);
    expect((float) $sender->fresh()->balance)->toBe(50.0);
});

it('rejects a payout when the sender wallet is not open', function () {
    [$sender, $receiver] = createTournamentWalletPair();
    $sender->update(['status' => 3]);

    $response = $this->postJson('/api/v1/competition/payout', [
        'sender_competition_wallet_id' => $sender->id,
        'receiver_competition_wallet_id' => $receiver->id,
    ], apiHeaders($this->apiKey->key));

    $response->assertStatus(400);
    $response->assertJsonFragment(['Sender Competition Wallet is not open']);
    expect((float) $receiver->fresh()->balance)->toBe(0.0);
});

it('rejects a payout when the receiver wallet is not open', function () {
    [$sender, $receiver] = createTournamentWalletPair();
    $receiver->update(['status' => 3]);

    $response = $this->postJson('/api/v1/competition/payout', [
        'sender_competition_wallet_id' => $sender->id,
        'receiver_competition_wallet_id' => $receiver->id,
        'receiver_withdraw' => true,
    ], apiHeaders($this->apiKey->key));

    $response->assertStatus(400);
    $response->assertJsonFragment(['Receiver Competition Wallet is not open']);
    expect((float) $sender->fresh()->balance)->toBe(50.0);
});

/**
 * Runs $race once, right after the service's first plain read of a competition wallet, the way a concurrent
 * request would: the read has happened and the row changes before the transaction locks it.
 */
function afterFirstCompetitionWalletRead(callable $race): void
{
    $fired = false;
    DB::listen(function (QueryExecuted $query) use (&$fired, $race) {
        if (! $fired && preg_match('/^select \* from [`"]?competition_wallets[`"]? where .* limit 1$/i', $query->sql)) {
            $fired = true;
            $race();
        }
    });
}

/**
 * Runs $race once, right after the service takes its first competition wallet lock: what a complaint filed while
 * the request waited for that lock looks like once the lock is granted. Only a re-check under the lock catches it.
 */
function afterFirstCompetitionWalletLock(callable $race): void
{
    $fired = false;
    DB::listen(function (QueryExecuted $query) use (&$fired, $race) {
        if (! $fired && preg_match('/^select .* from [`"]?competition_wallets[`"]?.* for update$/i', $query->sql)) {
            $fired = true;
            $race();
        }
    });
}

/**
 * Collects the rows locked FOR UPDATE, in order, as competition:<id> or wallet:<customer_id or id>.
 *
 * @param  list<string>  $locks
 */
function recordCompetitionLocks(array &$locks): void
{
    DB::listen(function (QueryExecuted $query) use (&$locks) {
        if (preg_match('/^select .* from [`"]?(competition_wallets|wallets)[`"]?.* for update$/i', $query->sql, $match)) {
            $locks[] = ($match[1] === 'competition_wallets' ? 'competition:' : 'wallet:').$query->bindings[0];
        }
    });
}

function withdrawCompetition(object $test, CompetitionWallet $wallet)
{
    return $test->postJson('/api/v1/competition/withdraw/'.encryptId($wallet->id), ['customer_id' => (string) $wallet->customer_id], apiHeaders($test->apiKey->key) + ['Idempotency-Key' => (string) Str::uuid()]);
}

function payCompetitionRound(object $test, CompetitionWallet $sender, CompetitionWallet $receiver)
{
    return $test->postJson('/api/v1/competition/payout', [
        'sender_competition_wallet_id' => $sender->id,
        'receiver_competition_wallet_id' => $receiver->id,
    ], apiHeaders($test->apiKey->key));
}

it('locks the competition wallet before the player wallet when withdrawing', function () {
    [$sender, $receiver] = createTournamentWalletPair();
    $receiver->update(['balance' => 50]);
    $locks = [];
    recordCompetitionLocks($locks);

    withdrawCompetition($this, $receiver)->assertOk();

    expect($locks)->toBe(['competition:'.$receiver->id, 'wallet:'.$receiver->customer_id]);
});

it('refuses a withdrawal when another one closed the competition wallet first', function () {
    [$sender, $receiver, $receiverWallet] = createTournamentWalletPair();
    $receiver->update(['balance' => 50]);
    afterFirstCompetitionWalletRead(fn () => DB::table('competition_wallets')->where('id', $receiver->id)->update(['status' => 3, 'balance' => 0]));

    withdrawCompetition($this, $receiver)->assertStatus(400)->assertJsonPath('status', 'Competition Wallet is not open for withdrawal');

    expect((float) $receiverWallet->fresh()->balance)->toBe(0.0)
        ->and(LedgerEntry::where('entry_type', 'competition_payout')->count())->toBe(0);
});

it('locks both competition wallets in id order when paying a round', function () {
    [$sender, $receiver] = createTournamentWalletPair();
    $receiver->update(['balance' => 50]);
    $locks = [];
    recordCompetitionLocks($locks);

    payCompetitionRound($this, $receiver, $sender)->assertOk();

    expect($sender->id)->toBeLessThan($receiver->id)
        ->and($locks)->toBe(['competition:'.$sender->id, 'competition:'.$receiver->id]);
});

it('refuses a round transfer when another one emptied the sender first', function () {
    [$sender, $receiver] = createTournamentWalletPair();
    afterFirstCompetitionWalletRead(fn () => DB::table('competition_wallets')->where('id', $sender->id)->update(['balance' => 0]));

    payCompetitionRound($this, $sender, $receiver)->assertStatus(400)->assertJsonPath('error', 'Insufficient balance in Sender Competition Wallet');

    expect((float) $receiver->fresh()->balance)->toBe(0.0)
        ->and((float) $sender->fresh()->balance)->toBe(0.0)
        ->and($receiver->fresh()->level)->toBe(2)
        ->and(LedgerEntry::where('entry_type', 'escrow_transfer')->count())->toBe(0);
});

it('refuses a withdrawal when a complaint froze the competition wallet while it waited for the lock', function () {
    [$sender, $receiver, $receiverWallet] = createTournamentWalletPair();
    $receiver->update(['balance' => 50]);
    afterFirstCompetitionWalletLock(fn () => Complaint::factory()->create(['subject_type' => Complaint::SUBJECT_TOURNAMENT, 'competition_wallet_id' => $receiver->id]));

    withdrawCompetition($this, $receiver)->assertStatus(400)->assertJsonPath('status', 'Competition Wallet is frozen by a pending complaint');

    expect((float) $receiverWallet->fresh()->balance)->toBe(0.0)
        ->and((float) $receiver->fresh()->balance)->toBe(50.0);
});

it('refuses a round transfer when a complaint froze a wallet while it waited for the lock', function () {
    [$sender, $receiver] = createTournamentWalletPair();
    afterFirstCompetitionWalletLock(fn () => Complaint::factory()->create(['subject_type' => Complaint::SUBJECT_TOURNAMENT, 'competition_wallet_id' => $receiver->id]));

    payCompetitionRound($this, $sender, $receiver)->assertStatus(400)->assertJsonPath('error', 'Competition Wallet is frozen by a pending complaint');

    expect((float) $sender->fresh()->balance)->toBe(50.0)
        ->and((float) $receiver->fresh()->balance)->toBe(0.0)
        ->and(LedgerEntry::where('entry_type', 'escrow_transfer')->count())->toBe(0);
});
