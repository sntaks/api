<?php

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\ExciseDutyCharge;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use App\Models\Wallet;
use App\Util\PlayerNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'finance.excise_duty.enabled' => true,
        'finance.excise_duty.rate' => 0.05,
        'finance.excise_duty.effective_from' => null,
    ]);

    $this->apiKey = createApiKey('test-bill-ref-key');
    $house = Customer::factory()->create(['id' => 1, 'phone_no' => '254700000009']);
    $this->houseWallet = Wallet::forceCreate(['id' => 1, 'customer_id' => $house->id, 'balance' => 0]);

    $this->ann = Customer::factory()->create(['account_no' => 'KK-6AA8B1DAAF392', 'phone_no' => '254712345678']);
    $this->ann->forceFill(['phone_no_verified_at' => now()])->save();
    $this->annPays = hash('sha256', '254712345678');
});

function annBalance(Customer $customer): float
{
    return (float) (Wallet::where('customer_id', $customer->id)->value('balance') ?? 0);
}

it('credits a paybill deposit by player number', function () {
    payPaybill(PlayerNumber::format($this->ann->player_no), hash('sha256', '254799000000'), 'BRF0000001')->assertStatus(201);

    $deposit = Deposit::where('trans_id', 'BRF0000001')->sole();

    expect(annBalance($this->ann))->toBe(95.0)
        ->and((int) $deposit->status)->toBe(Deposit::STATUS_COMPLETED)
        ->and($deposit->matched_by)->toBe('player_no')
        ->and(LedgerEntry::where('entry_type', 'deposit')->count())->toBe(1)
        ->and(ExciseDutyCharge::count())->toBe(1);
});

it("credits a paybill deposit by the payer's own verified phone", function () {
    payPaybill('0712345678', $this->annPays, 'BRF0000002')->assertStatus(201);

    expect(annBalance($this->ann))->toBe(95.0)
        ->and(Deposit::where('trans_id', 'BRF0000002')->sole()->matched_by)->toBe('phone');
});

it('credits a verified phone paid from another number for review', function () {
    payPaybill('0712345678', hash('sha256', '254799000000'), 'BRF0000003')->assertStatus(201);

    expect(annBalance($this->ann))->toBe(95.0)
        ->and(Deposit::where('trans_id', 'BRF0000003')->sole()->matched_by)->toBe('phone_other_payer');
});

it('leaves an unverified phone deposit unmatched with its reason', function () {
    $ben = Customer::factory()->create(['phone_no' => '254733000222']);

    payPaybill('0733000222', hash('sha256', '254733000222'), 'BRF0000004')->assertOk();

    $deposit = Deposit::where('trans_id', 'BRF0000004')->sole();

    expect((int) $deposit->status)->toBe(Deposit::STATUS_UNMATCHED)
        ->and($deposit->unmatched_reason)->toBe('phone_unverified')
        ->and($deposit->matched_by)->toBeNull()
        ->and(annBalance($ben))->toBe(0.0);
});

it('still credits by account number', function () {
    payPaybill('KK-6AA8B1DAAF392', $this->annPays, 'BRF0000005')->assertStatus(201);

    expect(annBalance($this->ann))->toBe(95.0)
        ->and(Deposit::where('trans_id', 'BRF0000005')->sole()->matched_by)->toBe('account_no');
});

it('never credits the house account by player number', function () {
    $houseCustomer = Customer::find(1);

    payPaybill($houseCustomer->player_no, hash('sha256', '254700000009'), 'BRF0000006')->assertStatus(201);

    expect((int) Deposit::where('trans_id', 'BRF0000006')->sole()->status)->toBe(Deposit::STATUS_UNMATCHED)
        ->and((float) $this->houseWallet->fresh()->balance)->toBe(0.0);
});

it('routes a gift paid by player number to the gift purchase', function () {
    payPaybill($this->ann->player_no.'#50#gift', $this->annPays, 'BRF0000007', 50)->assertStatus(201);

    expect(Purchase::where('purchase_type', 'gift')->where('customer_id', $this->ann->id)->count())->toBe(1)
        ->and(annBalance($this->ann))->toBe(0.0);
});

it('no longer matches a national ID on POST /deposits', function () {
    Customer::factory()->create(['id_no' => '12345678', 'account_no' => 'KK-0000000000ABC']);

    $this->postJson('/api/v1/deposits', [
        'TransID' => 'BRF0000008',
        'TransactionType' => 'Pay Bill',
        'TransTime' => now()->toDateTimeString(),
        'TransAmount' => 100,
        'BusinessShortCode' => '12345',
        'BillRefNumber' => '12345678',
        'MSISDN' => '254712345678',
        'FirstName' => 'John',
    ], apiHeaders($this->apiKey->key))->assertStatus(404);
});

it('filters deposits by how they were matched', function () {
    payPaybill('0712345678', hash('sha256', '254799000000'), 'BRF0000009')->assertStatus(201);
    payPaybill('KK-6AA8B1DAAF392', $this->annPays, 'BRF0000010')->assertStatus(201);

    $this->getJson('/api/v1/deposits?matched_by=phone_other_payer', apiHeaders($this->apiKey->key))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.trans_id', 'BRF0000009')
        ->assertJsonPath('data.0.matched_by', 'phone_other_payer');
});

it('leaves a SHOP- ref whose shop number looks like a phone for the shop float', function () {
    payPaybill('SHOP-0712345678', hash('sha256', '254712345678'), 'BRF0000020')->assertOk();

    expect((int) Deposit::where('trans_id', 'BRF0000020')->sole()->status)->toBe(Deposit::STATUS_UNMATCHED)
        ->and(annBalance($this->ann))->toBe(0.0);
});
