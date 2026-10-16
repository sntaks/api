<?php

use App\Models\Customer;
use App\Models\ExciseDutyCharge;
use App\Models\FinanceExpense;
use App\Models\GameWallet;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00', config('app.timezone')));
    config(['finance.test_customer_ids' => [1, 2]]);

    $this->apiKey = createApiKey('test-finance-taxes-key');
    $this->headers = apiHeaders($this->apiKey->key);

    taxWallet(1, 0);
    $this->test = taxWallet(2, 1000);
    $this->alice = taxWallet(900, 1000);
});

function taxWallet(int $customerId, float $balance): Wallet
{
    Customer::factory()->create(['id' => $customerId]);

    return Wallet::forceCreate(['id' => $customerId, 'customer_id' => $customerId, 'balance' => $balance]);
}

/**
 * One game for the player: stake 100, house cuts 5 + 4, winner is paid 91.
 */
function taxGame(object $test, Wallet $player, string $gameId): void
{
    $gameWallet = GameWallet::create(['game_id' => $gameId, 'game_type' => 1, 'balance' => 0]);

    legacyGameCreditStake($player, $gameWallet, 100);

    $test->postJson('/api/v1/game/withdraw/'.encryptId($gameWallet->id), [
        'customer_id' => (string) $player->customer_id,
    ], $test->headers)->assertStatus(201);
}

function taxReport(object $test, string $query = ''): array
{
    Cache::flush();

    return $test->getJson("/api/v1/finance/taxes{$query}", $test->headers)->assertOk()->json('data');
}

it('ships with every tax rate off', function () {
    foreach (config('finance.taxes') as $tax) {
        expect($tax['rate'])->toBe(0.0);
    }

    expect(array_keys(config('finance.taxes')))->toBe(['withholding_tax', 'income_tax']);
});

it('shows the bases and no tax while the rates are off', function () {
    taxGame($this, $this->alice, 'TAX_1');
    FinanceExpense::factory()->create(['expense_date' => '2026-09-20', 'amount' => 4]);

    $data = taxReport($this);

    expect($data['configured'])->toBeFalse();
    expect($data['net_income_before_tax'])->toEqual(5);
    expect($data['taxes']['excise_duty']['base_amount'])->toEqual(0);
    expect($data['taxes']['withholding_tax']['base_amount'])->toEqual(91);
    expect($data['taxes']['income_tax']['base_amount'])->toEqual(5);

    foreach ($data['taxes'] as $line) {
        expect($line['estimated_amount'])->toEqual(0);
    }

    expect($data['totals'])->toEqual(['expense_taxes' => 0, 'pass_through_taxes' => 0, 'net_income_after_tax' => 5]);
});

it('estimates each tax from its base and works income tax after the other cost taxes', function () {
    config([
        'finance.taxes.withholding_tax.rate' => 0.2,
        'finance.taxes.income_tax.rate' => 0.3,
    ]);
    taxGame($this, $this->alice, 'TAX_2');
    FinanceExpense::factory()->create(['expense_date' => '2026-09-20', 'amount' => 4]);

    $data = taxReport($this);

    // revenue 9, expenses 4, so 5 before tax; withholding is pass-through, so income tax is 30% of 5 = 1.5
    expect($data['configured'])->toBeTrue();
    expect($data['taxes']['withholding_tax'])->toMatchArray(['base' => 'winnings', 'kind' => 'pass_through', 'rate' => 0.2, 'base_amount' => 91, 'estimated_amount' => 18.2, 'actual' => false]);
    expect($data['taxes']['income_tax'])->toMatchArray(['base' => 'net_income', 'kind' => 'expense', 'rate' => 0.3, 'base_amount' => 5, 'estimated_amount' => 1.5, 'actual' => false]);
    expect($data['totals'])->toEqual(['expense_taxes' => 1.5, 'pass_through_taxes' => 18.2, 'net_income_after_tax' => 3.5]);
    expect(array_keys($data['taxes']))->toBe(['excise_duty', 'withholding_tax', 'income_tax']);
});

it('never charges income tax on a loss', function () {
    config(['finance.taxes.income_tax.rate' => 0.3]);
    taxGame($this, $this->alice, 'TAX_3');
    FinanceExpense::factory()->create(['expense_date' => '2026-09-20', 'amount' => 100]);

    $data = taxReport($this);

    expect($data['net_income_before_tax'])->toEqual(-91);
    expect($data['taxes']['income_tax']['base_amount'])->toEqual(0);
    expect($data['taxes']['income_tax']['estimated_amount'])->toEqual(0);
    expect($data['totals']['net_income_after_tax'])->toEqual(-91);
});

it('treats a pass-through tax as not reducing net income', function () {
    config(['finance.taxes.withholding_tax.rate' => 0.2]);
    taxGame($this, $this->alice, 'TAX_4');

    $data = taxReport($this);

    expect($data['totals']['pass_through_taxes'])->toEqual(18.2);
    expect($data['totals']['expense_taxes'])->toEqual(0);
    expect($data['totals']['net_income_after_tax'])->toEqual(9);
});

it('leaves test customers out of the stakes and winnings unless asked', function () {
    config(['finance.taxes.withholding_tax.rate' => 0.1]);
    taxGame($this, $this->alice, 'TAX_5');
    taxGame($this, $this->test, 'TAX_6');

    expect(taxReport($this)['taxes']['withholding_tax']['base_amount'])->toEqual(91);
    expect(taxReport($this, '?exclude_test=0')['taxes']['withholding_tax']['base_amount'])->toEqual(182);
});

it('shows the actual excise duty charged on deposits, net of reversals, as pass-through', function () {
    config(['finance.excise_duty.enabled' => true, 'finance.excise_duty.rate' => 0.05]);
    ExciseDutyCharge::factory()->create(['customer_id' => 900, 'wallet_id' => 900, 'gross_amount' => 100, 'excise_amount' => 5, 'net_amount' => 95]);
    ExciseDutyCharge::factory()->create(['customer_id' => 900, 'wallet_id' => 900, 'gross_amount' => 300, 'excise_amount' => 15, 'net_amount' => 285]);
    ExciseDutyCharge::factory()->reversed()->create(['customer_id' => 900, 'wallet_id' => 900, 'gross_amount' => 40, 'excise_amount' => 2, 'net_amount' => 38]);

    $data = taxReport($this);

    expect(array_keys($data['taxes']))->toBe(['excise_duty', 'withholding_tax', 'income_tax']);
    expect($data['taxes']['excise_duty'])->toBe([
        'label' => 'Excise duty on deposits', 'base' => 'deposits', 'kind' => 'pass_through', 'rate' => 0.05,
        'base_amount' => 400, 'estimated_amount' => 20, 'actual' => true,
    ]);
    expect($data['configured'])->toBeTrue();
    expect($data['totals'])->toEqual(['expense_taxes' => 0, 'pass_through_taxes' => 20, 'net_income_after_tax' => 0]);
});

it('describes the period and warns that these are estimates', function () {
    $data = taxReport($this, '?from=2026-09-01&to=2026-09-20');

    expect($data['meta']['period'])->toMatchArray(['from' => '2026-09-01', 'to' => '2026-09-20']);
    expect($data['notes'][0])->toContain('estimates');
});

it('exports the tax lines as CSV', function () {
    config(['finance.taxes.withholding_tax.rate' => 0.2]);
    taxGame($this, $this->alice, 'TAX_7');

    $rows = collect(explode("\n", trim(ltrim($this->get('/api/v1/finance/export/taxes', $this->headers)->streamedContent(), "\xEF\xBB\xBF"))))->map(fn (string $line) => str_getcsv($line));

    expect($rows->first())->toBe(['tax', 'label', 'base', 'kind', 'rate', 'base_amount', 'estimated_amount']);
    expect($rows)->toHaveCount(4);
    expect($rows[1])->toBe(['excise_duty', 'Excise duty on deposits', 'deposits', 'pass_through', '0', '0', '0']);
    expect($rows[2])->toBe(['withholding_tax', 'Withholding tax on winnings', 'winnings', 'pass_through', '0.2', '91', '18.2']);
});
