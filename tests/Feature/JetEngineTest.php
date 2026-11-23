<?php

use App\Domain\Jet\JetException;
use App\Domain\Jet\RoundRecoveryService;
use App\Domain\Jet\RoundService;
use App\Domain\Jet\SettlementService;
use App\Models\Customer;
use App\Models\JetBet;
use App\Models\JetRound;
use App\Models\Product;
use App\Models\Wager;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpJet($this);
    $this->travelTo('2026-11-23 12:00:00');
    $this->player = Customer::factory()->create(['status' => 1]);
    Wallet::forceCreate(['customer_id' => $this->player->id, 'balance' => 1000]);
});

function jetError(callable $command): string
{
    try {
        $command();
    } catch (JetException $e) {
        return $e->errorCode;
    }

    throw new RuntimeException('Expected a JetException');
}

/**
 * Closes the window, starts the flight and moves the clock $seconds into it.
 */
function jetFly(JetRound $round, int $seconds): JetRound
{
    test()->travelTo($round->betting_closes_at);
    $round = app(RoundService::class)->startRound($round);
    test()->travel($seconds)->seconds();

    return $round;
}

function jetCrashAndSettle(JetRound $round): JetRound
{
    return app(SettlementService::class)->settle(app(RoundService::class)->crashRound($round));
}

it('replays a place with the same key and body, and refuses a changed body', function () {
    $round = jetBettingRound();

    $first = jetPlace($this->player, $round, '100.00', null, 'key-1');
    $replay = jetPlace($this->player, $round, '100.00', null, 'key-1');

    expect($replay)->toEqual($first)
        ->and(Wager::count())->toBe(1)
        ->and(JetBet::count())->toBe(1)
        ->and(jetBalance($this->player))->toBe('900.00')
        ->and(jetError(fn () => jetPlace($this->player, $round, '200.00', null, 'key-1')))->toBe('IDEMPOTENCY_CONFLICT')
        ->and(jetBalance($this->player))->toBe('900.00');
});

it('refuses a place the player cannot fund or above the stake limit, leaving no bet, wager or operation', function (string $stake, string $code) {
    $round = jetBettingRound();

    expect(jetError(fn () => jetPlace($this->player, $round, $stake)))->toBe($code)
        ->and(Wager::count())->toBe(0)
        ->and(JetBet::count())->toBe(0)
        ->and(DB::table('jet_operations')->count())->toBe(0)
        ->and(jetBalance($this->player))->toBe('1000.00');
})->with([
    'insufficient funds' => ['1500.00', 'INSUFFICIENT_FUNDS'],
    'above max stake' => ['2500.00', 'LIMIT_EXCEEDED'],
    'below min stake' => ['5.00', 'LIMIT_EXCEEDED'],
]);

it('refuses a place once the betting window has closed', function () {
    $round = jetBettingRound();
    $this->travelTo($round->betting_closes_at);

    expect(jetError(fn () => jetPlace($this->player, $round, '100.00')))->toBe('ROUND_CLOSED')
        ->and(Wager::count())->toBe(0);
});

it('pays a manual cash-out once, at the server multiplier, with winnings tax on the profit', function () {
    $round = jetBettingRound('2.00');
    $bet = jetPlace($this->player, $round, '100.00')['bet'];
    jetFly($round, 1);

    $receipt = jetCashOut($this->player, $bet['id']);

    // e^(0.25 × 1) = 1.2840…: gross 128.40, tax 20% × 28.40 = 5.68, net 122.72
    expect($receipt['bet'])->toMatchArray(['status' => 'won', 'cashout_multiplier' => '1.28', 'gross_payout' => '128.40', 'tax' => '5.68', 'payout' => '122.72'])
        ->and(jetBalance($this->player))->toBe('1022.72')
        ->and(jetError(fn () => jetCashOut($this->player, $bet['id'])))->toBe('ALREADY_SETTLED')
        ->and(jetBalance($this->player))->toBe('1022.72');
});

it('settles auto cash-outs: strictly below the crash wins at the target, exactly at the crash loses', function () {
    $round = jetBettingRound('2.00');
    $below = jetPlace($this->player, $round, '100.00', '1.99')['bet'];
    $atCrash = jetPlace($this->player, $round, '100.00', '2.00')['bet'];
    $round = jetFly($round, 3);

    jetCrashAndSettle($round);

    // 199.00 gross, tax 20% × 99 = 19.80, net 179.20; the other stake is lost
    expect(JetBet::find($below['id']))->status->toBe(JetBet::STATUS_WON)->cashout_multiplier->toBe('1.99')->net_payout->toBe('179.20')
        ->and(JetBet::find($atCrash['id']))->status->toBe(JetBet::STATUS_LOST)
        ->and(Wager::where('reference', $atCrash['bet_reference'])->value('status'))->toBe(Wager::STATUS_LOST)
        ->and(jetBalance($this->player))->toBe('979.20');
});

it('settles a crashed round exactly once, even when settlement runs again', function () {
    $round = jetBettingRound('3.00');
    jetPlace($this->player, $round, '100.00', '1.50');
    $round = app(RoundService::class)->crashRound(jetFly($round, 5));

    $settled = app(SettlementService::class)->settle($round);
    DB::table('jet_rounds')->where('id', $round->id)->update(['status' => JetRound::STATUS_CRASHED]);
    app(SettlementService::class)->settle($round->fresh());

    // 150.00 gross, tax 20% × 50 = 10, net 140 credited once
    expect($settled->status)->toBe(JetRound::STATUS_SETTLED)
        ->and(jetBalance($this->player))->toBe('1040.00')
        ->and(DB::table('wager_settlements')->count())->toBe(1);
});

it('caps a bet with no auto target at the product max payout', function () {
    $this->jetProduct->update(['max_payout' => 300]);
    $round = jetBettingRound('5.00');
    $bet = jetPlace($this->player, $round, '100.00')['bet'];

    jetCrashAndSettle(jetFly($round, 10));

    // Capped at 300 / 100 = 3.00x: gross 300, tax 20% × 200 = 40, net 260
    expect($bet['cap_multiplier'])->toBe('3.00')
        ->and(JetBet::find($bet['id']))->status->toBe(JetBet::STATUS_WON)->payout->toBe('300.00')->net_payout->toBe('260.00')
        ->and(jetBalance($this->player))->toBe('1160.00');
});

it('voids a broken round: open bets are refunded and a cash-out keeps its win', function () {
    $other = Customer::factory()->create(['status' => 1]);
    Wallet::forceCreate(['customer_id' => $other->id, 'balance' => 1000]);
    $round = jetBettingRound('5.00');
    $open = jetPlace($this->player, $round, '100.00')['bet'];
    $cashed = jetPlace($other, $round, '100.00')['bet'];
    $round = jetFly($round, 1);
    jetCashOut($other, $cashed['id']);

    $voided = app(SettlementService::class)->void($round, 'Loop stalled', 'ops');

    expect($voided->status)->toBe(JetRound::STATUS_VOIDED)
        ->and(JetBet::find($open['id'])->status)->toBe(JetBet::STATUS_VOID)
        ->and(jetBalance($this->player))->toBe('1000.00')
        ->and(JetBet::find($cashed['id'])->status)->toBe(JetBet::STATUS_WON)
        ->and(jetBalance($other))->toBe('1022.72')
        ->and(jetError(fn () => app(SettlementService::class)->void($voided, 'again', 'ops')))->toBe('ALREADY_SETTLED');
});

it('reports jet_classic as a house-banked product in finance', function () {
    $round = jetBettingRound('2.00');
    jetPlace($this->player, $round, '100.00', '1.50');
    jetPlace($this->player, $round, '100.00');
    jetCrashAndSettle(jetFly($round, 3));
    Cache::flush();

    $report = $this->getJson('/api/v1/finance/house-banked?from=2026-11-23&to=2026-11-23', apiHeaders(createApiKey('finance-key')->key))
        ->assertOk()->json('data');
    $row = collect($report['by_product'])->firstWhere('product', 'jet_classic');

    // Turnover 200; the 1.50x target wins 150 gross, the bet with no target (capped far above 2.00x) loses
    expect($row)->not->toBeNull()
        ->and((float) $row['turnover'])->toBe(200.0)
        ->and((float) $row['payouts'])->toBe(150.0);
});

it('runs a round from creation to settlement, and starts none while jet_classic is suspended', function () {
    $recovery = app(RoundRecoveryService::class);
    $recovery->acquire();
    $this->jetProduct->update(['status' => Product::STATUS_SUSPENDED]);

    expect($recovery->tick(true))->toBeNull()
        ->and(JetRound::count())->toBe(0);

    $this->jetProduct->update(['status' => Product::STATUS_ACTIVE]);
    $round = $recovery->tick(true);
    expect($round->status)->toBe(JetRound::STATUS_BETTING);
    $round->forceFill(['crash_multiplier' => '2.00'])->save();

    // Each clock jump outlives the 15 s lease, so the loop takes it again, as after a restart
    $this->travelTo($round->betting_closes_at);
    $recovery->release();
    $recovery->acquire();
    expect($recovery->tick()->status)->toBe(JetRound::STATUS_RUNNING);

    $this->travel(3600)->seconds();
    $recovery->release();
    $recovery->acquire();

    expect($recovery->tick()->status)->toBe(JetRound::STATUS_SETTLED);
});
