<?php

use App\Models\Customer;
use App\Services\DepositAccountMatch;
use App\Services\DepositAccountResolver;
use App\Util\PlayerNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ann = verifiedCustomer(['name' => 'Ann', 'account_no' => 'KK-6AA8B1DAAF392', 'phone_no' => '254712345678']);
    $this->legacy = Customer::factory()->create(['name' => 'Legacy', 'account_no' => '722000111', 'phone_no' => '254700000001']);
    $this->ben = Customer::factory()->create(['name' => 'Ben', 'phone_no' => '254733000222']);
    $this->annPays = hash('sha256', '254712345678');
});

function verifiedCustomer(array $attributes): Customer
{
    $customer = Customer::factory()->create($attributes);
    $customer->forceFill(['phone_no_verified_at' => now()])->save();

    return $customer;
}

function resolveBillRef(?string $billRef, ?string $msisdn): DepositAccountMatch
{
    return app(DepositAccountResolver::class)->resolve($billRef, $msisdn);
}

it('resolves an account number with typing slips', function () {
    $match = resolveBillRef(' kk6aa8b1daaf392 ', $this->annPays);

    expect($match->customer?->id)->toBe($this->ann->id)
        ->and($match->matchedBy)->toBe(DepositAccountMatch::MATCHED_BY_ACCOUNT_NO)
        ->and($match->unmatchedReason)->toBeNull();
});

it('resolves a player number typed with a space', function () {
    $match = resolveBillRef(PlayerNumber::format($this->ann->player_no), null);

    expect($match->customer?->id)->toBe($this->ann->id)
        ->and($match->matchedBy)->toBe(DepositAccountMatch::MATCHED_BY_PLAYER_NO);
});

it('refuses a player number with a wrong check digit', function () {
    $number = $this->ann->player_no;
    $typo = substr($number, 0, 7).((((int) substr($number, 7)) + 1) % 10);

    $match = resolveBillRef($typo, $this->annPays);

    expect($match->customer)->toBeNull()
        ->and($match->matchedBy)->toBeNull()
        ->and($match->unmatchedReason)->toBe(DepositAccountMatch::REASON_UNKNOWN);
});

it('resolves a verified phone in every format', function (string $billRef) {
    $match = resolveBillRef($billRef, $this->annPays);

    expect($match->customer?->id)->toBe($this->ann->id)
        ->and($match->matchedBy)->toBe(DepositAccountMatch::MATCHED_BY_PHONE);
})->with(['0712345678', '712345678', '254712345678', '+254 712 345 678', '0712345678#ref']);

it('resolves an 01 number', function () {
    $customer = verifiedCustomer(['phone_no' => '254110123456']);

    $match = resolveBillRef('0110123456', hash('sha256', '254110123456'));

    expect($match->customer?->id)->toBe($customer->id)
        ->and($match->matchedBy)->toBe(DepositAccountMatch::MATCHED_BY_PHONE);
});

it('prefers a legacy account number over a phone reading', function () {
    verifiedCustomer(['phone_no' => '254722000111']);

    $match = resolveBillRef('0722000111', hash('sha256', '254722000111'));

    expect($match->customer?->id)->toBe($this->legacy->id)
        ->and($match->matchedBy)->toBe(DepositAccountMatch::MATCHED_BY_ACCOUNT_NO);
});

it('refuses an unverified phone', function () {
    $match = resolveBillRef('0733000222', hash('sha256', '254733000222'));

    expect($match->customer)->toBeNull()
        ->and($match->unmatchedReason)->toBe(DepositAccountMatch::REASON_PHONE_UNVERIFIED);
});

it('refuses a phone shared by two customers', function () {
    verifiedCustomer(['phone_no' => '0744000333']);
    verifiedCustomer(['phone_no' => '254744000333']);

    $match = resolveBillRef('0744000333', hash('sha256', '254744000333'));

    expect($match->customer)->toBeNull()
        ->and($match->unmatchedReason)->toBe(DepositAccountMatch::REASON_PHONE_AMBIGUOUS);
});

it('credits a verified phone paid from another number and marks it', function () {
    $match = resolveBillRef('0712345678', hash('sha256', '254799999999'));

    expect($match->customer?->id)->toBe($this->ann->id)
        ->and($match->matchedBy)->toBe(DepositAccountMatch::MATCHED_BY_PHONE_OTHER_PAYER);
});

it('marks a phone match whose msisdn is masked', function () {
    $match = resolveBillRef('0712345678', '2547****5678');

    expect($match->customer?->id)->toBe($this->ann->id)
        ->and($match->matchedBy)->toBe(DepositAccountMatch::MATCHED_BY_PHONE_OTHER_PAYER);
});

it('leaves an unknown or empty bill ref unmatched', function (?string $billRef) {
    $match = resolveBillRef($billRef, $this->annPays);

    expect($match->customer)->toBeNull()
        ->and($match->unmatchedReason)->toBe(DepositAccountMatch::REASON_UNKNOWN);
})->with(['UNKNOWN', '', null, 'SHOP-1001']);

it('never reads a reference with letters as a phone', function (string $billRef) {
    $match = resolveBillRef($billRef, $this->annPays);

    expect($match->customer)->toBeNull()
        ->and($match->unmatchedReason)->toBe(DepositAccountMatch::REASON_UNKNOWN);
})->with(['SHOP-0712345678', 'KK-7A1B2C3D4E5F6G7H8', 'Mary 0712345678']);

it('refuses a player number that is another customer\'s national ID', function () {
    Customer::factory()->create(['name' => 'Id Holder', 'id_no' => $this->ann->player_no]);

    $match = resolveBillRef($this->ann->player_no, null);

    expect($match->customer)->toBeNull()
        ->and($match->unmatchedReason)->toBe(DepositAccountMatch::REASON_UNKNOWN);
});

it('still resolves a player number that is the same customer\'s national ID', function () {
    $this->ann->update(['id_no' => $this->ann->player_no]);

    expect(resolveBillRef($this->ann->player_no, null)->customer?->id)->toBe($this->ann->id);
});
