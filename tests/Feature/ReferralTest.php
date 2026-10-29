<?php

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Referral;
use App\Models\ReferralBonus;
use App\Models\ReferralCode;
use App\Models\ReferralWallet;
use App\Models\Wallet;
use App\Services\FinanceDateRange;
use App\Services\FinanceReportService;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'referrals.enabled' => true,
        'referrals.effective_from' => null,
        'referrals.bonuses.signup' => 10,
        'referrals.bonuses.first_deposit' => 10,
        'finance.excise_duty.enabled' => false,
    ]);

    $this->apiKey = createApiKey('test-referrals-key');
    $this->headers = apiHeaders($this->apiKey->key);

    $this->referrer = Customer::factory()->create(['account_no' => '700000001']);
    Wallet::factory()->create(['customer_id' => $this->referrer->id, 'balance' => 0]);
    ReferralCode::factory()->create(['customer_id' => $this->referrer->id, 'code' => 'KADI2026']);
});

function signUpWithReferral(?string $code, string $accountNo = '711111111')
{
    return test()->postJson('/api/v1/customers', array_filter([
        'account_no' => $accountNo,
        'name' => 'New Player',
        'email' => $accountNo.'@example.com',
        'phone_no' => '254'.$accountNo,
        'referral_code' => $code,
    ]), test()->headers);
}

function reportReferralVerified(Customer $customer)
{
    return test()->postJson('/api/v1/customers/'.encryptId($customer->id).'/referral/verified', [], test()->headers);
}

function referralDeposit(string $accountNo, float $amount, string $transId)
{
    return test()->postJson('/api/v1/c2b/confirm', [
        'TransID' => $transId,
        'TransactionType' => 'Pay Bill',
        'TransTime' => now()->format('YmdHis'),
        'TransAmount' => $amount,
        'BusinessShortCode' => '12345',
        'BillRefNumber' => '0'.$accountNo,
        'MSISDN' => '254'.$accountNo,
        'FirstName' => 'New',
    ]);
}

function referralWalletBalance(Customer $customer): float
{
    return (float) (ReferralWallet::where('customer_id', $customer->id)->value('balance') ?? 0);
}

// codes

it('saves a customer referral code, link and qr code in upper case', function () {
    $customer = Customer::factory()->create();

    $this->putJson('/api/v1/customers/'.encryptId($customer->id).'/referral-code', [
        'code' => 'abc123',
        'link' => 'https://kadikings.co.ke/r/ABC123',
        'qr_code' => 'data:image/png;base64,iVBORw0KGgo=',
    ], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.code', 'ABC123')
        ->assertJsonPath('data.link', 'https://kadikings.co.ke/r/ABC123')
        ->assertJsonPath('data.qr_code', 'data:image/png;base64,iVBORw0KGgo=');

    $this->getJson('/api/v1/customers/'.encryptId($customer->id).'/referral-code', $this->headers)
        ->assertOk()
        ->assertJsonPath('data.code', 'ABC123');
});

it('rejects a code owned by another customer', function () {
    $customer = Customer::factory()->create();

    $this->putJson('/api/v1/customers/'.encryptId($customer->id).'/referral-code', ['code' => 'kadi2026'], $this->headers)
        ->assertStatus(409);
});

it('validates the code format', function (string $code) {
    $this->putJson('/api/v1/customers/'.encryptId($this->referrer->id).'/referral-code', ['code' => $code], $this->headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
})->with(['abc', 'has space', 'waytoolongcodeover20chars', 'dash-code']);

it('keeps earlier referrals when the code changes and stops matching the old code', function () {
    signUpWithReferral('KADI2026', '711111111')->assertCreated();

    $this->putJson('/api/v1/customers/'.encryptId($this->referrer->id).'/referral-code', ['code' => 'NEWCODE1'], $this->headers)
        ->assertOk();

    signUpWithReferral('KADI2026', '722222222')->assertCreated();
    signUpWithReferral('newcode1', '733333333')->assertCreated();

    expect(Referral::where('referrer_id', $this->referrer->id)->pluck('code_used')->all())->toBe(['KADI2026', 'NEWCODE1'])
        ->and(ReferralCode::where('customer_id', $this->referrer->id)->count())->toBe(1);
});

it('looks up who owns a code', function () {
    $this->getJson('/api/v1/referrals/lookup?code=kadi2026', $this->headers)
        ->assertOk()
        ->assertJsonPath('data.customer_id', $this->referrer->id)
        ->assertJsonPath('data.name', $this->referrer->name);

    $this->getJson('/api/v1/referrals/lookup?code=NOPE1234', $this->headers)->assertNotFound();
});

// attribution

it('creates a referral when a customer signs up with another customer code', function () {
    $response = signUpWithReferral('kadi2026')->assertCreated();

    $referred = Customer::find($response->json('customer_id'));

    expect($referred->referral_code)->toBe('kadi2026')
        ->and($referred->referredBy->referrer_id)->toBe($this->referrer->id)
        ->and($referred->referredBy->code_used)->toBe('KADI2026');
});

it('stores agent codes that belong to no customer without creating a referral', function () {
    $response = signUpWithReferral('AGENT007')->assertCreated();

    expect(Customer::find($response->json('customer_id'))->referral_code)->toBe('AGENT007')
        ->and(Referral::count())->toBe(0);
});

it('ignores signups without a code, before effective_from, or while switched off', function () {
    signUpWithReferral(null, '711111111')->assertCreated();

    config(['referrals.effective_from' => now()->addDay()->toDateString()]);
    signUpWithReferral('KADI2026', '722222222')->assertCreated();

    config(['referrals.effective_from' => null, 'referrals.enabled' => false]);
    signUpWithReferral('KADI2026', '733333333')->assertCreated();

    expect(Referral::count())->toBe(0);
});

it('never lets a customer refer themselves', function () {
    $customer = Customer::factory()->create();
    ReferralCode::factory()->create(['customer_id' => $customer->id, 'code' => 'SELF1234']);

    expect(app(ReferralService::class)->attachAtSignup($customer, 'SELF1234'))->toBeNull();
});

// bonuses

it('pays the signup bonus when the client reports the referral verified', function () {
    $referred = Customer::find(signUpWithReferral('KADI2026')->json('customer_id'));

    expect(referralWalletBalance($this->referrer))->toBe(0.0);

    reportReferralVerified($referred)
        ->assertOk()
        ->assertJsonPath('referred', true)
        ->assertJsonPath('data.status', 'verified')
        ->assertJsonPath('data.earned', 10);

    expect(referralWalletBalance($this->referrer))->toBe(10.0);

    $bonus = ReferralBonus::sole();
    $entry = LedgerEntry::find($bonus->ledger_entry_id);

    expect($bonus->milestone)->toBe('signup')
        ->and($bonus->customer_id)->toBe($this->referrer->id)
        ->and($entry->entry_type)->toBe('referral_bonus')
        ->and($entry->wallet_type)->toBe(LedgerEntry::WALLET_TYPE_REFERRAL)
        ->and($entry->customer_id)->toBe($this->referrer->id)
        ->and((float) $entry->credit)->toBe(10.0)
        ->and((float) $entry->balance_after)->toBe(10.0)
        ->and($entry->metadata)->toMatchArray(['milestone' => 'signup', 'referred_id' => $referred->id]);
});

it('pays each milestone once however often verified is reported', function () {
    $referred = Customer::find(signUpWithReferral('KADI2026')->json('customer_id'));

    reportReferralVerified($referred)->assertOk();
    reportReferralVerified($referred)->assertOk();

    expect(ReferralBonus::count())->toBe(1)
        ->and(referralWalletBalance($this->referrer))->toBe(10.0);
});

it('pays the first deposit bonus straight away for a verified referral', function () {
    $referred = Customer::find(signUpWithReferral('KADI2026', '711111111')->json('customer_id'));
    reportReferralVerified($referred);

    referralDeposit('711111111', 50, 'REF0000001')->assertStatus(201);
    referralDeposit('711111111', 500, 'REF0000002')->assertStatus(201);

    expect(ReferralBonus::orderBy('id')->pluck('milestone')->all())->toBe(['signup', 'first_deposit'])
        ->and(referralWalletBalance($this->referrer))->toBe(20.0)
        ->and($referred->referredBy->fresh()->firstDeposit->trans_id)->toBe('REF0000001');
});

it('holds the first deposit bonus until the referral is verified', function () {
    $referred = Customer::find(signUpWithReferral('KADI2026', '711111111')->json('customer_id'));

    referralDeposit('711111111', 1, 'REF0000001')->assertStatus(201);

    expect(ReferralBonus::count())->toBe(0);

    reportReferralVerified($referred)->assertOk()->assertJsonPath('data.status', 'deposited')->assertJsonPath('data.earned', 20);

    expect(ReferralBonus::orderBy('id')->pluck('milestone')->all())->toBe(['signup', 'first_deposit'])
        ->and(referralWalletBalance($this->referrer))->toBe(20.0);
});

it('counts deposits posted to /deposits as well', function () {
    $referred = Customer::find(signUpWithReferral('KADI2026', '711111111')->json('customer_id'));
    reportReferralVerified($referred);

    $this->postJson('/api/v1/deposits', [
        'TransID' => 'REFDEP0001',
        'TransactionType' => 'Pay Bill',
        'TransTime' => now()->toDateTimeString(),
        'TransAmount' => 20,
        'BusinessShortCode' => '12345',
        'BillRefNumber' => '711111111',
        'MSISDN' => '254711111111',
        'FirstName' => 'New',
    ], $this->headers)->assertCreated();

    expect(ReferralBonus::where('milestone', 'first_deposit')->count())->toBe(1);
});

it('does not treat a gift purchase as a first deposit', function () {
    $referred = Customer::find(signUpWithReferral('KADI2026', '711111111')->json('customer_id'));
    reportReferralVerified($referred);

    referralDeposit('711111111#100#gift', 10, 'REF0000001')->assertStatus(201);

    expect(ReferralBonus::where('milestone', 'first_deposit')->count())->toBe(0)
        ->and($referred->referredBy->fresh()->first_deposit_id)->toBeNull();
});

it('treats a #load paybill payment as an ordinary first deposit', function () {
    $referred = Customer::find(signUpWithReferral('KADI2026', '711111111')->json('customer_id'));
    reportReferralVerified($referred);

    referralDeposit('711111111#100#load', 100, 'REF0000001')->assertStatus(201);

    expect($referred->referredBy->fresh()->first_deposit_id)->not->toBeNull();
});

it('reports verified for a customer who was not referred without paying anything', function () {
    $customer = Customer::factory()->create();

    reportReferralVerified($customer)->assertOk()->assertJsonPath('referred', false)->assertJsonPath('data', null);

    expect(ReferralBonus::count())->toBe(0);
});

it('pays nothing while referrals are switched off', function () {
    $referred = Customer::find(signUpWithReferral('KADI2026')->json('customer_id'));
    config(['referrals.enabled' => false]);

    reportReferralVerified($referred)->assertOk();

    expect(ReferralBonus::count())->toBe(0)
        ->and($referred->referredBy->fresh()->verified_at)->not->toBeNull();
});

// listing and stats

it('lists a customer referrals with masked phones and what each earned', function () {
    $verified = Customer::find(signUpWithReferral('KADI2026', '711111111')->json('customer_id'));
    signUpWithReferral('KADI2026', '722222222');
    reportReferralVerified($verified);

    $this->getJson('/api/v1/customers/'.encryptId($this->referrer->id).'/referrals', $this->headers)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.referred_phone', '2547****2222')
        ->assertJsonPath('data.0.status', 'pending_verification')
        ->assertJsonPath('data.0.earned', 0)
        ->assertJsonPath('data.1.referred_name', 'New Player')
        ->assertJsonPath('data.1.status', 'verified')
        ->assertJsonPath('data.1.earned', 10)
        ->assertJsonPath('data.1.bonuses.0.milestone', 'signup');

    $this->getJson('/api/v1/customers/'.encryptId($this->referrer->id).'/referrals?status=verified', $this->headers)
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('returns a referrer stats and referral wallet', function () {
    $first = Customer::find(signUpWithReferral('KADI2026', '711111111')->json('customer_id'));
    signUpWithReferral('KADI2026', '722222222');
    reportReferralVerified($first);
    referralDeposit('711111111', 10, 'REF0000001');

    $this->getJson('/api/v1/customers/'.encryptId($this->referrer->id).'/referrals/stats', $this->headers)
        ->assertOk()
        ->assertJsonPath('data.code', 'KADI2026')
        ->assertJsonPath('data.referrals.total', 2)
        ->assertJsonPath('data.referrals.verified', 1)
        ->assertJsonPath('data.referrals.deposited', 1)
        ->assertJsonPath('data.referrals.pending_verification', 1)
        ->assertJsonPath('data.earned.total', 20)
        ->assertJsonPath('data.earned.signup', 10)
        ->assertJsonPath('data.earned.first_deposit', 10)
        ->assertJsonPath('data.wallet_balance', 20);

    $this->getJson('/api/v1/customers/'.encryptId($this->referrer->id).'/referral-wallet', $this->headers)
        ->assertOk()
        ->assertJsonPath('data.balance', 20)
        ->assertJsonPath('data.total_earned', 20)
        ->assertJsonPath('data.minimum_withdrawal', 50)
        ->assertJsonPath('data.bonuses.0.milestone', 'first_deposit')
        ->assertJsonPath('data.bonuses.1.milestone', 'signup')
        ->assertJsonPath('pagination.total', 2);
});

it('returns programme stats and the full referral list', function () {
    $referred = Customer::find(signUpWithReferral('KADI2026')->json('customer_id'));
    reportReferralVerified($referred);

    $this->getJson('/api/v1/stats/referrals', $this->headers)
        ->assertOk()
        ->assertJsonPath('data.referrals.total', 1)
        ->assertJsonPath('data.bonuses.total', 10)
        ->assertJsonPath('data.unspent_balance', 10)
        ->assertJsonPath('data.referrers', 1)
        ->assertJsonPath('data.top_referrers.0.customer_id', $this->referrer->id)
        ->assertJsonPath('data.top_referrers.0.earned', 10);

    $this->getJson('/api/v1/referrals?referrer_id='.$this->referrer->id, $this->headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 1);
});

it('returns 404 for unknown customers', function () {
    $this->getJson('/api/v1/customers/'.encryptId(99999).'/referrals/stats', $this->headers)->assertNotFound();
    $this->getJson('/api/v1/customers/'.encryptId($this->referrer->id + 1000).'/referral-code', $this->headers)->assertNotFound();
});

// finance

it('keeps referral bonuses out of the trial balance paired check', function () {
    $referred = Customer::find(signUpWithReferral('KADI2026')->json('customer_id'));
    reportReferralVerified($referred);

    $trialBalance = app(FinanceReportService::class)->trialBalance(FinanceDateRange::fromArray(['from' => now()->toDateString(), 'to' => now()->toDateString()]));
    $line = collect($trialBalance['lines'])->firstWhere('entry_type', 'referral_bonus');

    expect($trialBalance['check']['balanced'])->toBeTrue()
        ->and($line['account'])->toBe('referral_wallets')
        ->and($line['category'])->toBe('referral_bonus')
        ->and($line['credit'])->toEqual(10);
});

it('filters the referral list by referred customer', function () {
    $referred = Customer::find(signUpWithReferral('KADI2026', '711111111')->json('customer_id'));
    signUpWithReferral('KADI2026', '722222222');

    $this->getJson('/api/v1/referrals?referred_id='.$referred->id, $this->headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.referrer_id', $this->referrer->id);
});
