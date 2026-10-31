<?php

use App\Models\Customer;
use App\Models\FinancialSnapshot;
use App\Models\Product;
use App\Models\Wager;
use App\Models\WagerSettlement;
use App\Models\Wallet;
use App\Models\WinningsTaxCharge;
use App\Models\WinningsTaxRemittance;
use App\Services\FinanceSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00', config('app.timezone')));
    config(['finance.test_customer_ids' => []]);

    $this->apiKey = createApiKey('test-winnings-tax-key');
    $this->headers = apiHeaders($this->apiKey->key);
    $this->customer = Customer::factory()->create(['name' => 'Jane Player']);
    $this->wallet = Wallet::forceCreate(['customer_id' => $this->customer->id, 'balance' => 0]);
});

/**
 * A charge on a won settlement of a wager by the test customer, dated in the past.
 */
function whtCharge(object $test, string $chargedAt, float $amount, ?Product $product = null): WinningsTaxCharge
{
    $wager = Wager::factory()->create(['customer_id' => $test->customer->id, 'wallet_id' => $test->wallet->id] + ($product ? ['product_id' => $product->id] : []));

    return WinningsTaxCharge::factory()->create([
        'wager_settlement_id' => fn () => WagerSettlement::factory()->won(300, 0, $amount)->create(['wager_id' => $wager->id, 'tax_base' => $amount * 5, 'tax_rate' => 0.2])->id,
        'tax_base' => $amount * 5,
        'amount' => $amount,
        'charged_at' => CarbonImmutable::parse($chargedAt, config('app.timezone')),
    ]);
}

/**
 * The credit row of a charge's reversal, dated in the past.
 */
function whtReversal(WinningsTaxCharge $charge, string $chargedAt): WinningsTaxCharge
{
    return WinningsTaxCharge::factory()->reversalOf($charge)->create(['charged_at' => CarbonImmutable::parse($chargedAt, config('app.timezone'))]);
}

function whtGet(object $test, string $path): array
{
    Cache::flush();

    return $test->getJson("/api/v1/finance/{$path}", $test->headers)->assertOk()->json('data');
}

function whtRemit(object $test, array $overrides = [])
{
    return $test->postJson('/api/v1/finance/winnings-tax/remittances', $overrides + [
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'amount_paid' => 15,
        'kra_reference' => 'PRN0000000001',
        'paid_at' => '2026-09-18',
    ], $test->headers);
}

it('reports charged, reversed and remitted winnings tax', function () {
    $charge = whtCharge($this, '2026-09-20 09:00:00', 36);
    whtCharge($this, '2026-09-20 10:00:00', 20);
    whtReversal($charge, '2026-09-20 11:00:00');

    $data = whtGet($this, 'winnings-tax');

    expect($data['totals'])->toEqual(['charges' => 2, 'tax_charged' => 56, 'tax_reversed' => 36, 'tax_net' => 20, 'tax_remitted' => 0])
        ->and($data['payable']['outstanding'])->toEqual(20)
        ->and($data['series'][0]['period'])->toBe('2026-09-20');
});

it('lists charges by customer and product', function () {
    $sportsbook = Product::factory()->create(['code' => 'sportsbook_main']);
    $crashCharge = whtCharge($this, '2026-09-20 09:00:00', 36);
    $sportsbookCharge = whtCharge($this, '2026-09-20 10:00:00', 20, $sportsbook);
    whtReversal($crashCharge, '2026-09-20 11:00:00');

    $all = whtGet($this, 'winnings-tax/charges');

    expect($all['pagination']['total'])->toBe(3)
        ->and($all['summary'])->toMatchArray(['rows' => 3, 'tax_net' => 20, 'unremitted' => ['rows' => 3, 'tax' => 20]])
        ->and($all['items'][0])->toMatchArray([
            'kind' => 'reversal', 'amount' => -36, 'customer_id' => $this->customer->id, 'customer_name' => 'Jane Player',
            'wager_id' => $crashCharge->wager_id, 'settlement_version' => 1, 'remittance_id' => null,
        ])
        ->and(whtGet($this, 'winnings-tax/charges?product=sportsbook_main')['items'])->toHaveCount(1)
        ->and(whtGet($this, 'winnings-tax/charges?product=sportsbook_main')['items'][0]['id'])->toBe($sportsbookCharge->id)
        ->and(whtGet($this, 'winnings-tax/charges?kind=reversal')['pagination']['total'])->toBe(1)
        ->and(whtGet($this, "winnings-tax/charges?customer_id={$this->customer->id}")['pagination']['total'])->toBe(3)
        ->and(whtGet($this, 'winnings-tax/charges?customer_id=999999')['pagination']['total'])->toBe(0);
});

it('shows monthly returns with due date and overdue flag', function () {
    whtCharge($this, '2026-08-05 09:00:00', 10);
    whtCharge($this, '2026-08-31 23:30:00', 5);
    whtCharge($this, '2026-09-02 09:00:00', 3);

    $data = whtGet($this, 'winnings-tax/returns?from=2026-08-01&to=2026-09-20');

    expect($data['filing_day'])->toBe(20)
        ->and($data['payable'])->toEqual(18)
        ->and($data['items'][0])->toMatchArray([
            'period' => '2026-08', 'due_date' => '2026-09-20', 'charges' => 2,
            'tax_charged' => 15, 'tax_reversed' => 0, 'tax_due' => 15, 'tax_remitted' => 0, 'outstanding' => 15, 'overdue' => false,
        ])
        ->and($data['items'][1])->toMatchArray(['period' => '2026-09', 'due_date' => '2026-10-20', 'outstanding' => 3]);

    $this->travelTo(CarbonImmutable::parse('2026-09-21 08:00:00', config('app.timezone')));

    expect(whtGet($this, 'winnings-tax/returns?from=2026-08-01&to=2026-09-21')['items'][0]['overdue'])->toBeTrue();
});

it('records a remittance for every unremitted charge in the period', function () {
    $first = whtCharge($this, '2026-08-05 09:00:00', 10);
    $second = whtCharge($this, '2026-08-31 23:30:00', 5);
    $september = whtCharge($this, '2026-09-02 09:00:00', 3);

    $response = whtRemit($this, ['amount_paid' => 15.5])->assertCreated();

    expect($response->json('data'))->toMatchArray([
        'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'charges' => 2,
        'amount_due' => 15, 'amount_paid' => 15.5, 'difference' => 0.5,
        'kra_reference' => 'PRN0000000001', 'status' => 'active', 'recorded_by' => "api_key:{$this->apiKey->id}",
    ]);

    $remittanceId = $response->json('data.id');
    expect($first->fresh()->remittance_id)->toBe($remittanceId)
        ->and($second->fresh()->remittance_id)->toBe($remittanceId)
        ->and($september->fresh()->remittance_id)->toBeNull()
        ->and(whtGet($this, 'winnings-tax/returns?from=2026-08-01&to=2026-08-31')['items'][0])->toMatchArray(['tax_remitted' => 15, 'outstanding' => 0]);

    $list = whtGet($this, 'winnings-tax/remittances?from=2026-08-01&to=2026-09-20');
    expect($list['items'][0])->toMatchArray(['id' => $remittanceId, 'charges' => 2, 'amount_due' => 15])
        ->and($list['summary']['active'])->toEqual(['remittances' => 1, 'amount_due' => 15, 'amount_paid' => 15.5])
        ->and($list['summary']['payable'])->toEqual(3);
});

it('carries a reversal credit into the next return', function () {
    $august = whtCharge($this, '2026-08-05 09:00:00', 10);
    whtRemit($this, ['amount_paid' => 10])->assertCreated();
    $credit = whtReversal($august, '2026-09-03 09:00:00');
    $september = whtCharge($this, '2026-09-10 09:00:00', 25);

    expect(whtGet($this, 'winnings-tax/returns?from=2026-09-01&to=2026-09-20')['items'][0])->toMatchArray([
        'tax_charged' => 25, 'tax_reversed' => 10, 'tax_due' => 15, 'outstanding' => 15,
    ]);

    $response = whtRemit($this, ['period_start' => '2026-09-01', 'period_end' => '2026-09-19', 'amount_paid' => 15, 'kra_reference' => 'PRN0000000002'])->assertCreated();

    expect($response->json('data'))->toMatchArray(['charges' => 2, 'amount_due' => 15])
        ->and($credit->fresh()->remittance_id)->toBe($response->json('data.id'))
        ->and($september->fresh()->remittance_id)->toBe($response->json('data.id'))
        ->and($august->fresh()->remittance_id)->not->toBe($response->json('data.id'))
        ->and(whtGet($this, 'winnings-tax/returns?from=2026-09-01&to=2026-09-20')['payable'])->toEqual(0);
});

it('carries a credit from an earlier month into a later return', function () {
    $july = whtCharge($this, '2026-07-05 09:00:00', 10);
    whtRemit($this, ['period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'amount_paid' => 10])->assertCreated();
    $credit = whtReversal($july, '2026-08-03 09:00:00');

    whtRemit($this, ['kra_reference' => 'PRN0000000002'])->assertStatus(422)->assertJsonPath('success', false);
    expect($credit->fresh()->remittance_id)->toBeNull();

    whtCharge($this, '2026-09-05 09:00:00', 30);
    $response = whtRemit($this, ['period_start' => '2026-09-01', 'period_end' => '2026-09-19', 'amount_paid' => 20, 'kra_reference' => 'PRN0000000003'])->assertCreated();

    expect($response->json('data'))->toMatchArray(['charges' => 2, 'amount_due' => 20])
        ->and($credit->fresh()->remittance_id)->toBe($response->json('data.id'));
});

it('voids a remittance and frees its charges', function () {
    $charge = whtCharge($this, '2026-08-05 09:00:00', 10);
    $id = whtRemit($this)->assertCreated()->json('data.id');

    $void = fn () => $this->postJson('/api/v1/finance/winnings-tax/remittances/'.encryptId($id).'/void', ['reason' => 'wrong period'], $this->headers);

    $void()->assertOk()->assertJsonPath('data.status', 'voided')->assertJsonPath('data.charges', 0)->assertJsonPath('data.void_reason', 'wrong period');
    expect($charge->fresh()->remittance_id)->toBeNull()
        ->and(whtGet($this, 'winnings-tax/returns?from=2026-08-01&to=2026-08-31')['payable'])->toEqual(10);

    $void()->assertStatus(409);
    $this->postJson('/api/v1/finance/winnings-tax/remittances/'.encryptId(999).'/void', ['reason' => 'missing'], $this->headers)->assertNotFound();

    whtRemit($this)->assertCreated();
});

it('refuses a remittance with nothing owed', function () {
    whtRemit($this)->assertStatus(422)->assertJsonPath('message', 'There is no winnings tax owed in that period.');

    // A different body: the idempotency middleware would replay the identical first request's 422
    whtCharge($this, '2026-08-05 09:00:00', 10);
    whtRemit($this, ['amount_paid' => 10])->assertCreated();
    whtRemit($this, ['kra_reference' => 'PRN0000000002'])->assertStatus(422)->assertJsonPath('success', false);
    whtRemit($this, ['period_start' => '2026-09-01', 'period_end' => '2026-09-20'])->assertStatus(422)->assertJsonValidationErrors('kra_reference');

    expect(WinningsTaxRemittance::count())->toBe(1);
});

it('validates a remittance', function (array $payload, string $field) {
    whtRemit($this, $payload)->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'period ends before it starts' => [['period_start' => '2026-08-31', 'period_end' => '2026-08-01'], 'period_end'],
    'period in the future' => [['period_end' => '2026-10-31'], 'period_end'],
    'nothing paid' => [['amount_paid' => 0], 'amount_paid'],
    'no reference' => [['kra_reference' => ''], 'kra_reference'],
]);

// finance integration

it('shows winnings tax owed to KRA as a liability on the balance sheet and in the snapshot', function () {
    $charge = whtCharge($this, '2026-09-20 09:00:00', 36);
    whtCharge($this, '2026-09-20 10:00:00', 20);
    whtReversal($charge, '2026-09-20 11:00:00');

    expect(whtGet($this, 'balance-sheet')['liabilities']['winnings_tax_payable'])->toEqual(20);

    app(FinanceSnapshotService::class)->takeSnapshot();

    expect(FinancialSnapshot::sole()->winnings_tax_payable)->toBe('20.00')
        ->and(whtGet($this, 'balance-sheet?as_of=2026-09-20')['liabilities']['winnings_tax_payable'])->toEqual(20);
});

it('shows winnings tax withheld and remitted in the cash flow and takes remittances off net cash', function () {
    whtCharge($this, '2026-08-10 09:00:00', 15);
    $today = whtCharge($this, '2026-09-20 09:00:00', 36);
    whtReversal($today, '2026-09-20 10:00:00');
    whtCharge($this, '2026-09-20 11:00:00', 8);
    whtRemit($this, ['paid_at' => '2026-09-20'])->assertCreated();

    $totals = whtGet($this, 'cash-flow')['totals'];

    expect($totals['winnings_tax'])->toEqual(['withheld' => 8, 'remitted' => 15])
        ->and($totals['net_cash'])->toEqual(-15);
});

it('passes the winnings tax checks for correct charges and credits', function () {
    $charge = whtCharge($this, '2026-09-20 09:00:00', 36);
    whtReversal($charge, '2026-09-20 10:00:00');
    $charge->settlement->update(['status' => 'reversed']);

    $checks = collect(whtGet($this, 'reconciliation')['checks'])->keyBy('key');

    foreach (['winnings_tax_amounts', 'winnings_tax_ledger', 'settlements_without_winnings_tax', 'overdue_winnings_tax'] as $key) {
        expect($checks[$key]['status'])->toBe('pass', $key);
    }
});

it('flags a winnings tax row worked out wrongly or out of step with the ledger', function () {
    whtCharge($this, '2026-09-20 09:00:00', 36)->update(['amount' => 30]);

    $checks = collect(whtGet($this, 'reconciliation')['checks'])->keyBy('key');

    expect($checks['winnings_tax_amounts'])->toMatchArray(['status' => 'fail', 'count' => 1])
        ->and($checks['winnings_tax_ledger'])->toMatchArray(['status' => 'fail', 'count' => 1]);
});

it('flags a taxed settlement without its charge, or a reversed one without its credit', function () {
    $charge = whtCharge($this, '2026-09-20 09:00:00', 36);
    $charge->settlement->update(['status' => 'reversed']);
    $orphan = WagerSettlement::factory()->won(300, 0, 12)->create(['wager_id' => $charge->wager_id, 'version' => 2]);

    $check = collect(whtGet($this, 'reconciliation')['checks'])->firstWhere('key', 'settlements_without_winnings_tax');

    expect($check)->toMatchArray(['status' => 'fail', 'count' => 2, 'amount' => 48.0])
        ->and(collect($check['samples'])->pluck('id')->sort()->values()->all())->toBe(collect([$charge->wager_settlement_id, $orphan->id])->sort()->values()->all());
});

it('warns about winnings tax not paid by the due date', function () {
    whtCharge($this, '2026-07-10 09:00:00', 12);

    $check = collect(whtGet($this, 'reconciliation')['checks'])->firstWhere('key', 'overdue_winnings_tax');

    expect($check)->toMatchArray(['status' => 'warn', 'count' => 1, 'amount' => 12.0])
        ->and($check['samples'][0])->toEqual(['period' => '2026-07', 'due_date' => '2026-08-20', 'outstanding' => 12]);
});

it('does not flag a month as overdue when earlier credits leave nothing to remit', function () {
    $july = whtCharge($this, '2026-07-05 09:00:00', 150);
    whtRemit($this, ['period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'amount_paid' => 150])->assertCreated();
    whtReversal($july, '2026-08-03 09:00:00');
    whtCharge($this, '2026-09-05 09:00:00', 30);
    $this->travelTo(CarbonImmutable::parse('2026-10-21 08:00:00', config('app.timezone')));

    $september = collect(whtGet($this, 'winnings-tax/returns?from=2026-08-01&to=2026-09-30')['items'])->firstWhere('period', '2026-09');
    $check = collect(whtGet($this, 'reconciliation')['checks'])->firstWhere('key', 'overdue_winnings_tax');

    expect($september)->toMatchArray(['outstanding' => 30, 'amount_to_remit' => 0, 'overdue' => false])
        ->and($check)->toMatchArray(['status' => 'pass', 'count' => 0]);
    whtRemit($this, ['period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'amount_paid' => 30, 'kra_reference' => 'PRN0000000002'])->assertStatus(422);
});

it('flags only the months a remittance could actually be recorded for', function () {
    $june = whtCharge($this, '2026-06-05 09:00:00', 40);
    whtRemit($this, ['period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'amount_paid' => 40])->assertCreated();
    whtCharge($this, '2026-07-05 09:00:00', 50);
    whtReversal($june, '2026-08-03 09:00:00');
    whtCharge($this, '2026-09-05 09:00:00', 30);
    $this->travelTo(CarbonImmutable::parse('2026-10-21 08:00:00', config('app.timezone')));

    $months = collect(whtGet($this, 'winnings-tax/returns?from=2026-07-01&to=2026-09-30')['items'])->keyBy('period');
    $check = collect(whtGet($this, 'reconciliation')['checks'])->firstWhere('key', 'overdue_winnings_tax');

    expect($months['2026-07'])->toMatchArray(['amount_to_remit' => 50, 'overdue' => true])
        ->and($months['2026-09'])->toMatchArray(['outstanding' => 30, 'amount_to_remit' => 0, 'overdue' => false])
        ->and($check)->toMatchArray(['status' => 'warn', 'count' => 1, 'amount' => 50.0]);
});

it('flags a winnings tax row whose ledger entry belongs to another settlement', function () {
    $charge = whtCharge($this, '2026-09-20 09:00:00', 36);
    $other = whtCharge($this, '2026-09-20 10:00:00', 36);
    $charge->update(['ledger_entry_id' => $other->ledger_entry_id]);

    $check = collect(whtGet($this, 'reconciliation')['checks'])->firstWhere('key', 'winnings_tax_ledger');

    expect($check)->toMatchArray(['status' => 'fail', 'count' => 1]);
});

it('flags a settlement reversed in the range without its credit row, whenever it was settled', function () {
    $charge = whtCharge($this, '2026-06-10 09:00:00', 36);
    $charge->settlement->forceFill(['created_at' => '2026-06-10 09:00:00', 'status' => 'reversed', 'reversed_at' => '2026-09-20 09:00:00'])->save();

    $check = collect(whtGet($this, 'reconciliation?from=2026-09-01&to=2026-09-20')['checks'])->firstWhere('key', 'settlements_without_winnings_tax');

    expect($check)->toMatchArray(['status' => 'fail', 'count' => 1, 'amount' => 36.0]);
});
