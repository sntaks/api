<?php

use App\Jobs\SendWalletWebhookJob;
use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Models\Withdraw;
use App\Models\WithdrawalApproval;
use App\Services\MpesaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->key = createApiKey('approval-full-key');
    $this->player = Customer::factory()->phoneVerified()->create(['status' => 1, 'phone_no' => '254712345678']);
    $this->wallet = Wallet::factory()->create(['customer_id' => $this->player->id, 'balance' => 100_000]);
    RateLimiter::clear('withdrawals:'.$this->player->id);
    $this->b2cCalls = [];
});

/**
 * M-Pesa B2C mocked to accept, recording every payout it was asked to send.
 */
function acceptPayouts(object $test, bool $accept = true): void
{
    // Read at call time: the router keeps a controller (and the services injected into it) for the whole test,
    // so a test switches outcomes through $test->b2cAccept rather than a second mock.
    $test->b2cAccept = $accept;
    $mpesa = Mockery::mock(MpesaService::class);
    $mpesa->shouldReceive('b2c')->andReturnUsing(function (array $params) use ($test) {
        $test->b2cCalls[] = $params;

        return $test->b2cAccept
            ? ['ResponseCode' => '0', 'ConversationID' => 'AG_'.count($test->b2cCalls)]
            : ['ResponseCode' => '1', 'ResponseDescription' => 'Rejected'];
    });
    app()->instance(MpesaService::class, $mpesa);
}

function requestWithdrawal(object $test, int $amount)
{
    return $test->postJson('/api/v1/withdraw/'.encryptId($test->player->id), ['amount' => $amount], apiHeaders($test->key->key));
}

// Task 4b.1: holding

it('holds a withdrawal of the threshold or more for approval', function () {
    acceptPayouts($this);

    $response = requestWithdrawal($this, 20_000)
        ->assertStatus(202)
        ->assertJsonPath('status', 'awaiting_approval');

    $approval = WithdrawalApproval::sole();

    expect($this->b2cCalls)->toBe([])
        ->and((float) $this->wallet->fresh()->balance)->toBe(80_000.0)
        ->and(LedgerEntry::where('entry_type', 'withdrawal')->count())->toBe(1)
        ->and($response->json('withdrawal_id'))->toBe($approval->withdraw_id)
        ->and($response->json('ledger_entry_id'))->not->toBeNull()
        ->and($approval->status)->toBe(WithdrawalApproval::STATUS_AWAITING)
        ->and($approval->phone_no)->toBe('254712345678')
        ->and((float) $approval->amount)->toBe(20_000.0)
        ->and($approval->customer_id)->toBe($this->player->id)
        ->and(Withdraw::sole()->disburse)->toBe(1);
});

it('still pays a withdrawal below the threshold at once', function () {
    acceptPayouts($this);

    requestWithdrawal($this, 19_999)->assertCreated()->assertJsonPath('status', 'success');

    expect($this->b2cCalls)->toHaveCount(1)
        ->and(WithdrawalApproval::count())->toBe(0);
});

it('counts a held withdrawal toward the hourly cap', function () {
    acceptPayouts($this);

    // A minute apart: the route also has a per-key limit of 5 a minute.
    requestWithdrawal($this, 20_000)->assertStatus(202);
    foreach (range(1, 4) as $i) {
        $this->travel(61)->seconds();
        requestWithdrawal($this, 1_000)->assertCreated();
    }
    $this->travel(61)->seconds();

    requestWithdrawal($this, 1_000)->assertStatus(429)->assertJsonPath('code', 'too_many_withdrawals');
});

it('does not list a held withdrawal as stuck', function () {
    acceptPayouts($this);
    requestWithdrawal($this, 25_000)->assertStatus(202);
    $this->travel(3)->days();
    Cache::flush();

    $checks = collect($this->getJson('/api/v1/finance/reconciliation', apiHeaders($this->key->key))->assertOk()->json('data.checks'));

    expect($checks->firstWhere('key', 'stuck_withdrawals')['count'])->toBe(0);
});

// Task 4b.2: deciding

function holdWithdrawal(object $test, int $amount = 25_000): int
{
    return requestWithdrawal($test, $amount)->assertStatus(202)->json('withdrawal_id');
}

function decide(object $test, int $withdrawId, string $action, array $body = [], ?string $key = null)
{
    return $test->postJson("/api/v1/withdrawals/{$withdrawId}/{$action}", $body + ['admin_user' => 'ann'], apiHeaders($key ?? $test->key->key));
}

it('pays an approved withdrawal to the phone verified at request time', function () {
    acceptPayouts($this);
    $id = holdWithdrawal($this);
    $this->player->update(['phone_no' => '254799000111']);

    decide($this, $id, 'approve')->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.admin_user', 'ann');

    $approval = WithdrawalApproval::sole();
    expect($this->b2cCalls)->toHaveCount(1)
        ->and($this->b2cCalls[0]['PartyB'])->toBe('254712345678')
        ->and($this->b2cCalls[0]['Amount'])->toEqual(25_000)
        ->and($approval->decided_by)->toBe("api_key:{$this->key->id}")
        ->and($approval->decided_at)->not->toBeNull()
        ->and(Withdraw::sole()->disburse)->toBe(2)
        ->and((float) $this->wallet->fresh()->balance)->toBe(75_000.0);
});

it('refunds a rejected withdrawal and tells kadi', function () {
    acceptPayouts($this);
    config(['wallet-webhook.enabled' => true, 'wallet-webhook.url' => 'https://kadi-site.test/api/v1/wallet-webhooks', 'wallet-webhook.secret' => str_repeat('a', 32)]);
    $id = holdWithdrawal($this);
    Queue::fake();

    decide($this, $id, 'reject', ['reason' => 'Account under review'])->assertOk()->assertJsonPath('data.status', 'rejected');

    expect($this->b2cCalls)->toBe([])
        ->and((float) $this->wallet->fresh()->balance)->toBe(100_000.0)
        ->and(LedgerEntry::where('entry_type', 'withdrawal_reversal')->count())->toBe(1)
        ->and(Withdraw::sole()->disburse)->toBe(3)
        ->and(Withdraw::sole()->error_message)->toBe('Rejected: Account under review')
        ->and(WithdrawalApproval::sole()->reason)->toBe('Account under review');
    Queue::assertPushed(SendWalletWebhookJob::class);
});

it('refunds when M-Pesa refuses an approved withdrawal', function () {
    acceptPayouts($this, accept: false);
    $id = holdWithdrawal($this);

    decide($this, $id, 'approve')->assertStatus(502)->assertJsonPath('data.status', 'approved');

    expect((float) $this->wallet->fresh()->balance)->toBe(100_000.0)
        ->and(Withdraw::sole()->disburse)->toBe(3);
});

it('decides a withdrawal only once', function () {
    acceptPayouts($this);
    $id = holdWithdrawal($this);

    decide($this, $id, 'approve')->assertOk();
    // The identical request is replayed by the idempotency middleware; a different one reaches the decision.
    decide($this, $id, 'approve')->assertOk();
    decide($this, $id, 'approve', ['admin_user' => 'ben'])->assertStatus(409)->assertJsonPath('code', 'not_awaiting')->assertJsonPath('status', 'approved');
    decide($this, $id, 'reject', ['reason' => 'too late'])->assertStatus(409)->assertJsonPath('code', 'not_awaiting');

    expect($this->b2cCalls)->toHaveCount(1)
        ->and((float) $this->wallet->fresh()->balance)->toBe(75_000.0);
});

it('requires an admin user and a reason', function () {
    acceptPayouts($this);
    $id = holdWithdrawal($this);

    $this->postJson("/api/v1/withdrawals/{$id}/approve", [], apiHeaders($this->key->key))->assertUnprocessable()->assertJsonValidationErrors('admin_user');
    decide($this, $id, 'reject')->assertUnprocessable()->assertJsonValidationErrors('reason');
});

it('answers 404 for a withdrawal that is not held', function () {
    acceptPayouts($this);
    requestWithdrawal($this, 1_000)->assertCreated();

    decide($this, Withdraw::sole()->id, 'approve')->assertNotFound();
    decide($this, 999_999, 'approve')->assertNotFound();
});

it('lists withdrawals awaiting approval, oldest first', function () {
    acceptPayouts($this);
    $first = holdWithdrawal($this, 30_000);
    $this->travel(2)->minutes();
    $second = holdWithdrawal($this, 21_000);
    $this->travel(2)->minutes();
    $decided = holdWithdrawal($this, 22_000);
    decide($this, $decided, 'reject', ['reason' => 'duplicate'])->assertOk();

    $this->getJson('/api/v1/withdrawals/pending', apiHeaders($this->key->key))
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 2)
        ->assertJsonPath('data.items.0.withdrawal_id', $first)
        ->assertJsonPath('data.items.0.amount', 30000)
        ->assertJsonPath('data.items.0.player_no', $this->player->player_no)
        ->assertJsonPath('data.items.1.withdrawal_id', $second);

    $this->getJson('/api/v1/withdrawals/approvals?status=rejected', apiHeaders($this->key->key))
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.items.0.withdrawal_id', $decided)
        ->assertJsonPath('data.items.0.reason', 'duplicate');
});

it('warns on withdrawals waiting more than a day', function () {
    acceptPayouts($this);
    holdWithdrawal($this, 25_000);
    $this->travel(25)->hours();
    Cache::flush();

    $check = collect($this->getJson('/api/v1/finance/reconciliation', apiHeaders($this->key->key))->assertOk()->json('data.checks'))
        ->firstWhere('key', 'withdrawals_awaiting_approval');

    expect($check['status'])->toBe('warn')
        ->and($check['count'])->toBe(1)
        ->and($check['amount'])->toEqual(25_000);
});

it('keeps the approval routes off the site key', function () {
    acceptPayouts($this);
    $id = holdWithdrawal($this);
    $site = ApiKey::create(['key' => 'site_test_key', 'name' => 'kadi-site', 'scope' => ApiKey::SCOPE_SITE, 'is_active' => true]);

    decide($this, $id, 'approve', key: $site->key)->assertForbidden()->assertJsonPath('code', 'route_not_allowed');
    $this->getJson('/api/v1/withdrawals/pending', apiHeaders($site->key))->assertForbidden();
});

it('settles an approved withdrawal from its B2C result', function () {
    acceptPayouts($this);
    $id = holdWithdrawal($this);
    decide($this, $id, 'approve')->assertOk();

    $this->postJson('/api/v1/b2c/result', ['Result' => [
        'ResultCode' => 0,
        'ResultDesc' => 'The service request is processed successfully.',
        'ConversationID' => 'AG_1',
        'TransactionID' => 'RJK1234567',
    ]])->assertOk();

    expect((float) $this->wallet->fresh()->balance)->toBe(75_000.0)
        ->and(LedgerEntry::where('entry_type', 'withdrawal_reversal')->count())->toBe(0);
});

// Task 4b.3: the player cancels

function cancelWithdrawal(object $test, Customer $customer, int $withdrawId)
{
    $site = ApiKey::firstOrCreate(['key' => 'site_test_key'], ['name' => 'kadi-site', 'scope' => ApiKey::SCOPE_SITE, 'is_active' => true]);

    return $test->postJson('/api/v1/customers/'.encryptId($customer->id)."/withdrawals/{$withdrawId}/cancel", [], apiHeaders($site->key));
}

it('lets the player cancel their own held withdrawal', function () {
    acceptPayouts($this);
    $id = holdWithdrawal($this);

    cancelWithdrawal($this, $this->player, $id)->assertOk()->assertJsonPath('data.status', 'cancelled');

    $approval = WithdrawalApproval::sole();
    expect($this->b2cCalls)->toBe([])
        ->and((float) $this->wallet->fresh()->balance)->toBe(100_000.0)
        ->and($approval->decided_by)->toStartWith('api_key:')
        ->and($approval->admin_user)->toBeNull()
        ->and(Withdraw::sole()->error_message)->toBe('Cancelled: Cancelled by the player');
});

it('refuses to cancel another player\'s withdrawal', function () {
    acceptPayouts($this);
    $id = holdWithdrawal($this);
    $other = Customer::factory()->create(['status' => 1]);

    cancelWithdrawal($this, $other, $id)->assertNotFound();

    expect(WithdrawalApproval::sole()->status)->toBe(WithdrawalApproval::STATUS_AWAITING);
});

it('refuses to cancel a decided withdrawal', function () {
    acceptPayouts($this);
    $id = holdWithdrawal($this);
    decide($this, $id, 'approve')->assertOk();

    cancelWithdrawal($this, $this->player, $id)->assertStatus(409)->assertJsonPath('code', 'not_awaiting');

    expect((float) $this->wallet->fresh()->balance)->toBe(75_000.0);
});

// Final review fixes

it('refunds a second refused payout as cleanly as the first', function () {
    acceptPayouts($this, accept: false);

    // Below the threshold: paid (here refused) at once. M-Pesa answers the same code both times.
    requestWithdrawal($this, 1_000)->assertStatus(500);
    $this->travel(61)->seconds();
    requestWithdrawal($this, 2_000)->assertStatus(500);

    expect((float) $this->wallet->fresh()->balance)->toBe(100_000.0)
        ->and(Withdraw::where('disburse', 3)->count())->toBe(2)
        ->and(Withdraw::whereNotNull('receipt')->count())->toBe(0);
});

it('reports an approved payout whose outcome is unknown and never refunds it', function () {
    $id = holdWithdrawal($this);
    $mpesa = Mockery::mock(MpesaService::class);
    $mpesa->shouldReceive('b2c')->once()->andThrow(new ConnectionException('cURL error 28: Operation timed out'));
    app()->instance(MpesaService::class, $mpesa);

    decide($this, $id, 'approve')
        ->assertStatus(504)
        ->assertJsonPath('code', 'payout_unknown')
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.payout_status', 'unconfirmed');

    // A timeout may have paid: no refund, and the withdrawal says what happened.
    expect((float) $this->wallet->fresh()->balance)->toBe(75_000.0)
        ->and(LedgerEntry::where('entry_type', 'withdrawal_reversal')->count())->toBe(0)
        ->and(Withdraw::sole()->error_message)->toStartWith('Payout not confirmed:');
});

it('shows whether an approved withdrawal was paid, refused or is unconfirmed', function () {
    acceptPayouts($this, accept: false);
    $refused = holdWithdrawal($this, 21_000);
    decide($this, $refused, 'approve')->assertStatus(502);
    $this->travel(2)->minutes();
    $this->b2cAccept = true;
    $paid = holdWithdrawal($this, 22_000);
    decide($this, $paid, 'approve')->assertOk();
    $this->travel(2)->minutes();
    $waiting = holdWithdrawal($this, 23_000);

    $items = collect($this->getJson('/api/v1/withdrawals/approvals', apiHeaders($this->key->key))->assertOk()->json('data.items'))->keyBy('withdrawal_id');

    expect($items[$refused]['payout_status'])->toBe('failed')
        ->and($items[$refused]['payout_error'])->toBe('M-Pesa ResponseCode 1: Rejected')
        ->and($items[$paid]['payout_status'])->toBe('sent')
        ->and($items[$paid]['payout_error'])->toBeNull()
        ->and($items[$waiting]['payout_status'])->toBe('pending');
});
