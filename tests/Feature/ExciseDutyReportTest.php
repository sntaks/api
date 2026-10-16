<?php

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\ExciseDutyCharge;
use App\Models\ExciseDutyRemittance;
use App\Models\FinancialSnapshot;
use App\Models\Wallet;
use App\Services\FinanceSnapshotService;
use App\Services\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00', config('app.timezone')));
    config([
        'finance.test_customer_ids' => [],
        'finance.excise_duty.enabled' => true,
        'finance.excise_duty.rate' => 0.05,
        'finance.excise_duty.effective_from' => '2026-08-01',
    ]);

    $this->apiKey = createApiKey('test-excise-report-key');
    $this->headers = apiHeaders($this->apiKey->key);

    $this->customer = Customer::factory()->create(['account_no' => '712345678', 'name' => 'Jane Player']);
});

function exciseDeposit(object $test, float $amount, string $transId): void
{
    $test->postJson('/api/v1/c2b/confirm', [
        'TransID' => $transId,
        'TransactionType' => 'Pay Bill',
        'TransTime' => now()->format('YmdHis'),
        'TransAmount' => $amount,
        'BusinessShortCode' => '12345',
        'BillRefNumber' => '0712345678',
        'MSISDN' => '254712345678',
        'FirstName' => 'Jane',
    ])->assertStatus(201);
}

/**
 * A charge dated in the past, for returns and remittances.
 */
function datedCharge(object $test, string $chargedAt, float $excise): ExciseDutyCharge
{
    return ExciseDutyCharge::factory()->create([
        'customer_id' => $test->customer->id,
        'gross_amount' => $excise * 20,
        'excise_amount' => $excise,
        'net_amount' => $excise * 19,
        'charged_at' => CarbonImmutable::parse($chargedAt, config('app.timezone')),
    ]);
}

function exciseGet(object $test, string $path): array
{
    Cache::flush();

    return $test->getJson("/api/v1/finance/{$path}", $test->headers)->assertOk()->json('data');
}

function remit(object $test, array $overrides = [])
{
    return $test->postJson('/api/v1/finance/excise-duty/remittances', $overrides + [
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'amount_paid' => 15,
        'kra_reference' => 'PRN0000000001',
        'paid_at' => '2026-09-18',
    ], $test->headers);
}

// summary and charges

it('summarises excise duty charged in the period and what is still owed', function () {
    exciseDeposit($this, 100, 'EXS0000001');
    exciseDeposit($this, 200, 'EXS0000002');

    $data = exciseGet($this, 'excise-duty');

    expect($data['enabled'])->toBeTrue()
        ->and($data['rate'])->toEqual(0.05)
        ->and($data['totals'])->toEqual([
            'deposits' => 2, 'gross_deposits' => 300, 'excise_charged' => 15, 'excise_reversed' => 0, 'excise_net' => 15, 'excise_remitted' => 0,
        ])
        ->and($data['payable']['outstanding'])->toEqual(15)
        ->and($data['series'][0]['period'])->toBe('2026-09-20');
});

it('lists the charge on each deposit with the phone number masked', function () {
    exciseDeposit($this, 100, 'EXS0000003');

    $data = exciseGet($this, 'excise-duty/charges');

    expect($data['summary'])->toMatchArray(['charges' => 1, 'gross_deposits' => 100, 'excise' => 5, 'unremitted' => ['charges' => 1, 'excise' => 5]])
        ->and($data['items'][0])->toMatchArray([
            'trans_id' => 'EXS0000003',
            'customer_id' => $this->customer->id,
            'customer_name' => 'Jane Player',
            'msisdn' => '2547****5678',
            'gross_amount' => 100,
            'rate' => 0.05,
            'excise_amount' => 5,
            'net_amount' => 95,
            'status' => 'charged',
            'remittance_id' => null,
        ])
        ->and($data['pagination']['total'])->toBe(1);
});

it('filters charges by status', function () {
    datedCharge($this, '2026-09-19 10:00:00', 5);
    $remitted = datedCharge($this, '2026-09-19 11:00:00', 7);
    $remitted->update(['remittance_id' => ExciseDutyRemittance::factory()->create()->id]);
    ExciseDutyCharge::factory()->reversed()->create(['customer_id' => $this->customer->id, 'charged_at' => '2026-09-19 12:00:00']);

    $range = '?from=2026-09-19&to=2026-09-19';

    expect(exciseGet($this, "excise-duty/charges{$range}&status=unremitted")['pagination']['total'])->toBe(1)
        ->and(exciseGet($this, "excise-duty/charges{$range}&status=remitted")['items'][0]['id'])->toBe($remitted->id)
        ->and(exciseGet($this, "excise-duty/charges{$range}&status=reversed")['pagination']['total'])->toBe(1)
        ->and(exciseGet($this, "excise-duty/charges{$range}")['pagination']['total'])->toBe(3);
});

// statements

it('shows excise duty owed to KRA as a liability on the balance sheet and in the snapshot', function () {
    exciseDeposit($this, 100, 'EXS0000004');

    expect(exciseGet($this, 'balance-sheet')['liabilities']['excise_duty_payable'])->toEqual(5);

    app(FinanceSnapshotService::class)->takeSnapshot();

    expect(FinancialSnapshot::sole()->excise_duty_payable)->toBe('5.00')
        ->and(exciseGet($this, 'balance-sheet?as_of=2026-09-20')['liabilities']['excise_duty_payable'])->toEqual(5);
});

it('shows excise withheld and remitted in the cash flow and takes remittances off net cash', function () {
    datedCharge($this, '2026-08-10 09:00:00', 15);
    exciseDeposit($this, 100, 'EXS0000005');
    remit($this, ['paid_at' => '2026-09-20'])->assertCreated();

    $totals = exciseGet($this, 'cash-flow')['totals'];

    // cash in is today's 100 plus the 300 deposit the factory records today behind the August charge; only today's 5 is withheld in range
    expect($totals['cash_in']['total'])->toEqual(400)
        ->and($totals['excise_duty'])->toEqual(['withheld' => 5, 'remitted' => 15])
        ->and($totals['net_cash'])->toEqual(385);
});

it('adds the duty each customer paid to the top customers report', function () {
    exciseDeposit($this, 100, 'EXS0000006');

    $row = exciseGet($this, 'customers/top')['items'][0];

    expect($row)->toMatchArray(['customer_id' => $this->customer->id, 'deposited' => 100, 'excise_paid' => 5, 'balance' => 95]);
});

// returns and remittances

it('works out each monthly return with its due date', function () {
    datedCharge($this, '2026-08-05 09:00:00', 10);
    datedCharge($this, '2026-08-31 23:30:00', 5);
    datedCharge($this, '2026-09-02 09:00:00', 3);

    $data = exciseGet($this, 'excise-duty/returns?from=2026-08-01&to=2026-09-20');

    expect($data['filing_day'])->toBe(20)
        ->and($data['payable'])->toEqual(18)
        ->and($data['items'])->toHaveCount(2)
        ->and($data['items'][0])->toMatchArray([
            'period' => '2026-08', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'due_date' => '2026-09-20',
            'charges' => 2, 'excise_charged' => 15, 'excise_due' => 15, 'excise_remitted' => 0, 'outstanding' => 15, 'overdue' => false,
        ])
        ->and($data['items'][1])->toMatchArray(['period' => '2026-09', 'due_date' => '2026-10-20', 'outstanding' => 3]);

    $this->travelTo(CarbonImmutable::parse('2026-09-21 08:00:00', config('app.timezone')));

    expect(exciseGet($this, 'excise-duty/returns?from=2026-08-01&to=2026-09-21')['items'][0]['overdue'])->toBeTrue();
});

it('records a KRA payment against every unremitted charge in the period', function () {
    $first = datedCharge($this, '2026-08-05 09:00:00', 10);
    $second = datedCharge($this, '2026-08-31 23:30:00', 5);
    $september = datedCharge($this, '2026-09-02 09:00:00', 3);

    $response = remit($this, ['amount_paid' => 15.5])->assertCreated();

    expect($response->json('data'))->toMatchArray([
        'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'charges' => 2,
        'amount_due' => 15, 'amount_paid' => 15.5, 'difference' => 0.5,
        'kra_reference' => 'PRN0000000001', 'status' => 'active', 'recorded_by' => "api_key:{$this->apiKey->id}",
    ]);

    $remittanceId = $response->json('data.id');
    expect($first->fresh()->remittance_id)->toBe($remittanceId)
        ->and($second->fresh()->remittance_id)->toBe($remittanceId)
        ->and($september->fresh()->remittance_id)->toBeNull()
        ->and(exciseGet($this, 'excise-duty/returns?from=2026-08-01&to=2026-08-31')['items'][0])->toMatchArray(['excise_remitted' => 15, 'outstanding' => 0])
        ->and(exciseGet($this, 'balance-sheet')['liabilities']['excise_duty_payable'])->toEqual(3);

    $list = exciseGet($this, 'excise-duty/remittances?from=2026-08-01&to=2026-09-20');
    expect($list['items'][0])->toMatchArray(['id' => $remittanceId, 'charges' => 2, 'amount_due' => 15])
        ->and($list['summary']['active'])->toEqual(['remittances' => 1, 'amount_due' => 15, 'amount_paid' => 15.5])
        ->and($list['summary']['payable'])->toEqual(3);
});

it('refuses a remittance for a period with nothing left to remit, or a reused KRA reference', function () {
    datedCharge($this, '2026-08-05 09:00:00', 10);

    remit($this)->assertCreated();
    remit($this, ['kra_reference' => 'PRN0000000002'])->assertStatus(422)->assertJsonPath('success', false);
    remit($this, ['period_start' => '2026-09-01', 'period_end' => '2026-09-20'])->assertStatus(422)->assertJsonValidationErrors('kra_reference');
});

it('validates a remittance', function (array $payload, string $field) {
    remit($this, $payload)->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'period ends before it starts' => [['period_start' => '2026-08-31', 'period_end' => '2026-08-01'], 'period_end'],
    'period in the future' => [['period_end' => '2026-10-31'], 'period_end'],
    'nothing paid' => [['amount_paid' => 0], 'amount_paid'],
    'no reference' => [['kra_reference' => ''], 'kra_reference'],
]);

it('voids a remittance and makes its charges owed again', function () {
    $charge = datedCharge($this, '2026-08-05 09:00:00', 10);
    $id = remit($this)->assertCreated()->json('data.id');

    $void = fn () => $this->postJson('/api/v1/finance/excise-duty/remittances/'.encryptId($id).'/void', ['reason' => 'wrong period'], $this->headers);

    $void()->assertOk()->assertJsonPath('data.status', 'voided')->assertJsonPath('data.charges', 0)->assertJsonPath('data.void_reason', 'wrong period');
    expect($charge->fresh()->remittance_id)->toBeNull()
        ->and(exciseGet($this, 'balance-sheet')['liabilities']['excise_duty_payable'])->toEqual(10);

    $void()->assertStatus(409);
    $this->postJson('/api/v1/finance/excise-duty/remittances/'.encryptId(999).'/void', ['reason' => 'missing'], $this->headers)->assertNotFound();

    // The reference of a voided remittance can be used again.
    remit($this)->assertCreated();
});

// reconciliation

it('flags a wallet deposit credited without its excise duty', function () {
    $deposit = Deposit::create([
        'trans_id' => 'EXSNOTAX01', 'trans_type' => 'Pay Bill', 'trans_time' => now(), 'trans_amount' => 100,
        'short_code' => '12345', 'bill_ref_no' => '0712345678', 'msisdn' => '254712345678', 'name' => 'Jane', 'status' => 2,
    ]);
    $wallet = Wallet::create(['customer_id' => $this->customer->id, 'balance' => 0]);
    app(LedgerService::class)->recordDeposit($deposit, $wallet, 100);

    $check = collect(exciseGet($this, 'reconciliation')['checks'])->firstWhere('key', 'deposits_without_excise_duty');

    expect($check)->toMatchArray(['status' => 'fail', 'count' => 1, 'amount' => 100.0]);
});

it('passes the excise checks for deposits charged correctly', function () {
    exciseDeposit($this, 100, 'EXS0000007');

    $checks = collect(exciseGet($this, 'reconciliation')['checks'])->keyBy('key');

    foreach (['deposits_without_excise_duty', 'excise_duty_amounts', 'excise_duty_ledger', 'overdue_excise_duty'] as $key) {
        expect($checks[$key]['status'])->toBe('pass');
    }
});

it('flags a charge worked out wrongly or out of step with the ledger', function () {
    exciseDeposit($this, 100, 'EXS0000008');
    ExciseDutyCharge::sole()->update(['excise_amount' => 4, 'net_amount' => 96]);

    $checks = collect(exciseGet($this, 'reconciliation')['checks'])->keyBy('key');

    expect($checks['excise_duty_amounts'])->toMatchArray(['status' => 'fail', 'count' => 1])
        ->and($checks['excise_duty_ledger'])->toMatchArray(['status' => 'fail', 'count' => 1]);
});

it('warns about excise duty not paid by the due date', function () {
    datedCharge($this, '2026-07-10 09:00:00', 12);

    $check = collect(exciseGet($this, 'reconciliation')['checks'])->firstWhere('key', 'overdue_excise_duty');

    expect($check)->toMatchArray(['status' => 'warn', 'count' => 1, 'amount' => 12.0])
        ->and($check['samples'][0])->toEqual(['period' => '2026-07', 'due_date' => '2026-08-20', 'outstanding' => 12]);
});

// export and access

it('exports the excise duty reports as CSV', function (string $report, array $header) {
    exciseDeposit($this, 100, 'EXS0000009');

    $raw = $this->get("/api/v1/finance/export/{$report}", $this->headers)->assertOk()->streamedContent();
    $lines = array_map('str_getcsv', explode("\n", trim(ltrim($raw, "\xEF\xBB\xBF"))));

    expect($lines[0])->toBe($header);
})->with([
    'summary' => ['excise-duty', ['period', 'deposits', 'gross_deposits', 'excise_charged', 'excise_reversed', 'excise_net', 'excise_remitted']],
    'charges' => ['excise-duty-charges', ['id', 'charged_at', 'source', 'deposit_id', 'promotion_credit_id', 'bet_shop_load_id', 'trans_id', 'customer_id', 'customer_name', 'msisdn', 'gross_amount', 'rate', 'excise_amount', 'net_amount', 'status', 'remittance_id', 'kra_reference']],
    'returns' => ['excise-duty-returns', ['period', 'period_start', 'period_end', 'due_date', 'charges', 'gross_deposits', 'excise_charged', 'excise_reversed', 'excise_due', 'excise_remitted', 'outstanding', 'overdue']],
    'remittances' => ['excise-duty-remittances', ['id', 'period_start', 'period_end', 'charges', 'amount_due', 'amount_paid', 'difference', 'kra_reference', 'paid_at', 'status', 'recorded_by', 'created_at', 'voided_at', 'voided_by', 'void_reason']],
]);

it('requires an API key', function () {
    $this->getJson('/api/v1/finance/excise-duty')->assertUnauthorized();
    $this->getJson('/api/v1/finance/excise-duty/charges')->assertUnauthorized();
    $this->getJson('/api/v1/finance/excise-duty/returns')->assertUnauthorized();
    $this->getJson('/api/v1/finance/excise-duty/remittances')->assertUnauthorized();
    $this->postJson('/api/v1/finance/excise-duty/remittances', [])->assertUnauthorized();
    $this->postJson('/api/v1/finance/excise-duty/remittances/'.encryptId(1).'/void', ['reason' => 'x'])->assertUnauthorized();
});

it('shows the excise amounts on a deposit', function () {
    exciseDeposit($this, 100, 'EXS0000010');
    $deposit = Deposit::sole();

    $this->getJson('/api/v1/deposits/'.encryptId($deposit->id), $this->headers)
        ->assertOk()
        ->assertJsonPath('data.excise_amount', '5.00')
        ->assertJsonPath('data.net_amount', '95.00');
});
