<?php

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use App\Models\Wallet;
use App\Services\MpesaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    createApiKey();
    $this->customer = Customer::factory()->create(['account_no' => 'KK-6AA8B1DAAF392']);
});

function payLoadBillRef(float $amount, string $billRef, string $transId = 'LOAD000001')
{
    return test()->postJson('/api/v1/c2b/confirm', [
        'TransID' => $transId,
        'TransactionType' => 'Pay Bill',
        'TransTime' => now()->format('YmdHis'),
        'TransAmount' => $amount,
        'BusinessShortCode' => '12345',
        'BillRefNumber' => $billRef,
        'MSISDN' => '254712345678',
        'FirstName' => 'Jane',
    ]);
}

it('credits the amount paid, not the value in the bill reference', function () {
    payLoadBillRef(10, 'KK-6AA8B1DAAF392#100000#load')->assertStatus(201);

    $wallet = Wallet::where('customer_id', $this->customer->id)->sole();

    expect((float) $wallet->balance)->toBe(10.0)
        ->and(LedgerEntry::where('entry_type', 'deposit')->pluck('credit')->map(fn ($credit) => (float) $credit)->all())->toBe([10.0])
        ->and(Purchase::where('purchase_type', 'load')->count())->toBe(0);
});

it('refuses an STK load of type load', function () {
    $this->postJson('/api/v1/load/'.encryptId($this->customer->id), [
        'amount' => 10,
        'type' => 'load',
        'coin_value' => 100000,
    ], apiHeaders())->assertStatus(422)->assertJsonValidationErrors('type');
});

it('refuses an STK gift with no positive amount', function (mixed $amount) {
    $this->postJson('/api/v1/load/'.encryptId($this->customer->id), [
        'amount' => $amount,
        'type' => 'gift',
    ], apiHeaders())->assertStatus(422)->assertJsonValidationErrors('amount');
})->with([0, -5]);

it('still starts a gift purchase', function () {
    $this->mock(MpesaService::class)
        ->shouldReceive('stkPush')
        ->once()
        ->withArgs(fn (array $params) => str_starts_with($params['AccountReference'], 'KK-6AA8B1DAAF392#50#gift'))
        ->andReturn(['ResponseCode' => '0']);

    $this->postJson('/api/v1/load/'.encryptId($this->customer->id), [
        'amount' => 50,
        'type' => 'gift',
    ], apiHeaders())->assertCreated();
});
