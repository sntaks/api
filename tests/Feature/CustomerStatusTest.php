<?php

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Game;
use App\Services\PlayerTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->gms = createApiKey('gms-key');
    $this->player = Customer::factory()->create(['status' => 1]);
});

function changeStatus(object $test, array $body, ?ApiKey $key = null)
{
    return $test->putJson('/api/v1/customers/'.encryptId($test->player->id).'/status', $body, apiHeaders(($key ?? $test->gms)->key));
}

it('records who changed a status and why', function () {
    changeStatus($this, ['status' => 2, 'reason' => 'Chargeback on M-Pesa', 'admin_user' => 'ann'])
        ->assertOk()
        ->assertJsonPath('data.status', 2)
        ->assertJsonPath('data.previous_status', 1);

    expect($this->player->fresh()->status)->toBe(2);

    $row = DB::table('customer_status_changes')->sole();
    expect($row->customer_id)->toBe($this->player->id)
        ->and($row->from_status)->toBe(1)
        ->and($row->to_status)->toBe(2)
        ->and($row->reason)->toBe('Chargeback on M-Pesa')
        ->and($row->admin_user)->toBe('ann')
        ->and($row->actor)->toBe("api_key:{$this->gms->id}");
});

it('audits a status change made through the customer update route', function () {
    $this->putJson('/api/v1/customers/'.encryptId($this->player->id), ['status' => 5], apiHeaders($this->gms->key))->assertOk();

    $row = DB::table('customer_status_changes')->sole();
    expect($row->from_status)->toBe(1)
        ->and($row->to_status)->toBe(5)
        ->and($row->reason)->toBeNull()
        ->and($row->actor)->toBe("api_key:{$this->gms->id}");
});

it('records nothing when the status does not change', function () {
    $this->putJson('/api/v1/customers/'.encryptId($this->player->id), ['status' => 1, 'name' => 'Jane Doe'], apiHeaders($this->gms->key))->assertOk();
    changeStatus($this, ['status' => 1, 'reason' => 'no change', 'admin_user' => 'ann'])->assertOk();

    expect(DB::table('customer_status_changes')->count())->toBe(0);
});

it('ends game sessions on a status change through the status route', function () {
    app(PlayerTokenService::class)->issue($this->player, Game::factory()->create(['code' => 'crash']));

    changeStatus($this, ['status' => 2, 'reason' => 'Suspected fraud', 'admin_user' => 'ann'])->assertOk();

    expect($this->player->tokens()->count())->toBe(0);
});

it('lists a customer\'s status changes, newest first', function () {
    changeStatus($this, ['status' => 2, 'reason' => 'first', 'admin_user' => 'ann'])->assertOk();
    $this->travel(1)->minutes();
    changeStatus($this, ['status' => 1, 'reason' => 'second', 'admin_user' => 'ben'])->assertOk();

    $this->getJson('/api/v1/customers/'.encryptId($this->player->id).'/status-changes', apiHeaders($this->gms->key))
        ->assertOk()
        ->assertJsonPath('data.items.0.reason', 'second')
        ->assertJsonPath('data.items.1.reason', 'first')
        ->assertJsonPath('data.pagination.total', 2);
});

it('validates a status change', function (array $body, string $field) {
    changeStatus($this, $body)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'unknown status' => [['status' => 7, 'reason' => 'x reason', 'admin_user' => 'ann'], 'status'],
    'no reason' => [['status' => 2, 'admin_user' => 'ann'], 'reason'],
    'no admin user' => [['status' => 2, 'reason' => 'a reason'], 'admin_user'],
]);

it('keeps the status routes off the site key', function () {
    $site = ApiKey::create(['key' => 'site_test_key', 'name' => 'kadi-site', 'scope' => ApiKey::SCOPE_SITE, 'is_active' => true]);

    changeStatus($this, ['status' => 2, 'reason' => 'self service', 'admin_user' => 'x'], $site)
        ->assertForbidden()
        ->assertJsonPath('code', 'route_not_allowed');
});
