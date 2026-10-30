<?php

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\Withdraw;
use App\Services\MpesaService;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A withdrawal of 80 from a wallet of 200 that Safaricom accepted with the given ConversationID.
 */
function acceptedB2cWithdrawal(string $conversationId): Wallet
{
    $customer = Customer::factory()->phoneVerified()->create(['phone_no' => '254700000009']);
    $wallet = Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 200]);

    $mpesa = Mockery::mock(MpesaService::class);
    $mpesa->shouldReceive('b2c')->once()->andReturn(['ResponseCode' => '0', 'ConversationID' => $conversationId, 'OriginatorConversationID' => '29115-34620561-1']);
    app()->instance(MpesaService::class, $mpesa);

    expect(app(WithdrawalService::class)->initiateWithdrawal((string) $customer->id, 80)['success'])->toBeTrue();

    return $wallet;
}

/**
 * The body Safaricom posts to the B2C ResultURL: everything nested under "Result".
 */
function safaricomB2cResult(string $conversationId, int $resultCode, string $transactionId = 'NLJ41HAY6Q'): array
{
    return ['Result' => [
        'ResultType' => 0,
        'ResultCode' => $resultCode,
        'ResultDesc' => $resultCode === 0 ? 'The service request is processed successfully.' : 'The initiator information is invalid.',
        'OriginatorConversationID' => '29115-34620561-1',
        'ConversationID' => $conversationId,
        'TransactionID' => $transactionId,
        'ResultParameters' => ['ResultParameter' => [['Key' => 'TransactionAmount', 'Value' => 80]]],
        'ReferenceData' => ['ReferenceItem' => ['Key' => 'QueueTimeoutURL', 'Value' => 'https://api.test/api/v1/b2c/timeout']],
    ]];
}

it('records the M-Pesa receipt from a nested success result', function () {
    $wallet = acceptedB2cWithdrawal('AG_20260924_NESTED_OK');

    $this->postJson('/api/v1/b2c/result', safaricomB2cResult('AG_20260924_NESTED_OK', 0))->assertOk();

    $transaction = Transaction::where('payment_type', Withdraw::class)->sole();

    expect($transaction->payment_ref)->toBe('NLJ41HAY6Q')
        ->and($transaction->status)->toBe(2)
        ->and((float) $wallet->fresh()->balance)->toBe(120.0)
        ->and(LedgerEntry::where('entry_type', 'withdrawal_reversal')->count())->toBe(0);
});

it('refunds the wallet once from a nested failure result', function () {
    $wallet = acceptedB2cWithdrawal('AG_20260924_NESTED_FAIL');

    $this->postJson('/api/v1/b2c/result', safaricomB2cResult('AG_20260924_NESTED_FAIL', 2001))->assertOk();
    $this->postJson('/api/v1/b2c/result', safaricomB2cResult('AG_20260924_NESTED_FAIL', 2001))->assertOk();

    $withdraw = Withdraw::sole();

    expect((float) $wallet->fresh()->balance)->toBe(200.0)
        ->and($withdraw->disburse)->toBe(3)
        ->and($withdraw->error_message)->toBe('The initiator information is invalid.')
        ->and(Transaction::where('payment_type', Withdraw::class)->sole()->status)->toBe(3)
        ->and(LedgerEntry::where('entry_type', 'withdrawal_reversal')->count())->toBe(1);
});

it('changes nothing when the result has no ResultCode', function () {
    $wallet = acceptedB2cWithdrawal('AG_20260924_NO_CODE');

    $this->postJson('/api/v1/b2c/result', ['Result' => ['ConversationID' => 'AG_20260924_NO_CODE']])->assertOk();

    $transaction = Transaction::where('payment_type', Withdraw::class)->sole();

    expect($transaction->payment_ref)->toBe('AG_20260924_NO_CODE')
        ->and((float) $wallet->fresh()->balance)->toBe(120.0);
});

it('ignores results for conversations it did not send', function () {
    $wallet = acceptedB2cWithdrawal('AG_20260924_MINE');

    $this->postJson('/api/v1/b2c/result', safaricomB2cResult('AG_20260924_SOMEONE_ELSE', 2001))->assertOk();

    expect((float) $wallet->fresh()->balance)->toBe(120.0)
        ->and(Transaction::where('payment_type', Withdraw::class)->sole()->payment_ref)->toBe('AG_20260924_MINE');
});
