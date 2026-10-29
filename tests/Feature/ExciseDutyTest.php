<?php

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\ExciseDutyCharge;
use App\Models\ExciseDutyRemittance;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\ExciseDutyService;
use App\Services\WalletWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'finance.excise_duty.enabled' => true,
        'finance.excise_duty.rate' => 0.05,
        'finance.excise_duty.effective_from' => null,
    ]);

    $this->customer = Customer::factory()->create(['account_no' => '712345678']);
});

function confirmMpesaDeposit(float $amount, string $billRef = '0712345678', string $transId = 'EXC0000001', ?string $transTime = null)
{
    return test()->postJson('/api/v1/c2b/confirm', [
        'TransID' => $transId,
        'TransactionType' => 'Pay Bill',
        'TransTime' => $transTime ?? now()->format('YmdHis'),
        'TransAmount' => $amount,
        'BusinessShortCode' => '12345',
        'BillRefNumber' => $billRef,
        'MSISDN' => '254712345678',
        'FirstName' => 'Jane',
    ]);
}

function customerWallet(Customer $customer): ?Wallet
{
    return Wallet::where('customer_id', $customer->id)->first();
}

// charging

it('credits the wallet with the deposit less 5% excise duty', function () {
    confirmMpesaDeposit(100)->assertStatus(201);

    $deposit = Deposit::where('trans_id', 'EXC0000001')->first();
    $wallet = customerWallet($this->customer);

    expect((float) $wallet->balance)->toBe(95.0)
        ->and((int) $deposit->status)->toBe(2);

    $entries = LedgerEntry::where('wallet_id', $wallet->id)->orderBy('id')->get();
    expect($entries->pluck('entry_type')->all())->toBe(['deposit', 'excise_duty'])
        ->and((float) $entries[0]->credit)->toBe(100.0)
        ->and((float) $entries[1]->debit)->toBe(5.0)
        ->and((float) $entries[1]->balance_after)->toBe(95.0)
        ->and($entries[1]->referenceable_id)->toBe($deposit->id)
        ->and($entries[1]->metadata)->toMatchArray(['deposit_id' => $deposit->id, 'rate' => 0.05, 'gross_amount' => 100]);

    $charge = ExciseDutyCharge::sole();
    expect($charge->deposit_id)->toBe($deposit->id)
        ->and($charge->customer_id)->toBe($this->customer->id)
        ->and($charge->ledger_entry_id)->toBe($entries[1]->id)
        ->and($charge->gross_amount)->toBe('100.00')
        ->and($charge->rate)->toBe('0.0500')
        ->and($charge->excise_amount)->toBe('5.00')
        ->and($charge->net_amount)->toBe('95.00')
        ->and($charge->status)->toBe(ExciseDutyCharge::STATUS_CHARGED)
        ->and($charge->remittance_id)->toBeNull();

    $transaction = Transaction::where('payment_id', $deposit->id)->sole();
    expect((float) $transaction->amount)->toBe(95.0)
        ->and((float) $transaction->balance_before)->toBe(0.0)
        ->and((float) $transaction->balance_after)->toBe(95.0);
});

it('does not charge a replayed callback twice', function () {
    confirmMpesaDeposit(100)->assertStatus(201);
    confirmMpesaDeposit(100)->assertStatus(200)->assertJson(['ResultDesc' => 'Already processed']);

    expect(ExciseDutyCharge::count())->toBe(1)
        ->and((float) customerWallet($this->customer)->balance)->toBe(95.0);
});

it('credits the full deposit when excise duty is switched off', function () {
    config(['finance.excise_duty.enabled' => false]);

    confirmMpesaDeposit(100)->assertStatus(201);

    expect((float) customerWallet($this->customer)->balance)->toBe(100.0)
        ->and(ExciseDutyCharge::count())->toBe(0)
        ->and(LedgerEntry::where('entry_type', 'excise_duty')->count())->toBe(0);
});

it('only charges deposits made on or after the effective date', function () {
    config(['finance.excise_duty.effective_from' => now()->toDateString()]);

    confirmMpesaDeposit(100, transId: 'EXCBEFORE1', transTime: now()->subDay()->format('YmdHis'))->assertStatus(201);
    expect((float) customerWallet($this->customer)->balance)->toBe(100.0);

    confirmMpesaDeposit(100, transId: 'EXCONDAY01', transTime: now()->startOfDay()->format('YmdHis'))->assertStatus(201);
    expect((float) customerWallet($this->customer)->fresh()->balance)->toBe(195.0)
        ->and(ExciseDutyCharge::count())->toBe(1);
});

it('does not charge gifts or emoji', function (string $billRef) {
    confirmMpesaDeposit(100, $billRef)->assertStatus(201);

    expect(ExciseDutyCharge::count())->toBe(0)
        ->and(LedgerEntry::where('entry_type', 'excise_duty')->count())->toBe(0);
})->with([
    'gift' => ['712345678#100#gift'],
    'emoji' => ['712345678#100#emoji'],
]);

it('charges a #load paybill payment as an ordinary deposit of the amount paid', function () {
    confirmMpesaDeposit(100, '712345678#100000#load')->assertStatus(201);

    expect((float) customerWallet($this->customer)->balance)->toBe(95.0)
        ->and(ExciseDutyCharge::count())->toBe(1);
});

it('does not charge a deposit with no matching customer', function () {
    confirmMpesaDeposit(100, 'UNKNOWN')->assertOk();

    expect(ExciseDutyCharge::count())->toBe(0)
        ->and((int) Deposit::sole()->status)->toBe(0);
});

it('rolls the deposit back when the excise duty cannot be recorded', function () {
    $this->mock(ExciseDutyService::class)
        ->shouldReceive('chargeIfApplicable')
        ->andThrow(new RuntimeException('excise insert failed'));

    confirmMpesaDeposit(100)->assertStatus(500);

    expect(Deposit::count())->toBe(0)
        ->and(LedgerEntry::count())->toBe(0)
        ->and((float) (customerWallet($this->customer)?->balance ?? 0))->toBe(0.0);
});

it('charges deposits recorded through the deposits endpoint', function () {
    $apiKey = createApiKey('test-excise-key');
    $customer = Customer::factory()->create(['id_no' => 'EXC001']);

    $this->postJson('/api/v1/deposits', [
        'TransID' => 'EXCAPI0001',
        'TransactionType' => 'Pay Bill',
        'TransTime' => now()->toDateTimeString(),
        'TransAmount' => 200,
        'BusinessShortCode' => '12345',
        'BillRefNumber' => 'EXC001',
        'MSISDN' => '254712345678',
        'FirstName' => 'John',
    ], apiHeaders($apiKey->key))->assertStatus(201)->assertJsonPath('transaction.amount', '190.00');

    expect((float) customerWallet($customer)->balance)->toBe(190.0)
        ->and(ExciseDutyCharge::sole()->excise_amount)->toBe('10.00');
});

// reversal

it('gives the duty back when a charge is reversed', function () {
    confirmMpesaDeposit(100)->assertStatus(201);
    $charge = ExciseDutyCharge::sole();

    expect(app(ExciseDutyService::class)->reverse($charge))->toBeTrue();

    expect($charge->fresh()->status)->toBe(ExciseDutyCharge::STATUS_REVERSED)
        ->and((float) customerWallet($this->customer)->balance)->toBe(100.0)
        ->and(LedgerEntry::where('entry_type', 'excise_duty')->value('status'))->toBe('reversed')
        ->and((float) LedgerEntry::where('entry_type', 'excise_duty_reversal')->value('credit'))->toBe(5.0);

    expect(app(ExciseDutyService::class)->reverse($charge->fresh()))->toBeFalse();
});

it('keeps the duty once it has been remitted to KRA', function () {
    confirmMpesaDeposit(100)->assertStatus(201);
    $charge = ExciseDutyCharge::sole();
    $charge->update(['remittance_id' => ExciseDutyRemittance::factory()->create()->id]);

    expect(app(ExciseDutyService::class)->reverse($charge))->toBeFalse()
        ->and($charge->fresh()->status)->toBe(ExciseDutyCharge::STATUS_CHARGED)
        ->and((float) customerWallet($this->customer)->balance)->toBe(95.0);
});

// integrity

it('keeps the ledger in step with wallet balances', function () {
    confirmMpesaDeposit(100, transId: 'EXCREC0001')->assertStatus(201);
    confirmMpesaDeposit(333, transId: 'EXCREC0002')->assertStatus(201);

    expect((float) customerWallet($this->customer)->balance)->toBe(411.35);

    $this->artisan('wallet:reconcile')->assertSuccessful();
});

it('reports excise duty as withheld tax without unbalancing the trial balance', function () {
    confirmMpesaDeposit(100)->assertStatus(201);
    $apiKey = createApiKey('test-excise-trial-key');

    $data = $this->getJson('/api/v1/finance/trial-balance', apiHeaders($apiKey->key))->assertOk()->json('data');

    expect($data['check']['balanced'])->toBeTrue()
        ->and(collect($data['lines'])->firstWhere('entry_type', 'excise_duty')['category'])->toBe('tax_withheld');
});

it('reports the excise debit to the wallet webhook as a deposit', function () {
    confirmMpesaDeposit(100)->assertStatus(201);
    $wallet = customerWallet($this->customer);

    expect(app(WalletWebhookService::class)->buildSnapshot($wallet->id, $this->customer->id))
        ->toMatchArray(['balance' => 95.0, 'reason' => 'deposit']);
});
