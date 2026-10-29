<?php

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositResolution;
use App\Models\ExciseDutyCharge;
use App\Models\LedgerEntry;
use App\Models\Referral;
use App\Models\ReferralBonus;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config([
        'finance.test_customer_ids' => [],
        'finance.excise_duty.enabled' => true,
        'finance.excise_duty.rate' => 0.05,
        'finance.excise_duty.effective_from' => null,
        'referrals.enabled' => true,
        'referrals.effective_from' => null,
    ]);

    $this->apiKey = createApiKey('test-unmatched-deposits-key');
    $this->headers = apiHeaders($this->apiKey->key);

    $this->customer = Customer::factory()->create(['account_no' => '712345678', 'phone_no' => '254712345678', 'name' => 'Jane Payer']);
    $this->wallet = Wallet::factory()->create(['customer_id' => $this->customer->id, 'balance' => 0]);
});

/**
 * A C2B payment whose account number matches no customer, so it is left unmatched.
 */
function unmatchedDeposit(float $amount = 100, string $billRef = '0799000111', string $transId = 'UNM0000001', string $msisdn = '254712345678'): Deposit
{
    test()->postJson('/api/v1/c2b/confirm', [
        'TransID' => $transId,
        'TransactionType' => 'Pay Bill',
        'TransTime' => now()->format('YmdHis'),
        'TransAmount' => $amount,
        'BusinessShortCode' => '4007279',
        'BillRefNumber' => $billRef,
        'MSISDN' => $msisdn,
        'FirstName' => 'Jane',
    ])->assertOk()->assertExactJson(['ResultCode' => '0', 'ResultDesc' => 'Accepted']);

    $deposit = Deposit::where('trans_id', $transId)->sole();
    expect((int) $deposit->status)->toBe(Deposit::STATUS_UNMATCHED);

    return $deposit;
}

function assignDeposit(Deposit $deposit, array $payload)
{
    return test()->postJson('/api/v1/deposits/'.encryptId($deposit->id).'/assign', $payload, test()->headers);
}

function refundDeposit(Deposit $deposit, array $payload)
{
    return test()->postJson('/api/v1/deposits/'.encryptId($deposit->id).'/refund', $payload, test()->headers);
}

// listing

it('lists unmatched deposits with suggested customers', function () {
    unmatchedDeposit(100, '0799000111');
    Customer::factory()->create(['account_no' => '799000111', 'name' => 'Account Owner']);

    $data = $this->getJson('/api/v1/deposits/unmatched', $this->headers)->assertOk()->json('data');

    expect($data['summary'])->toEqual(['unmatched_count' => 1, 'unmatched_amount' => 100])
        ->and($data['items'][0]['msisdn'])->toBe('2547****5678')
        ->and(collect($data['items'][0]['suggestions'])->pluck('match', 'name')->all())
        ->toEqual(['Account Owner' => 'account_no', 'Jane Payer' => 'payer_phone']);
});

it('shows why each deposit was left unmatched', function () {
    Customer::factory()->create(['account_no' => 'KK-6AA0000000003', 'phone_no' => '254733000222']);
    unmatchedDeposit(100, '0799000111');
    unmatchedDeposit(100, '0733000222', 'UNM0000014');

    $items = $this->getJson('/api/v1/deposits/unmatched', $this->headers)->assertOk()->json('data.items');

    expect(collect($items)->pluck('unmatched_reason', 'trans_id')->all())->toBe(['UNM0000001' => 'unknown', 'UNM0000014' => 'phone_unverified']);
});

it('gives no phone suggestion when M-Pesa masks the number', function () {
    unmatchedDeposit(100, '0799000111', 'UNM0000002', '2547 ***** 678');

    $items = $this->getJson('/api/v1/deposits/unmatched', $this->headers)->assertOk()->json('data.items');

    expect($items[0]['suggestions'])->toBe([]);
});

// assign

it('credits an assigned deposit exactly like a C2B wallet deposit', function () {
    $deposit = unmatchedDeposit(100);

    assignDeposit($deposit, ['customer_id' => $this->customer->id, 'note' => 'Typo in account number'])
        ->assertOk()
        ->assertJsonPath('data.status', 2)
        ->assertJsonPath('data.resolution.action', 'assigned')
        ->assertJsonPath('data.resolution.customer_id', $this->customer->id)
        ->assertJsonPath('data.resolution.customer_name', $this->customer->name)
        ->assertJsonPath('data.resolution.resolved_by', 'api_key:'.$this->apiKey->id);

    expect((float) $this->wallet->fresh()->balance)->toBe(95.0)
        ->and(LedgerEntry::where('wallet_id', $this->wallet->id)->orderBy('id')->pluck('entry_type')->all())->toBe(['deposit', 'excise_duty'])
        ->and(ExciseDutyCharge::sole()->deposit_id)->toBe($deposit->id)
        ->and((float) Transaction::where('payment_id', $deposit->id)->sole()->amount)->toBe(95.0)
        ->and($deposit->fresh()->bill_ref_no)->toBe('0799000111');
});

it('pays the referrer the first-deposit bonus when an assigned deposit is the first one', function () {
    $referrer = Customer::factory()->create();
    Referral::create(['referrer_id' => $referrer->id, 'referred_id' => $this->customer->id, 'code_used' => 'CODE1234', 'verified_at' => now()]);

    assignDeposit(unmatchedDeposit(100), ['customer_id' => $this->customer->id, 'note' => 'Paid before registering'])->assertOk();

    expect(ReferralBonus::pluck('milestone')->sort()->values()->all())->toBe(['first_deposit', 'signup']);
});

it('credits a typed bill ref as a plain wallet deposit', function () {
    $deposit = unmatchedDeposit(50, '0799000111#200#load', 'UNM0000003');

    assignDeposit($deposit, ['customer_id' => $this->customer->id, 'note' => 'Load with wrong account'])->assertOk();

    expect((float) $this->wallet->fresh()->balance)->toBe(47.5);
});

it('assigns or refunds a deposit only once', function () {
    $deposit = unmatchedDeposit(100);

    assignDeposit($deposit, ['customer_id' => $this->customer->id, 'note' => 'first'])->assertOk();
    assignDeposit($deposit, ['customer_id' => $this->customer->id, 'note' => 'again'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'already_resolved')
        ->assertJsonMissingPath('errors');
    refundDeposit($deposit, ['mpesa_reference' => 'RVX1234567', 'note' => 'late refund'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'already_resolved')
        ->assertJsonMissingPath('errors');

    expect((float) $this->wallet->fresh()->balance)->toBe(95.0)
        ->and(DepositResolution::count())->toBe(1);
});

it('validates an assignment', function () {
    $deposit = unmatchedDeposit(100);

    assignDeposit($deposit, ['note' => 'no customer'])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    assignDeposit($deposit, ['customer_id' => $this->customer->id])->assertUnprocessable()->assertJsonValidationErrors('note');
    assignDeposit($deposit, ['customer_id' => 999999, 'note' => 'ghost'])->assertNotFound();
    $this->postJson('/api/v1/deposits/'.encryptId(999999).'/assign', ['customer_id' => $this->customer->id, 'note' => 'x y z'], $this->headers)->assertNotFound();

    expect((int) $deposit->fresh()->status)->toBe(Deposit::STATUS_UNMATCHED);
});

// refund

it('records a refund without touching any wallet', function () {
    $deposit = unmatchedDeposit(100);

    refundDeposit($deposit, ['mpesa_reference' => 'rvx1234567', 'note' => 'Reversed on the Kizuka portal'])
        ->assertOk()
        ->assertJsonPath('data.status', 4)
        ->assertJsonPath('data.resolution.action', 'refunded')
        ->assertJsonPath('data.resolution.mpesa_reference', 'RVX1234567');

    expect((float) $this->wallet->fresh()->balance)->toBe(0.0)
        ->and(LedgerEntry::count())->toBe(0);

    $other = unmatchedDeposit(30, '0799000222', 'UNM0000004');
    refundDeposit($other, ['mpesa_reference' => 'RVX1234567', 'note' => 'same reference'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'reference_used')
        ->assertJsonValidationErrors('mpesa_reference');
    refundDeposit($other, ['note' => 'no reference'])->assertUnprocessable()->assertJsonValidationErrors('mpesa_reference');

    expect((int) $other->fresh()->status)->toBe(Deposit::STATUS_UNMATCHED);
});

it('allows one refund per M-Pesa reference at the database level', function () {
    $first = unmatchedDeposit(100);
    $second = unmatchedDeposit(30, '0799000222', 'UNM0000004');

    DepositResolution::create(['deposit_id' => $first->id, 'action' => DepositResolution::ACTION_REFUNDED, 'mpesa_reference' => 'RVX1234567', 'note' => 'first']);

    expect(fn () => DepositResolution::create(['deposit_id' => $second->id, 'action' => DepositResolution::ACTION_REFUNDED, 'mpesa_reference' => 'RVX1234567', 'note' => 'second']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('shows the resolution on the deposit and lists resolved deposits', function () {
    $deposit = unmatchedDeposit(100);
    refundDeposit($deposit, ['mpesa_reference' => 'RVX7654321', 'note' => 'Reversed']);

    $this->getJson('/api/v1/deposits/'.encryptId($deposit->id), $this->headers)
        ->assertOk()
        ->assertJsonPath('data.resolution.action', 'refunded');

    $data = $this->getJson('/api/v1/deposits/unmatched?status=refunded', $this->headers)->assertOk()->json('data');
    expect($data['items'])->toHaveCount(1)
        ->and($data['items'][0]['resolution']['mpesa_reference'])->toBe('RVX7654321')
        ->and($data['items'][0]['resolution']['customer_name'])->toBeNull()
        ->and($data['summary']['unmatched_count'])->toBe(0);
});

it('names the credited customer on assigned deposits', function () {
    foreach (['UNM0000011', 'UNM0000012'] as $billRef) {
        assignDeposit(unmatchedDeposit(100, '0799000111', $billRef), ['customer_id' => $this->customer->id, 'note' => 'Wrong account'])->assertOk();
    }

    DB::enableQueryLog();
    $items = $this->getJson('/api/v1/deposits/unmatched?status=assigned', $this->headers)->assertOk()->json('data.items');
    $customerQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'from `customers`'));

    expect($items)->toHaveCount(2)
        ->and(array_column(array_column($items, 'resolution'), 'customer_name'))->toBe([$this->customer->name, $this->customer->name])
        ->and($customerQueries)->toHaveCount(1);
});

it('no longer has the broken PUT deposit route', function () {
    $this->putJson('/api/v1/deposits/'.encryptId(unmatchedDeposit()->id), ['status' => 2], $this->headers)->assertStatus(405);
});

// command

it('matches by exact account number only, and a dry run changes nothing', function () {
    $byAccount = unmatchedDeposit(100, '0799000111', 'UNM0000005');
    $byPhoneOnly = unmatchedDeposit(40, '0788000999', 'UNM0000006');
    $late = Customer::factory()->create(['account_no' => '799000111']);

    $this->artisan('deposits:match-unmatched', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run: 1 deposit(s) would be assigned')
        ->assertSuccessful();
    expect((int) $byAccount->fresh()->status)->toBe(Deposit::STATUS_UNMATCHED);

    $this->artisan('deposits:match-unmatched')->expectsOutputToContain('Assigned 1 deposit(s)')->assertSuccessful();

    expect((int) $byAccount->fresh()->status)->toBe(Deposit::STATUS_COMPLETED)
        ->and($byAccount->fresh()->resolution->resolved_by)->toBe('command:deposits:match-unmatched')
        ->and($byAccount->fresh()->resolution->customer_id)->toBe($late->id)
        ->and((int) $byPhoneOnly->fresh()->status)->toBe(Deposit::STATUS_UNMATCHED);
});

it('runs the matcher from the API, dry run by default', function () {
    $deposit = unmatchedDeposit(100, '0799000111', 'UNM0000010');
    Customer::factory()->create(['account_no' => '799000111']);

    $this->postJson('/api/v1/deposits/unmatched/match', [], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.dry_run', true)
        ->assertJsonPath('data.matched', 1)
        ->assertJsonPath('data.assigned', 0);
    expect((int) $deposit->fresh()->status)->toBe(Deposit::STATUS_UNMATCHED);

    $this->postJson('/api/v1/deposits/unmatched/match', ['dry_run' => false], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.assigned', 1)
        ->assertJsonPath('data.assigned_amount', 100);
    expect($deposit->fresh()->resolution->resolved_by)->toBe('api_key:'.$this->apiKey->id);
});

/**
 * An unmatched deposit recorded before bill refs could be player numbers or phones.
 */
function olderUnmatchedDeposit(string $billRef, string $msisdn, string $transId, float $amount = 100): Deposit
{
    return Deposit::create([
        'trans_id' => $transId, 'trans_type' => 'Pay Bill', 'trans_time' => now(), 'trans_amount' => $amount,
        'short_code' => '4007279', 'bill_ref_no' => $billRef, 'msisdn' => $msisdn, 'name' => 'Payer', 'status' => Deposit::STATUS_UNMATCHED,
    ]);
}

it('assigns an older unmatched deposit by player number', function () {
    $deposit = olderUnmatchedDeposit($this->customer->player_no, str_repeat('a', 64), 'UNM0000011');

    $this->artisan('deposits:match-unmatched')->expectsOutputToContain('Assigned 1 deposit(s)')->assertSuccessful();

    expect((int) $deposit->fresh()->status)->toBe(Deposit::STATUS_COMPLETED)
        ->and($deposit->fresh()->resolution->customer_id)->toBe($this->customer->id)
        ->and($deposit->fresh()->resolution->note)->toBe("Matched by player_no {$this->customer->player_no}");
});

it('suggests a player number match', function () {
    olderUnmatchedDeposit($this->customer->player_no, '2547 ***** 678', 'UNM0000012');

    $items = $this->getJson('/api/v1/deposits/unmatched', $this->headers)->assertOk()->json('data.items');

    expect(collect($items[0]['suggestions'])->pluck('match', 'name')->all())->toEqual(['Jane Payer' => 'player_no']);
});

it('assigns an older deposit paid from the customer\'s own verified phone', function () {
    $vera = Customer::factory()->create(['name' => 'Vera', 'account_no' => 'KK-6AA0000000002', 'phone_no' => '254733000111']);
    $vera->forceFill(['phone_no_verified_at' => now()])->save();
    $deposit = olderUnmatchedDeposit('0733000111', hash('sha256', '254733000111'), 'UNM0000013');

    $this->postJson('/api/v1/deposits/unmatched/match', ['dry_run' => false], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.assigned', 1)
        ->assertJsonPath('data.items.0.matched_by', 'phone');

    expect($deposit->fresh()->resolution->customer_id)->toBe($vera->id)
        ->and($deposit->fresh()->resolution->note)->toBe('Matched by phone 0733000111')
        ->and($deposit->fresh()->matched_by)->toBe('phone');
});

it('leaves an older phone match paid from another number for a person to assign', function () {
    $vera = Customer::factory()->create(['name' => 'Vera', 'account_no' => 'KK-6AA0000000002', 'phone_no' => '254733000111']);
    $vera->forceFill(['phone_no_verified_at' => now()])->save();
    $deposit = olderUnmatchedDeposit('0733000111', hash('sha256', '254799000000'), 'UNM0000015');

    $this->postJson('/api/v1/deposits/unmatched/match', ['dry_run' => false], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.matched', 0);

    expect((int) $deposit->fresh()->status)->toBe(Deposit::STATUS_UNMATCHED);
});

// finance

it('moves resolved deposits out of the unmatched liability and shows refunds as cash out', function () {
    $assigned = unmatchedDeposit(100, '0799000111', 'UNM0000007');
    $refunded = unmatchedDeposit(40, '0799000222', 'UNM0000008');
    unmatchedDeposit(10, '0799000333', 'UNM0000009');

    assignDeposit($assigned, ['customer_id' => $this->customer->id, 'note' => 'fixed']);
    refundDeposit($refunded, ['mpesa_reference' => 'RVX0000001', 'note' => 'reversed']);

    $sheet = $this->getJson('/api/v1/finance/balance-sheet', $this->headers)->assertOk()->json('data');
    expect($sheet['liabilities']['unmatched_deposits'])->toEqual(10);

    $cash = $this->getJson('/api/v1/finance/cash-flow', $this->headers)->assertOk()->json('data.totals');
    expect($cash['cash_in']['total'])->toEqual(150)
        ->and($cash['cash_in']['unmatched'])->toEqual(50)
        ->and($cash['cash_in']['wallet_deposit'])->toEqual(100)
        ->and($cash['deposit_refunds'])->toEqual(['refunded' => 40])
        ->and($cash['net_cash'])->toEqual(110);

    $check = collect($this->getJson('/api/v1/finance/reconciliation', $this->headers)->json('data.checks'))->firstWhere('key', 'deposit_resolutions');
    expect($check['status'])->toBe('pass');
});

it('fails reconciliation when a deposit and its resolution disagree', function () {
    $deposit = unmatchedDeposit(100);
    refundDeposit($deposit, ['mpesa_reference' => 'RVX0000002', 'note' => 'reversed']);
    Deposit::whereKey($deposit->id)->update(['status' => Deposit::STATUS_UNMATCHED]);

    $check = collect($this->getJson('/api/v1/finance/reconciliation', $this->headers)->json('data.checks'))->firstWhere('key', 'deposit_resolutions');

    expect($check)->toMatchArray(['status' => 'fail', 'count' => 1, 'amount' => 100]);
});
