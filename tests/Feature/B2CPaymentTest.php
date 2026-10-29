<?php

use App\Models\LedgerEntry;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->apiKey = createApiKey('test-b2c-send-key');

    config([
        'mpesa.apps.b2c' => ['consumer_key' => 'main-key', 'consumer_secret' => 'main-secret'],
        'mpesa.b2c.initiator_name' => 'mainapi',
        'mpesa.b2c.security_credential' => 'main-pass',
        'mpesa.b2c.short_code' => '3009678',
        'mpesa.b2c.result_url' => 'https://api.test/api/v1/b2c/result',
        'mpesa.b2c.timeout_url' => 'https://api.test/api/v1/b2c/timeout',
        'mpesa.b2c.send_allowed_ips' => ['127.0.0.1'],
    ]);
});

function sendB2C(array $body, string $key): TestResponse
{
    return test()->postJson('/api/v1/b2c/send', $body, apiHeaders($key) + ['Idempotency-Key' => (string) Str::uuid()]);
}

it('sends the amount from the main B2C shortcode to the phone', function () {
    Http::fake([
        '*/oauth/v1/generate*' => Http::response(['access_token' => 'main-token']),
        '*/mpesa/b2c/v1/paymentrequest' => Http::response([
            'ResponseCode' => '0',
            'ConversationID' => 'AG_SEND_1',
            'OriginatorConversationID' => 'ORIG_1',
        ]),
    ]);

    sendB2C(['phone' => '0712345678', 'amount' => 500], $this->apiKey->key)
        ->assertCreated()
        ->assertExactJson([
            'status' => 'success',
            'phone' => '254712345678',
            'amount' => 500,
            'conversation_id' => 'AG_SEND_1',
            'originator_conversation_id' => 'ORIG_1',
        ]);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/mpesa/b2c/v1/paymentrequest')
        && $request['PartyA'] === '3009678'
        && $request['PartyB'] === '254712345678'
        && $request['Amount'] === 500
        && $request['Remarks'] === 'Business Payment');
});

it('writes nothing to the ledger or transactions', function () {
    Http::fake([
        '*/oauth/v1/generate*' => Http::response(['access_token' => 'main-token']),
        '*/mpesa/b2c/v1/paymentrequest' => Http::response(['ResponseCode' => '0', 'ConversationID' => 'AG_SEND_2']),
    ]);

    sendB2C(['phone' => '+254112345678', 'amount' => 50], $this->apiKey->key)->assertCreated();

    expect(LedgerEntry::count())->toBe(0)
        ->and(Transaction::count())->toBe(0);
});

it('rejects a bad phone or amount without calling M-Pesa', function (array $body, string $field) {
    Http::fake();

    sendB2C($body, $this->apiKey->key)->assertUnprocessable()->assertJsonValidationErrors($field);

    Http::assertNothingSent();
})->with([
    'missing phone' => [['amount' => 100], 'phone'],
    'landline' => [['phone' => '0202345678', 'amount' => 100], 'phone'],
    'too short' => [['phone' => '07123', 'amount' => 100], 'phone'],
    'below minimum' => [['phone' => '0712345678', 'amount' => 9], 'amount'],
    'fractional' => [['phone' => '0712345678', 'amount' => 10.5], 'amount'],
]);

it('returns 500 when M-Pesa refuses the request', function () {
    Http::fake([
        '*/oauth/v1/generate*' => Http::response(['access_token' => 'main-token']),
        '*/mpesa/b2c/v1/paymentrequest' => Http::response(['errorCode' => '400.002.02', 'errorMessage' => 'Bad Request - Invalid PartyB'], 400),
    ]);

    sendB2C(['phone' => '0712345678', 'amount' => 100], $this->apiKey->key)
        ->assertStatus(500)
        ->assertJson(['status' => 'M-Pesa API error: Bad Request - Invalid PartyB']);
});

it('returns 503 when the shortcode credentials are not configured', function () {
    config(['mpesa.b2c.security_credential' => null]);
    Http::fake(['*/oauth/v1/generate*' => Http::response(['access_token' => 'main-token'])]);

    sendB2C(['phone' => '0712345678', 'amount' => 100], $this->apiKey->key)->assertStatus(503);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/mpesa/b2c/v1/paymentrequest'));
});

it('requires an API key', function () {
    $this->postJson('/api/v1/b2c/send', ['phone' => '0712345678', 'amount' => 100])->assertUnauthorized();
});

it('refuses a caller whose IP is not on the allowlist', function () {
    Http::fake();
    config(['mpesa.b2c.send_allowed_ips' => ['10.0.0.5']]);

    sendB2C(['phone' => '0712345678', 'amount' => 500], $this->apiKey->key)
        ->assertForbidden()
        ->assertJsonPath('code', 'ip_not_allowed');

    Http::assertNothingSent();
});

it('refuses every caller while the allowlist is empty', function () {
    Http::fake();
    config(['mpesa.b2c.send_allowed_ips' => []]);

    sendB2C(['phone' => '0712345678', 'amount' => 500], $this->apiKey->key)->assertForbidden();

    Http::assertNothingSent();
});

it('sends from an allowed IP that is not the first on the list', function () {
    Http::fake([
        '*/oauth/v1/generate*' => Http::response(['access_token' => 'main-token']),
        '*/mpesa/b2c/v1/paymentrequest' => Http::response(['ResponseCode' => '0', 'ConversationID' => 'AG_IP_2']),
    ]);
    config(['mpesa.b2c.send_allowed_ips' => ['10.0.0.5', '10.0.0.9']]);

    test()->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
        ->postJson('/api/v1/b2c/send', ['phone' => '0712345678', 'amount' => 500], apiHeaders($this->apiKey->key) + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated();
});

it('refuses a send without an Idempotency-Key', function () {
    Http::fake();

    $this->postJson('/api/v1/b2c/send', ['phone' => '0712345678', 'amount' => 500], apiHeaders($this->apiKey->key))
        ->assertStatus(400)
        ->assertJsonPath('code', 'idempotency_key_required');
    $this->postJson('/api/v1/b2c/send', ['phone' => '0712345678', 'amount' => 500], apiHeaders($this->apiKey->key))
        ->assertStatus(400);

    Http::assertNothingSent();
});

it('ignores a forged X-Forwarded-For header', function () {
    Http::fake();

    test()->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->postJson('/api/v1/b2c/send', ['phone' => '0712345678', 'amount' => 500], apiHeaders($this->apiKey->key) + [
            'Idempotency-Key' => (string) Str::uuid(),
            'X-Forwarded-For' => '127.0.0.1',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'ip_not_allowed');

    Http::assertNothingSent();
});
