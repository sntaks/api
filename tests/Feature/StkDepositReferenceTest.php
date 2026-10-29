<?php

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\Wallet;
use App\Services\MpesaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    createApiKey();
    $this->customer = Customer::factory()->create(['account_no' => 'KK-6AA8B1DAAF392', 'phone_no' => '254712345678', 'id_no' => null]);
});

/**
 * Start an STK deposit with M-Pesa mocked, returning the params sent to stkPush.
 *
 * @return array<string, mixed>
 */
function startStkDeposit(Customer $customer, int $amount = 100): array
{
    $sent = [];

    test()->mock(MpesaService::class)
        ->shouldReceive('stkPush')
        ->once()
        ->andReturnUsing(function (array $params) use (&$sent) {
            $sent = $params;

            return ['ResponseCode' => '0'];
        });

    test()->postJson('/api/v1/deposits/'.encryptId($customer->id), ['amount' => $amount], apiHeaders())->assertCreated();

    return $sent;
}

it('sends the player number as the STK account reference', function () {
    expect(startStkDeposit($this->customer)['AccountReference'])->toBe($this->customer->fresh()->player_no);
});

it('keeps the player number after the player saves a national ID', function () {
    $this->customer->update(['id_no' => '12345678']);

    expect(startStkDeposit($this->customer)['AccountReference'])->toBe($this->customer->fresh()->player_no);
});

it('credits the STK payment when its confirmation arrives', function () {
    $reference = startStkDeposit($this->customer)['AccountReference'];

    payPaybill($reference, hash('sha256', '254712345678'), 'STK0000001')->assertStatus(201);

    expect(Deposit::where('trans_id', 'STK0000001')->sole()->matched_by)->toBe('player_no')
        ->and((float) Wallet::where('customer_id', $this->customer->id)->sole()->balance)->toBeGreaterThan(0.0);
});
