<?php

use App\Exceptions\MpesaApiException;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\ReferralWallet;
use App\Models\ReferralWithdrawal;
use App\Services\FinanceDateRange;
use App\Services\FinanceReportService;
use App\Services\MpesaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    config([
        'referrals.withdrawals.enabled' => true,
        'referrals.withdrawals.minimum' => 50,
        'mpesa.apps.b2c' => ['consumer_key' => 'main-key', 'consumer_secret' => 'main-secret'],
        'mpesa.b2c.initiator_name' => 'mainapi',
        'mpesa.b2c.security_credential' => 'main-pass',
        'mpesa.b2c.short_code' => '3009678',
        'mpesa.b2c.result_url' => 'https://api.test/api/v1/b2c/result',
        'mpesa.b2c.timeout_url' => 'https://api.test/api/v1/b2c/timeout',
        'mpesa.apps.referral_b2c' => ['consumer_key' => 'referral-key', 'consumer_secret' => 'referral-secret'],
        'mpesa.referral_b2c' => [
            'initiator_name' => 'kadiapi',
            'security_credential' => 'referral-pass',
            'short_code' => '4151665',
            'default_command_id' => 'BusinessPayment',
            'result_url' => 'https://api.test/api/v1/referral/b2c/result',
            'timeout_url' => 'https://api.test/api/v1/referral/b2c/timeout',
        ],
    ]);

    $this->apiKey = createApiKey('test-referral-withdrawals-key');
    $this->headers = apiHeaders($this->apiKey->key);

    $this->customer = Customer::factory()->phoneVerified()->create(['phone_no' => '0712345678']);
    $this->referralWallet = ReferralWallet::factory()->create(['customer_id' => $this->customer->id, 'balance' => 120]);
});

/**
 * @param  array<string, mixed>|Throwable  $outcome  referralB2c() reply, or the exception it throws
 */
function mockReferralB2c(array|Throwable $outcome): void
{
    $mpesa = Mockery::mock(MpesaService::class);
    $expectation = $mpesa->shouldReceive('referralB2c')->once();
    $outcome instanceof Throwable ? $expectation->andThrow($outcome) : $expectation->andReturn($outcome);
    $mpesa->shouldNotReceive('b2c');
    app()->instance(MpesaService::class, $mpesa);
}

function requestReferralWithdrawal(Customer $customer, int $amount)
{
    return test()->postJson('/api/v1/customers/'.encryptId($customer->id).'/referral-wallet/withdraw', ['amount' => $amount], test()->headers);
}

/**
 * Safaricom's B2C result body, nested under "Result".
 */
function referralB2cResult(string $conversationId, int $resultCode, string $transactionId = 'RKA1B2C3D4'): array
{
    return ['Result' => [
        'ResultType' => 0,
        'ResultCode' => $resultCode,
        'ResultDesc' => $resultCode === 0 ? 'The service request is processed successfully.' : 'The balance is insufficient for the transaction.',
        'OriginatorConversationID' => '1234-5678-1',
        'ConversationID' => $conversationId,
        'TransactionID' => $transactionId,
    ]];
}

// requesting

it('debits the referral wallet and sends the payout from the referral shortcode', function () {
    mockReferralB2c(['ResponseCode' => '0', 'ConversationID' => 'AG_REF_1', 'OriginatorConversationID' => '1234-5678-1']);

    requestReferralWithdrawal($this->customer, 100)
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'processing')
        ->assertJsonPath('data.amount', 100)
        ->assertJsonPath('data.phone_no', '2547****5678');

    $withdrawal = ReferralWithdrawal::sole();
    $entry = LedgerEntry::find($withdrawal->ledger_entry_id);

    expect((float) $this->referralWallet->fresh()->balance)->toBe(20.0)
        ->and($withdrawal->phone_no)->toBe('254712345678')
        ->and($withdrawal->conversation_id)->toBe('AG_REF_1')
        ->and($entry->entry_type)->toBe('referral_withdrawal')
        ->and($entry->wallet_type)->toBe(LedgerEntry::WALLET_TYPE_REFERRAL)
        ->and((float) $entry->debit)->toBe(100.0)
        ->and((float) $entry->balance_after)->toBe(20.0);
});

it('refuses amounts under the minimum, over the balance, or while switched off', function () {
    $mpesa = Mockery::mock(MpesaService::class);
    $mpesa->shouldNotReceive('referralB2c');
    $mpesa->shouldNotReceive('b2c');
    app()->instance(MpesaService::class, $mpesa);

    requestReferralWithdrawal($this->customer, 49)->assertStatus(422);
    requestReferralWithdrawal($this->customer, 121)->assertStatus(400);

    config(['referrals.withdrawals.enabled' => false]);
    requestReferralWithdrawal($this->customer, 100)->assertStatus(403);

    expect(ReferralWithdrawal::count())->toBe(0)
        ->and((float) $this->referralWallet->fresh()->balance)->toBe(120.0);
});

it('refuses before debiting when the referral shortcode is not configured', function () {
    config(['mpesa.referral_b2c.short_code' => null]);

    requestReferralWithdrawal($this->customer, 100)->assertStatus(503);

    expect(ReferralWithdrawal::count())->toBe(0)
        ->and((float) $this->referralWallet->fresh()->balance)->toBe(120.0);
});

it('refuses a customer without a valid M-Pesa number', function () {
    // Verified, so the number itself is what is refused (a new phone otherwise clears its verification first).
    $this->customer->update(['phone_no' => '12345']);
    $this->customer->forceFill(['phone_no_verified_at' => now()])->save();

    requestReferralWithdrawal($this->customer, 100)->assertStatus(400);

    expect(ReferralWithdrawal::count())->toBe(0);
});

it('reverses at once when Safaricom rejects the request', function () {
    mockReferralB2c(new MpesaApiException('Bad request', statusCode: 400, errorCode: '400.002.02'));

    requestReferralWithdrawal($this->customer, 100)->assertStatus(502)->assertJsonPath('data.status', 'failed');

    $withdrawal = ReferralWithdrawal::sole();

    expect((float) $this->referralWallet->fresh()->balance)->toBe(120.0)
        ->and($withdrawal->result_code)->toBe('400.002.02')
        ->and(LedgerEntry::find($withdrawal->reversal_entry_id)->entry_type)->toBe('referral_withdrawal_reversal')
        ->and(LedgerEntry::find($withdrawal->ledger_entry_id)->status)->toBe('reversed');
});

it('reverses when Safaricom answers with a non-zero response code', function () {
    mockReferralB2c(['ResponseCode' => '1', 'ResponseDescription' => 'Rejected']);

    requestReferralWithdrawal($this->customer, 100)->assertStatus(502);

    expect(ReferralWithdrawal::sole()->status)->toBe('failed')
        ->and((float) $this->referralWallet->fresh()->balance)->toBe(120.0);
});

it('keeps the money held when the outcome is unknown', function () {
    mockReferralB2c(new ConnectionException('cURL error 28: Operation timed out'));

    requestReferralWithdrawal($this->customer, 100)->assertStatus(202)->assertJsonPath('data.status', 'pending');

    expect((float) $this->referralWallet->fresh()->balance)->toBe(20.0)
        ->and(LedgerEntry::where('entry_type', 'referral_withdrawal_reversal')->count())->toBe(0);
});

// result callback

it('completes a withdrawal from the nested result callback, once', function () {
    mockReferralB2c(['ResponseCode' => '0', 'ConversationID' => 'AG_REF_1']);
    requestReferralWithdrawal($this->customer, 100);

    $this->postJson('/api/v1/referral/b2c/result', referralB2cResult('AG_REF_1', 0))->assertOk()->assertJson(['ResultCode' => 0]);
    $this->postJson('/api/v1/referral/b2c/result', referralB2cResult('AG_REF_1', 1))->assertOk();

    $withdrawal = ReferralWithdrawal::sole();

    expect($withdrawal->status)->toBe('completed')
        ->and($withdrawal->mpesa_receipt)->toBe('RKA1B2C3D4')
        ->and($withdrawal->completed_at)->not->toBeNull()
        ->and((float) $this->referralWallet->fresh()->balance)->toBe(20.0);
});

it('refunds a withdrawal whose result callback reports failure, once', function () {
    mockReferralB2c(['ResponseCode' => '0', 'ConversationID' => 'AG_REF_2']);
    requestReferralWithdrawal($this->customer, 100);

    $this->postJson('/api/v1/referral/b2c/result', referralB2cResult('AG_REF_2', 2001))->assertOk();
    $this->postJson('/api/v1/referral/b2c/result', referralB2cResult('AG_REF_2', 2001))->assertOk();

    expect(ReferralWithdrawal::sole()->status)->toBe('failed')
        ->and((float) $this->referralWallet->fresh()->balance)->toBe(120.0)
        ->and(LedgerEntry::where('entry_type', 'referral_withdrawal_reversal')->count())->toBe(1);
});

it('ignores results for unknown conversations and leaves timeouts pending', function () {
    mockReferralB2c(['ResponseCode' => '0', 'ConversationID' => 'AG_REF_3']);
    requestReferralWithdrawal($this->customer, 100);

    $this->postJson('/api/v1/referral/b2c/result', referralB2cResult('AG_SOMEONE_ELSE', 0))->assertOk();
    $this->postJson('/api/v1/referral/b2c/timeout', referralB2cResult('AG_REF_3', 1))->assertOk();

    expect(ReferralWithdrawal::sole()->status)->toBe('processing')
        ->and((float) $this->referralWallet->fresh()->balance)->toBe(20.0);
});

it('does not touch main wallet withdrawals when a referral result arrives', function () {
    $this->postJson('/api/v1/referral/b2c/result', referralB2cResult('AG_MAIN_1', 2001))->assertOk();

    expect(LedgerEntry::count())->toBe(0);
});

// listing

it('lists a customer referral withdrawals and all withdrawals for admins', function () {
    mockReferralB2c(['ResponseCode' => '0', 'ConversationID' => 'AG_REF_4']);
    requestReferralWithdrawal($this->customer, 60);

    $this->getJson('/api/v1/customers/'.encryptId($this->customer->id).'/referral-wallet/withdrawals', $this->headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.amount', 60)
        ->assertJsonPath('data.0.status', 'processing');

    $this->getJson('/api/v1/referral-withdrawals?status=processing', $this->headers)
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson('/api/v1/customers/'.encryptId($this->customer->id).'/referral-wallet', $this->headers)
        ->assertOk()
        ->assertJsonPath('data.balance', 60)
        ->assertJsonPath('data.withdrawable', true)
        ->assertJsonPath('data.minimum_withdrawal', 50);
});

// M-Pesa wiring

it('sends referral payouts with the referral credentials and shortcode', function () {
    Http::fake([
        '*/oauth/v1/generate*' => Http::response(['access_token' => 'referral-token']),
        '*/mpesa/b2c/v1/paymentrequest' => Http::response(['ResponseCode' => '0', 'ConversationID' => 'AG_HTTP_1']),
    ]);

    (new MpesaService(false))->referralB2c(['Amount' => 100, 'PartyB' => '254712345678']);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/oauth/v1/generate')
        && $request->header('Authorization')[0] === 'Basic '.base64_encode('referral-key:referral-secret'));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/mpesa/b2c/v1/paymentrequest')
        && $request->header('Authorization')[0] === 'Bearer referral-token'
        && $request['PartyA'] === '4151665'
        && $request['InitiatorName'] === 'kadiapi'
        && $request['ResultURL'] === 'https://api.test/api/v1/referral/b2c/result'
        && $request['QueueTimeOutURL'] === 'https://api.test/api/v1/referral/b2c/timeout');
});

it('leaves the main B2C payout on the main credentials and shortcode', function () {
    Http::fake([
        '*/oauth/v1/generate*' => Http::response(['access_token' => 'main-token']),
        '*/mpesa/b2c/v1/paymentrequest' => Http::response(['ResponseCode' => '0', 'ConversationID' => 'AG_HTTP_2']),
    ]);

    (new MpesaService(false))->b2c(['Amount' => 100, 'PartyB' => '254712345678']);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/oauth/v1/generate')
        && $request->header('Authorization')[0] === 'Basic '.base64_encode('main-key:main-secret'));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/mpesa/b2c/v1/paymentrequest')
        && $request['PartyA'] === '3009678'
        && $request['InitiatorName'] === 'mainapi'
        && $request['ResultURL'] === 'https://api.test/api/v1/b2c/result');
});

// finance

it('keeps referral payouts out of the trial balance paired check', function () {
    mockReferralB2c(['ResponseCode' => '0', 'ConversationID' => 'AG_REF_5']);
    requestReferralWithdrawal($this->customer, 100);

    $trialBalance = app(FinanceReportService::class)->trialBalance(FinanceDateRange::fromArray(['from' => now()->toDateString(), 'to' => now()->toDateString()]));
    $line = collect($trialBalance['lines'])->firstWhere('entry_type', 'referral_withdrawal');

    expect($trialBalance['check']['balanced'])->toBeTrue()
        ->and($line['account'])->toBe('referral_wallets')
        ->and($line['category'])->toBe('referral_payout');
});

// manual settlement (GMS admin)

function settleReferralWithdrawal(ReferralWithdrawal $withdrawal, array $payload)
{
    return test()->postJson('/api/v1/referral-withdrawals/'.encryptId($withdrawal->id).'/settle', $payload, test()->headers);
}

it('marks a stuck withdrawal completed with the M-Pesa receipt', function () {
    mockReferralB2c(['ResponseCode' => '0', 'ConversationID' => 'AG_STUCK_1']);
    requestReferralWithdrawal($this->customer, 100);
    $withdrawal = ReferralWithdrawal::sole();

    settleReferralWithdrawal($withdrawal, ['outcome' => 'completed', 'mpesa_receipt' => 'rka1b2c3d4', 'note' => 'Found on the 4151665 statement'])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.mpesa_receipt', 'RKA1B2C3D4')
        ->assertJsonPath('data.settled_by', 'api_key:'.$this->apiKey->id)
        ->assertJsonPath('data.settlement_note', 'Found on the 4151665 statement');

    expect((float) $this->referralWallet->fresh()->balance)->toBe(20.0)
        ->and($withdrawal->fresh()->completed_at)->not->toBeNull();

    // a late Safaricom result for it changes nothing
    $this->postJson('/api/v1/referral/b2c/result', referralB2cResult('AG_STUCK_1', 2001))->assertOk();
    expect($withdrawal->fresh()->status)->toBe('completed');
});

it('marks a stuck withdrawal failed and refunds the referral wallet once', function () {
    mockReferralB2c(new ConnectionException('timed out'));
    requestReferralWithdrawal($this->customer, 100)->assertStatus(202);
    $withdrawal = ReferralWithdrawal::sole();

    settleReferralWithdrawal($withdrawal, ['outcome' => 'failed', 'note' => 'Not on the statement'])
        ->assertOk()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.result_code', 'manual');

    settleReferralWithdrawal($withdrawal, ['outcome' => 'failed', 'note' => 'Again'])->assertStatus(409);

    expect((float) $this->referralWallet->fresh()->balance)->toBe(120.0)
        ->and(LedgerEntry::where('entry_type', 'referral_withdrawal_reversal')->count())->toBe(1);
});

it('validates a manual settlement', function () {
    mockReferralB2c(['ResponseCode' => '0', 'ConversationID' => 'AG_STUCK_2']);
    requestReferralWithdrawal($this->customer, 100);
    $withdrawal = ReferralWithdrawal::sole();

    settleReferralWithdrawal($withdrawal, ['outcome' => 'completed', 'note' => 'no receipt'])->assertUnprocessable()->assertJsonValidationErrors('mpesa_receipt');
    settleReferralWithdrawal($withdrawal, ['outcome' => 'reversed', 'note' => 'bad outcome'])->assertUnprocessable()->assertJsonValidationErrors('outcome');
    settleReferralWithdrawal($withdrawal, ['outcome' => 'failed'])->assertUnprocessable()->assertJsonValidationErrors('note');

    $this->postJson('/api/v1/referral-withdrawals/'.encryptId(99999).'/settle', ['outcome' => 'failed', 'note' => 'missing'], $this->headers)->assertNotFound();
});

it('refuses a receipt already used by another referral withdrawal', function () {
    mockReferralB2c(['ResponseCode' => '0', 'ConversationID' => 'AG_STUCK_3']);
    requestReferralWithdrawal($this->customer, 100);
    $stuck = ReferralWithdrawal::sole();
    $other = ReferralWithdrawal::create([
        'customer_id' => $this->customer->id, 'referral_wallet_id' => $this->referralWallet->id, 'amount' => 10,
        'phone_no' => '254712345678', 'status' => 'completed', 'mpesa_receipt' => 'RKDUPLICATE',
    ]);

    settleReferralWithdrawal($stuck, ['outcome' => 'completed', 'mpesa_receipt' => 'RKDUPLICATE', 'note' => 'typo'])->assertStatus(409);

    expect($stuck->fresh()->status)->toBe('processing');
});

it('shows one referral withdrawal', function () {
    mockReferralB2c(['ResponseCode' => '0', 'ConversationID' => 'AG_SHOW_1']);
    requestReferralWithdrawal($this->customer, 100);

    $this->getJson('/api/v1/referral-withdrawals/'.encryptId(ReferralWithdrawal::sole()->id), $this->headers)
        ->assertOk()
        ->assertJsonPath('data.status', 'processing')
        ->assertJsonPath('data.settled_by', null);
});
