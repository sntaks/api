<?php

use App\Models\BetShop;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\PromotionCredit;
use App\Models\ReferralWallet;
use App\Models\Wallet;
use App\Models\Withdraw;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Players (registered: alice 2026-09-01, carol 2026-09-01, bob 2026-10-05), on slots_fruit:
 *  - 2026-09-10 carol loses 20; 2026-09-15 alice loses 50 (both in the 30 days before the last 30);
 *  - 2026-10-05 bob registers, deposits 500 (his first deposit) and gets a 50 signup bonus;
 *  - 2026-10-14 bob wins a 20 spin (payout 30) from the web;
 *  - 2026-10-20 alice loses 200; bob wins 40 on a free spin of 10; alice is paid a 30 referral payout;
 *    payments that day: two STK pushes for alice (one completed in 60 s), a B2C withdrawal of 100 paid in
 *    5 minutes and one of 70 refused as an invalid account, a 25,000 withdrawal held 2 hours then approved and
 *    one still awaiting, a bet shop load of 300 and a shop cash-out of 80.
 * Requests are made on 2026-10-25 for 2026-10-01 to 2026-10-24.
 */
beforeEach(function () {
    Cache::flush();
    config(['stats.on_demand_days' => 100, 'stats.today_refresh_seconds' => 0, 'finance.test_customer_ids' => []]);
    $this->headers = apiHeaders(createApiKey('test-stats-players-key')->key);

    $this->travelTo('2026-09-01 09:00:00');
    $houseCustomer = Customer::factory()->create(['id' => 1]);
    Wallet::forceCreate(['id' => 1, 'customer_id' => $houseCustomer->id, 'balance' => 100000]);
    $this->alice = Customer::factory()->create(['status' => 1, 'name' => 'alice wanjiru']);
    $this->aliceWallet = Wallet::forceCreate(['customer_id' => $this->alice->id, 'balance' => 1000]);
    $this->carol = Customer::factory()->create(['status' => 1]);
    Wallet::forceCreate(['customer_id' => $this->carol->id, 'balance' => 1000]);
    $this->slots = statsProduct('slots', 'slots_fruit', 0);

    $this->travelTo('2026-09-10 12:00:00');
    statsPlay($this, $this->carol, $this->slots, 'carol-1', ['stake' => 20, 'outcome' => 'lost', 'payout' => 0]);
    $this->travelTo('2026-09-15 12:00:00');
    statsPlay($this, $this->alice, $this->slots, 'alice-1', ['stake' => 50, 'outcome' => 'lost', 'payout' => 0]);

    $this->travelTo('2026-10-05 08:00:00');
    $this->bob = Customer::factory()->create(['status' => 1, 'name' => 'Bob']);
    $this->bobWallet = Wallet::forceCreate(['customer_id' => $this->bob->id, 'balance' => 0]);
    $deposit = Deposit::factory()->create(['trans_amount' => 500, 'status' => Deposit::STATUS_COMPLETED, 'trans_time' => now()->format('YmdHis')]);
    app(LedgerService::class)->recordDeposit($deposit, $this->bobWallet->fresh(), 500);
    $this->bobWallet->update(['balance' => 500]);
    PromotionCredit::factory()->create(['customer_id' => $this->bob->id, 'wallet_id' => $this->bobWallet->id, 'gross_amount' => 50, 'net_amount' => 50]);

    $this->travelTo('2026-10-14 12:00:00');
    statsPlay($this, $this->bob, $this->slots, 'bob-1', ['stake' => 20, 'outcome' => 'won', 'payout' => 30, 'channel' => 'web']);

    $this->travelTo('2026-10-20 09:00:00');
    statsPlay($this, $this->alice, $this->slots, 'alice-2', ['stake' => 200, 'outcome' => 'lost', 'payout' => 0]);
    statsPlay($this, $this->bob, $this->slots, 'bob-free', ['funding' => 'free_bet', 'free_bet_amount' => 10, 'outcome' => 'won', 'payout' => 40]);

    DB::table('referral_withdrawals')->insert([
        'customer_id' => $this->alice->id, 'referral_wallet_id' => ReferralWallet::factory()->create(['customer_id' => $this->alice->id])->id,
        'amount' => 30, 'phone_no' => '254700000001', 'status' => 'completed', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('stk_pushes')->insert([
        ['customer_id' => $this->alice->id, 'amount' => 100, 'settled_at' => now()->addSeconds(60), 'created_at' => now()],
        ['customer_id' => $this->alice->id, 'amount' => 40, 'settled_at' => null, 'created_at' => now()],
    ]);

    $payout = function (float $amount, int $disburse, ?string $error, int $seconds) {
        $transaction = DB::table('transactions')->insertGetId(['wallet_id' => $this->aliceWallet->id, 'payment_type' => Withdraw::class, 'amount' => $amount, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('outgoing_payments')->insertGetId(['transaction_id' => $transaction, 'amount' => $amount, 'status' => 1, 'disburse' => $disburse, 'error_message' => $error,
            'created_at' => now(), 'updated_at' => now()->addSeconds($seconds)]);
    };
    $paid = $payout(100, 2, null, 300);
    $refused = $payout(70, 3, 'The initiator information is invalid.', 120);

    DB::table('withdrawal_approvals')->insert([
        ['withdraw_id' => $paid, 'customer_id' => $this->alice->id, 'amount' => 25000, 'phone_no' => '254700000001', 'status' => 'approved', 'decided_at' => now()->addHours(2), 'created_at' => now(), 'updated_at' => now()],
        ['withdraw_id' => $refused, 'customer_id' => $this->alice->id, 'amount' => 30000, 'phone_no' => '254700000001', 'status' => 'awaiting', 'decided_at' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $shop = BetShop::factory()->create();
    DB::table('bet_shop_loads')->insert(['load_id' => (string) Str::uuid(), 'bet_shop_id' => $shop->id, 'customer_id' => $this->alice->id, 'wallet_id' => $this->aliceWallet->id,
        'gross_amount' => 300, 'net_amount' => 300, 'status' => 'completed', 'idempotency_key' => 'load-1', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('shop_withdrawals')->insert(['withdrawal_id' => (string) Str::uuid(), 'bet_shop_id' => $shop->id, 'customer_id' => $this->alice->id, 'wallet_id' => $this->aliceWallet->id,
        'amount' => 80, 'pickup_code_hash' => 'x', 'expires_at' => now()->addDay(), 'status' => 'paid', 'closed_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);

    $this->travelTo('2026-10-25 12:00:00');
    $this->range = 'from=2026-10-01&to=2026-10-24';
});

it('reports registrations, first-deposit conversion and active players', function () {
    $players = statsGet($this, 'players', $this->range);

    expect($players['registrations']['total'])->toBe(1)
        ->and(collect($players['registrations']['series'])->firstWhere('date', '2026-10-05')['registrations'])->toBe(1)
        ->and($players['first_deposit_conversion'])->toMatchArray([
            'registered' => 1,
            'within_1_days' => ['eligible' => 1, 'converted' => 1, 'rate' => 100],
            'within_7_days' => ['eligible' => 1, 'converted' => 1, 'rate' => 100],
            // bob registered 20 days ago: not yet eligible for the 30-day window
            'within_30_days' => ['eligible' => 0, 'converted' => 0, 'rate' => null],
        ])
        ->and(collect($players['active']['daily'])->firstWhere('date', '2026-10-20')['players'])->toBe(2)
        ->and(collect($players['active']['weekly'])->firstWhere('date', '2026-10-12')['players'])->toBe(1)
        ->and($players['active']['monthly'])->toBe([['date' => '2026-10', 'players' => 2]]);
});

it('builds weekly retention cohorts, churn, stake bands and channels', function () {
    $players = statsGet($this, 'players', $this->range);
    $cohort = collect($players['retention_cohorts'])->sole();

    expect($cohort)->toMatchArray(['week' => '2026-10-05', 'registered' => 1])
        ->and($cohort['weeks']['week_1'])->toEqual(100)
        // week 2 (2026-10-19 to 10-25) is not over on 10-25
        ->and($cohort['weeks']['week_2'])->toBeNull()
        // carol played only in the 30 days before; alice came back
        ->and($players['churn'])->toMatchArray(['previous_active' => 2, 'churned' => 1, 'rate' => 50])
        ->and($players['stake_bands']['under_100'])->toEqual(['players' => 1, 'stakes' => 20])
        ->and($players['stake_bands']['100_1k'])->toEqual(['players' => 1, 'stakes' => 200])
        ->and(collect($players['channel'])->keyBy('channel')->map(fn ($row) => $row['entries'])->all())->toEqual(['unspecified' => 2, 'web' => 1]);
});

it('ranks top players by each measure, with the public display name', function () {
    $top = fn (string $sort) => statsGet($this, 'players/top', "{$this->range}&sort={$sort}")['players'];

    $byTurnover = $top('turnover');
    expect(collect($byTurnover)->pluck('customer_id')->all())->toBe([$this->alice->id, $this->bob->id])
        ->and($byTurnover[0])->toMatchArray([
            'player_no' => $this->alice->player_no, 'display_name' => 'Alice W.', 'turnover' => 200, 'payouts' => 0, 'net_loss' => 200, 'ggr' => 200,
            'wins' => 0, 'deposits' => 0, 'withdrawals' => 100, 'games_played' => 1, 'last_active' => '2026-10-20',
        ])
        ->and($byTurnover[1])->toMatchArray(['display_name' => 'Bob', 'turnover' => 20, 'payouts' => 30, 'ggr' => -10, 'wins' => 2, 'deposits' => 500, 'games_played' => 2])
        ->and(collect($top('wins'))->first()['customer_id'])->toBe($this->bob->id)
        ->and(collect($top('deposits'))->pluck('customer_id')->all())->toBe([$this->bob->id])
        ->and(collect($top('net_loss'))->first()['customer_id'])->toBe($this->alice->id)
        ->and(statsGet($this, 'players/top', "{$this->range}&limit=1")['players'])->toHaveCount(1);
});

it('costs promotions by type and product, and agrees with the income statement', function () {
    $promotions = statsGet($this, 'promotions', $this->range);
    $types = collect($promotions['by_type'])->keyBy('type');
    Cache::flush();
    $income = $this->getJson("/api/v1/finance/income-statement?{$this->range}", $this->headers)->assertOk()->json('data');
    $dashboard = collect(statsGet($this, 'dashboard', $this->range)['by_product'])->keyBy('product');

    expect($types['signup_bonus'])->toMatchArray(['amount' => 50, 'players' => 1, 'is_cost' => true, 'scope' => 'platform'])
        ->and($types['free_bet_winnings'])->toMatchArray(['amount' => 40, 'items' => 1, 'players' => 1, 'scope' => 'product'])
        ->and($types['free_bet_value'])->toMatchArray(['amount' => 10, 'is_cost' => false])
        ->and($types['referral_payout'])->toMatchArray(['amount' => 30, 'players' => 1])
        ->and($promotions['totals'])->toMatchArray(['cost' => 120, 'players_reached' => 2, 'active_players' => 2, 'cost_per_active_player' => 60])
        ->and($promotions['totals']['cost'])->toEqual(round($income['promotions'] + $income['wager_promotions'] + $income['referral_payouts'], 2))
        ->and(collect($promotions['by_product'])->sole())->toMatchArray(['product' => 'slots_fruit', 'cost' => $dashboard['slots_fruit']['promotions'], 'players' => 1]);

    // a product filter keeps only that product's promotions
    expect(collect(statsGet($this, 'promotions', "{$this->range}&product=slots_fruit")['by_type'])->pluck('type')->all())->toBe(['free_bet_value', 'free_bet_winnings']);
});

it('reports deposits, STK pushes, withdrawals, held withdrawals and bet shop cash', function () {
    $payments = statsGet($this, 'payments', $this->range);
    Cache::flush();
    $dashboard = statsGet($this, 'dashboard', $this->range);

    expect($payments['deposits'])->toMatchArray(['count' => 1, 'amount' => $dashboard['totals']['deposits'], 'average' => 500])
        ->and(collect($payments['deposits']['by_hour'])->firstWhere('hour', 8))->toMatchArray(['count' => 1, 'amount' => 500])
        ->and($payments['stk_push'])->toMatchArray(['count' => 2, 'success_rate' => 50, 'avg_seconds_to_complete' => 60])
        ->and($payments['withdrawals'])->toMatchArray([
            'requested' => ['count' => 2, 'amount' => 170],
            'paid' => ['count' => 1, 'amount' => 100, 'average' => 100],
            'failure_rate' => 50, 'avg_seconds_to_complete' => 210, 'pending_now' => ['count' => 0, 'amount' => 0],
        ])
        ->and($payments['withdrawals']['failed']['by_reason'])->toBe(['invalid_account' => ['count' => 1, 'amount' => 70]])
        ->and($payments['held_withdrawals'])->toMatchArray([
            'threshold' => 20000, 'requested' => ['count' => 2, 'amount' => 55000], 'avg_wait_seconds' => 7200,
        ])
        ->and($payments['held_withdrawals']['decided']['approved'])->toBe(['count' => 1, 'amount' => 25000])
        ->and($payments['held_withdrawals']['awaiting_now'])->toMatchArray(['count' => 1, 'amount' => 30000])
        ->and($payments['bet_shops'])->toMatchArray(['loads' => ['count' => 1, 'amount' => 300], 'cash_outs' => ['count' => 1, 'amount' => 80, 'avg_seconds_to_pay' => 600]]);
});

it('refuses a product filter on payments, which cover the whole platform', function () {
    $this->getJson("/api/v1/stats/payments?{$this->range}&game=slots", $this->headers)
        ->assertUnprocessable()->assertJsonPath('code', 'product_filter_not_supported');
    $this->getJson("/api/v1/stats/export/stats-payments?{$this->range}&game=slots", $this->headers)
        ->assertUnprocessable()->assertJsonPath('code', 'product_filter_not_supported');
});

it('exports the answers as CSV', function (string $report, string $header) {
    $response = $this->get("/api/v1/stats/export/{$report}?{$this->range}", $this->headers)->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and(explode("\n", ltrim($response->streamedContent(), "\xEF\xBB\xBF"))[0])->toBe($header);
})->with([
    'games' => ['stats-games', 'product,game,revenue_model,bets,players,voids,turnover,payouts,ggr,hold,rtp,avg_stake,free_bet_value,promotions,net,winnings_tax_withheld'],
    'top players' => ['stats-players-top', 'customer_id,player_no,display_name,turnover,payouts,net_loss,ggr,wins,deposits,withdrawals,games_played,last_active'],
    'promotions' => ['stats-promotions', 'type,scope,is_cost,amount,items,players'],
    'payments' => ['stats-payments', 'date,deposits,deposit_amount,withdrawals_paid,withdrawal_amount'],
]);

it('validates sort, limit and the export name', function () {
    $this->getJson('/api/v1/stats/players/top?sort=richest', $this->headers)->assertUnprocessable()->assertJsonValidationErrors('sort');
    $this->getJson('/api/v1/stats/players/top?limit=101', $this->headers)->assertUnprocessable()->assertJsonValidationErrors('limit');
    $this->getJson('/api/v1/stats/export/nope', $this->headers)->assertNotFound();
});

it('answers full keys only', function () {
    $site = createApiKey('stats-site-key-2');
    $site->update(['scope' => 'site']);

    foreach (['players', 'players/top', 'promotions', 'payments', 'export/stats-games'] as $path) {
        $this->getJson("/api/v1/stats/{$path}", apiHeaders($site->key))->assertForbidden();
        $this->getJson("/api/v1/stats/{$path}", apiHeaders($this->slots['key']->key))->assertForbidden();
    }
});
