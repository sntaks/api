<?php

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\GameWallet;
use App\Models\Purchase;
use App\Models\Wallet;
use App\Services\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00', config('app.timezone')));
    config(['finance.test_customer_ids' => [1, 2]]);

    $this->apiKey = createApiKey('test-finance-export-key');
    $this->headers = apiHeaders($this->apiKey->key);
    $this->ledger = app(LedgerService::class);

    exportWallet(1, 0, 'House');
    $this->alice = exportWallet(900, 1000, 'Alice Wanjiru', '254712345678');
    $this->bob = exportWallet(901, 1000, '=HYPERLINK("http://evil.example")', '254798765432');
});

function exportWallet(int $customerId, float $balance, string $name, ?string $phone = null): Wallet
{
    Customer::factory()->create(['id' => $customerId, 'name' => $name, 'phone_no' => $phone ?? '2547000'.str_pad((string) $customerId, 5, '0', STR_PAD_LEFT)]);

    return Wallet::forceCreate(['id' => $customerId, 'customer_id' => $customerId, 'balance' => $balance]);
}

function exportDeposit(string $transId, float $amount, int $status = 2, ?string $msisdn = null): Deposit
{
    return Deposit::create(['trans_id' => $transId, 'trans_time' => '20260920100000', 'trans_amount' => $amount, 'status' => $status, 'msisdn' => $msisdn]);
}

/**
 * Download a report and parse it: the response, the raw text and the rows keyed by header.
 *
 * @return array{response: TestResponse, raw: string, header: list<string>, rows: list<array<string, string>>}
 */
function downloadCsv(object $test, string $path): array
{
    $response = $test->get("/api/v1/finance/export/{$path}", $test->headers);
    $response->assertOk();

    $raw = $response->streamedContent();
    $lines = array_values(array_filter(
        array_map('str_getcsv', explode("\n", ltrim($raw, "\xEF\xBB\xBF"))),
        fn (array $line) => $line !== [null]
    ));
    $header = array_shift($lines);

    return [
        'response' => $response,
        'raw' => $raw,
        'header' => $header,
        'rows' => array_map(fn (array $line) => array_combine($header, $line), $lines),
    ];
}

it('streams a CSV download with a byte order mark, a filename and the report header', function () {
    $deposit = exportDeposit('EX1', 500, 2, '254712345678');
    $this->ledger->recordDeposit($deposit, $this->alice->fresh(), 500.0);

    $csv = downloadCsv($this, 'deposits?from=2026-09-01&to=2026-09-20');

    expect($csv['response']->headers->get('Content-Type'))->toContain('text/csv');
    expect($csv['response']->headers->get('Content-Disposition'))->toContain('finance-deposits-2026-09-01-2026-09-20.csv');
    expect(str_starts_with($csv['raw'], "\xEF\xBB\xBF"))->toBeTrue();
    expect($csv['header'])->toBe(['id', 'trans_id', 'amount', 'kind', 'status', 'customer_id', 'customer_name', 'msisdn', 'bill_ref_no', 'created_at']);
    expect($csv['rows'])->toHaveCount(1);
    expect($csv['rows'][0])->toMatchArray(['trans_id' => 'EX1', 'amount' => '500', 'kind' => 'wallet_deposit', 'customer_name' => 'Alice Wanjiru']);
});

it('masks phone numbers in the export', function () {
    $deposit = exportDeposit('EX2', 100, 2, '254712345678');
    $this->ledger->recordDeposit($deposit, $this->alice->fresh(), 100.0);

    $csv = downloadCsv($this, 'deposits');

    expect($csv['rows'][0]['msisdn'])->toBe('2547****5678');
    expect($csv['raw'])->not->toContain('254712345678');
});

it('keeps spreadsheet formulas in customer names from running', function () {
    $deposit = exportDeposit('EX3', 100);
    $this->ledger->recordDeposit($deposit, $this->bob->fresh(), 100.0);

    $csv = downloadCsv($this, 'deposits');

    expect($csv['rows'][0]['customer_name'])->toBe("'=HYPERLINK(\"http://evil.example\")");
});

it('exports every row, not one page, and applies the same filters as the report', function () {
    foreach (range(1, 60) as $n) {
        $deposit = exportDeposit("EXB{$n}", 10);
        $this->ledger->recordDeposit($deposit, $this->alice->fresh(), 10.0);
    }
    exportDeposit('EXU', 99, 0);

    expect(downloadCsv($this, 'deposits')['rows'])->toHaveCount(61);
    expect(downloadCsv($this, 'deposits?status=0')['rows'])->toHaveCount(1);
    expect(downloadCsv($this, 'deposits?per_page=5')['rows'])->toHaveCount(61);
});

it('exports the ledger with its metadata as JSON', function () {
    $gameWallet = GameWallet::create(['game_id' => 'EX_G', 'game_type' => 1, 'balance' => 0]);
    legacyGameCreditStake($this->alice, $gameWallet, 100);

    $csv = downloadCsv($this, 'ledger?entry_type=house_cut');

    expect($csv['rows'])->toHaveCount(1);
    expect(json_decode($csv['rows'][0]['metadata'], true))->toMatchArray(['source' => 'game_credit', 'game_wallet_id' => $gameWallet->id]);
    expect($csv['rows'][0]['credit'])->toBe('5');
});

it('exports the statements as flat rows', function () {
    $deposit = exportDeposit('EX4', 500);
    $this->ledger->recordDeposit($deposit, $this->alice->fresh(), 500.0);
    $gift = exportDeposit('EX5', 100);
    Purchase::create(['customer_id' => 900, 'deposit_id' => $gift->id, 'purchase_type' => 'gift', 'amount' => 100, 'value' => 0]);

    $cash = downloadCsv($this, 'cash-flow?from=2026-09-19&to=2026-09-20');
    expect($cash['header'])->toBe(['period', 'wallet_deposit', 'load', 'gift', 'emoji', 'unmatched', 'house_funding', 'shop_float', 'other', 'cash_in_total', 'paid', 'pending', 'failed', 'excise_withheld', 'excise_remitted', 'net_cash', 'referral_paid', 'referral_pending', 'referral_failed', 'deposit_refunded']);
    expect(array_column($cash['rows'], 'period'))->toBe(['2026-09-19', '2026-09-20']);
    expect($cash['rows'][1])->toMatchArray(['wallet_deposit' => '500', 'gift' => '100', 'cash_in_total' => '600', 'net_cash' => '600']);

    $income = downloadCsv($this, 'income-statement');
    expect($income['header'])->toBe(['period', 'games', 'tournaments', 'jackpots', 'competitions_unattributed', 'gift_emoji_sales', 'other', 'total', 'expenses', 'net_income', 'referral_payouts', 'promotions']);
    expect($income['rows'][0])->toMatchArray(['period' => '2026-09-20', 'gift_emoji_sales' => '100', 'total' => '100', 'expenses' => '0', 'net_income' => '100']);

    $trial = downloadCsv($this, 'trial-balance');
    expect($trial['header'])->toBe(['account', 'entry_type', 'category', 'unit', 'debit', 'credit', 'entries']);
    expect($trial['rows'][0])->toMatchArray(['account' => 'customer_wallets', 'entry_type' => 'deposit', 'category' => 'cash_in', 'credit' => '500']);
});

it('sends only the header for a report with no rows', function () {
    $csv = downloadCsv($this, 'withdrawals');

    expect($csv['header'])->toContain('status');
    expect($csv['rows'])->toBe([]);
});

it('exports the remaining reports with their headers', function (string $report, string $firstColumn) {
    expect(downloadCsv($this, $report)['header'][0])->toBe($firstColumn);
})->with([
    'withdrawals' => ['withdrawals', 'id'],
    'purchases' => ['purchases', 'id'],
    'adjustments' => ['adjustments', 'id'],
    'games' => ['games', 'id'],
    'competitions' => ['competitions', 'cmp_uid'],
    'customers-top' => ['customers-top', 'customer_id'],
]);

it('rejects an unknown report and bad parameters', function () {
    $this->get('/api/v1/finance/export/passwords', $this->headers)->assertNotFound();
    $this->getJson('/api/v1/finance/export/ledger?from=2025-01-01&to=2026-09-20', $this->headers)->assertStatus(422);
});
