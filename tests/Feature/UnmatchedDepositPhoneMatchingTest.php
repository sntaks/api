<?php

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\Wallet;
use App\Services\WalletDepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/** Safaricom's msisdn for 0705171214: sha256("254705171214"), from a real production deposit. */
const DORIS_MSISDN_HASH = '60da0bd9f1f1f556e423fcebb106409ba5b3c6dcd7ee91beafb4d970b03b936e';

beforeEach(function () {
    Cache::flush();
    config([
        'finance.test_customer_ids' => [],
        'finance.excise_duty.enabled' => false,
        'referrals.enabled' => false,
    ]);

    $this->apiKey = createApiKey('test-phone-matching-key');
    $this->headers = apiHeaders($this->apiKey->key);

    $this->doris = Customer::factory()->create(['name' => 'Doris', 'account_no' => 'KK-6AA8B1DAAF392', 'phone_no' => '+254705171214']);
    $this->dorisWallet = Wallet::factory()->create(['customer_id' => $this->doris->id, 'balance' => 0]);
});

function c2bPayment(string $billRef, string $msisdn, string $transId, float $amount = 150)
{
    return test()->postJson('/api/v1/c2b/confirm', [
        'TransID' => $transId,
        'TransactionType' => 'Pay Bill',
        'TransTime' => '20260924131832',
        'TransAmount' => $amount,
        'BusinessShortCode' => '4007279',
        'BillRefNumber' => $billRef,
        'MSISDN' => $msisdn,
        'FirstName' => 'doris',
    ]);
}

function unmatchedSuggestions(): array
{
    return test()->getJson('/api/v1/deposits/unmatched', test()->headers)->assertOk()->json('data.items.0.suggestions');
}

// phone fingerprint

it('hashes every stored phone format the way Safaricom does', function (?string $phone, ?string $expected) {
    expect(Customer::phoneHash($phone))->toBe($expected === null ? null : hash('sha256', $expected));
})->with([
    ['+254705171214', '254705171214'],
    ['254705171214', '254705171214'],
    ['0705171214', '254705171214'],
    ['705171214', '254705171214'],
    [' 0705 171 214 ', '254705171214'],
    ['0110123456', '254110123456'],
    ['+254110123456', '254110123456'],
    ['12345', null],
    ['0205171214', null],
    [null, null],
]);

it('matches the real production msisdn hash', function () {
    expect(Customer::phoneHash('+254705171214'))->toBe(DORIS_MSISDN_HASH)
        ->and($this->doris->fresh()->phone_hash)->toBe(DORIS_MSISDN_HASH);
});

it('keeps phone_hash in step with phone_no', function () {
    $this->doris->update(['phone_no' => '0110123456']);
    expect($this->doris->fresh()->phone_hash)->toBe(hash('sha256', '254110123456'));

    $this->doris->update(['phone_no' => null]);
    expect($this->doris->fresh()->phone_hash)->toBeNull();

    $this->doris->update(['name' => 'Doris W']);
    expect($this->doris->fresh()->phone_hash)->toBeNull();
});

// Doris: paid the paybill with her phone number as the account

it('leaves a phone-number bill ref unmatched at C2B and suggests the customer twice over', function () {
    c2bPayment('0705171214', DORIS_MSISDN_HASH, 'UIO2E8BUH5')->assertOk();

    expect((int) Deposit::where('trans_id', 'UIO2E8BUH5')->sole()->status)->toBe(Deposit::STATUS_UNMATCHED)
        ->and((float) $this->dorisWallet->fresh()->balance)->toBe(0.0);

    expect(unmatchedSuggestions())->toBe([[
        'customer_id' => $this->doris->id,
        'name' => 'Doris',
        'account_no' => 'KK-6AA8B1DAAF392',
        'phone_no' => '+2547****1214',
        'match' => 'payer_phone',
        'matches' => ['payer_phone', 'bill_ref_phone'],
        'ambiguous' => false,
    ]]);
});

it('never assigns a phone match automatically', function () {
    c2bPayment('0705171214', DORIS_MSISDN_HASH, 'UIO2E8BUH5');

    $this->artisan('deposits:match-unmatched', ['--dry-run' => true])
        ->expectsOutputToContain('No unmatched deposit matches a customer account number.')
        ->assertSuccessful();

    $this->postJson('/api/v1/deposits/unmatched/match', ['dry_run' => false], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.assigned', 0);

    expect((int) Deposit::where('trans_id', 'UIO2E8BUH5')->sole()->status)->toBe(Deposit::STATUS_UNMATCHED);
});

it('credits Doris in one assign from the suggestion', function () {
    c2bPayment('0705171214', DORIS_MSISDN_HASH, 'UIO2E8BUH5');
    $deposit = Deposit::where('trans_id', 'UIO2E8BUH5')->sole();

    $this->postJson('/api/v1/deposits/'.encryptId($deposit->id).'/assign', ['customer_id' => $this->doris->id, 'note' => 'Paid with her phone number as account'], $this->headers)
        ->assertOk();

    expect((float) $this->dorisWallet->fresh()->balance)->toBe(150.0);
});

it('flags a phone number shared by two customers as ambiguous', function () {
    $duplicate = Customer::factory()->create(['name' => 'Doris again', 'account_no' => 'KK-6AA0000000001', 'phone_no' => '254705171214']);

    c2bPayment('0705171214', DORIS_MSISDN_HASH, 'UIO2E8BUH6');

    $suggestions = collect(unmatchedSuggestions());

    expect($suggestions->pluck('customer_id')->sort()->values()->all())->toBe(collect([$this->doris->id, $duplicate->id])->sort()->values()->all())
        ->and($suggestions->pluck('ambiguous')->unique()->all())->toBe([true]);
});

it('ranks an account number match above phone matches', function () {
    $other = Customer::factory()->create(['name' => 'Account Holder', 'account_no' => 'KK-6AA1111111111', 'phone_no' => '254711111111']);

    // Doris paid from her phone but typed someone else's account number with a slip.
    c2bPayment('kk-6aa1111111111x', DORIS_MSISDN_HASH, 'UIO2E8BUH7');
    Deposit::where('trans_id', 'UIO2E8BUH7')->update(['bill_ref_no' => 'KK6AA111111111']);

    expect(collect(unmatchedSuggestions())->pluck('match')->all())->toBe(['payer_phone']);

    Deposit::where('trans_id', 'UIO2E8BUH7')->update(['bill_ref_no' => 'kk6aa1111111111']);

    expect(collect(unmatchedSuggestions())->pluck('match', 'name')->all())->toBe(['Account Holder' => 'account_no', 'Doris' => 'payer_phone']);
});

// KK- account number typos

it('credits live C2B payments whose KK account number has typing slips', function (string $billRef) {
    c2bPayment($billRef, DORIS_MSISDN_HASH, 'KKTYPO'.md5($billRef))->assertStatus(201);

    expect((float) $this->dorisWallet->fresh()->balance)->toBe(150.0);
})->with([
    'exact' => 'KK-6AA8B1DAAF392',
    'lower case' => 'kk-6aa8b1daaf392',
    'no dash' => 'KK6AA8B1DAAF392',
    'spaces' => ' KK 6AA8 B1DA AF392 ',
]);

it('assigns an older unmatched deposit whose KK account number had a slip', function () {
    $deposit = Deposit::create([
        'trans_id' => 'OLDTYPO001', 'trans_type' => 'Pay Bill', 'trans_time' => '2026-09-20 10:00:00', 'trans_amount' => 80,
        'short_code' => '4007279', 'bill_ref_no' => 'kk6aa8b1daaf392', 'msisdn' => str_repeat('a', 64), 'name' => 'doris', 'status' => 0,
    ]);

    $this->artisan('deposits:match-unmatched')->expectsOutputToContain('Assigned 1 deposit(s)')->assertSuccessful();

    expect((int) $deposit->fresh()->status)->toBe(Deposit::STATUS_COMPLETED)
        ->and((float) $this->dorisWallet->fresh()->balance)->toBe(80.0);
});

it('leaves every other account value exactly as before', function (string $raw, string $expected) {
    expect(app(WalletDepositService::class)->normalizeAccountNo($raw))->toBe($expected);
})->with([
    ['254712345678', '712345678'],
    ['0712345678', '712345678'],
    ['712345678', '712345678'],
    ['ABC 123', 'ABC 123'],
    ['KK-6AA8B1DAAF39', 'KK-6AA8B1DAAF39'],
    ['KK-6AA8B1DAAF3922', 'KK-6AA8B1DAAF3922'],
    ['', ''],
]);

it('still routes a gift bill ref with a KK slip to the gift purchase, not the wallet', function () {
    c2bPayment('kk6aa8b1daaf392#50#gift', DORIS_MSISDN_HASH, 'KKGIFT0001', 50)->assertStatus(201);

    expect($this->doris->purchases()->sole()->purchase_type)->toBe('gift')
        ->and((float) $this->dorisWallet->fresh()->balance)->toBe(0.0);
});

// finance

it('labels refunded deposits as refunded in the finance deposits report', function () {
    c2bPayment('0705171214', DORIS_MSISDN_HASH, 'UIO2E8BUH8');
    $deposit = Deposit::where('trans_id', 'UIO2E8BUH8')->sole();
    $this->postJson('/api/v1/deposits/'.encryptId($deposit->id).'/refund', ['mpesa_reference' => 'RVX9990001', 'note' => 'reversed'], $this->headers)->assertOk();

    $items = $this->getJson('/api/v1/finance/deposits?from=2026-09-01', $this->headers)->assertOk()->json('data.items');

    expect(collect($items)->firstWhere('trans_id', 'UIO2E8BUH8')['status'])->toBe('refunded');
});

it('never exposes phone_hash in serialised customers', function () {
    expect($this->doris->fresh()->toArray())->not->toHaveKey('phone_hash')
        ->and($this->getJson('/api/v1/customers/'.encryptId($this->doris->id), $this->headers)->assertOk()->json('data'))->not->toHaveKey('phone_hash');
});
