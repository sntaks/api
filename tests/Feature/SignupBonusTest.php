<?php

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\ExciseDutyCharge;
use App\Models\Game;
use App\Models\GameTransaction;
use App\Models\GameWallet;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\PromotionCredit;
use App\Models\Wallet;
use App\Services\ExciseDutyService;
use App\Services\GameWalletService;
use App\Services\LedgerService;
use App\Services\PlayerTokenService;
use App\Services\SignupBonusService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $house = Customer::factory()->create(['phone_no' => '254700000001']);
    $this->houseWallet = Wallet::factory()->create(['customer_id' => $house->id, 'balance' => 1000]);

    config([
        'wallets.house_wallet_id' => $this->houseWallet->id,
        'promotions.signup_bonus.enabled' => true,
        'promotions.signup_bonus.net_amount' => 20,
        'promotions.signup_bonus.budget_cap' => null,
        'finance.excise_duty.enabled' => true,
        'finance.excise_duty.rate' => 0.05,
        'finance.excise_duty.effective_from' => null,
        'referrals.enabled' => true,
        'referrals.effective_from' => null,
    ]);

    $this->apiKey = createApiKey('test-signup-bonus-key');
    $this->headers = apiHeaders($this->apiKey->key);

    $this->promoCode = PromoCode::factory()->create(['code' => 'KADI20']);
    $this->customer = bonusCustomer('254711000001');
    $this->wallet = $this->customer->wallet;
});

/**
 * A customer who signed up with the test promo code (or $promoCode, or none when false).
 */
function bonusCustomer(string $phone, PromoCode|false|null $promoCode = null): Customer
{
    $promoCode ??= test()->promoCode;

    $customer = Customer::factory()->create(['phone_no' => $phone]);
    $customer->forceFill(['promo_code_id' => $promoCode === false ? null : $promoCode->id])->save();
    Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 0]);

    return $customer->fresh();
}

function signUp(string $accountNo, ?string $promoCode)
{
    return test()->postJson('/api/v1/customers', array_filter([
        'account_no' => $accountNo,
        'name' => 'New Player',
        'email' => $accountNo.'@example.com',
        'phone_no' => '254'.$accountNo,
        'promo_code' => $promoCode,
    ]), test()->headers);
}

function reportVerified(Customer $customer)
{
    return test()->postJson('/api/v1/customers/'.encryptId($customer->id).'/verified', [], test()->headers);
}

function stake(Customer $customer, float $amount): GameWallet
{
    $gameWallet = GameWallet::create(['game_id' => 'BONUS_'.uniqid(), 'game_type' => 1, 'balance' => 0]);
    $transaction = GameTransaction::create(['game_wallet_id' => $gameWallet->id, 'customer_id' => $customer->id, 'payment_type' => 'deposit', 'amount' => $amount, 'status' => 2]);
    app(LedgerService::class)->recordGameBet($transaction, Wallet::where('customer_id', $customer->id)->first(), $gameWallet, $amount);

    return $gameWallet;
}

function lockedBonus(Customer $customer): float
{
    return app(SignupBonusService::class)->lockedAmount($customer->id);
}

// amounts

it('grosses the bonus up so the customer keeps exactly the net amount', function (float $rate, float $gross, float $excise) {
    $amounts = app(ExciseDutyService::class)->grossUpForNet(20, $rate);

    expect($amounts)->toMatchArray(['gross' => $gross, 'excise' => $excise, 'net' => 20.0]);
})->with([
    '5%' => [0.05, 21.05, 1.05],
    '16%' => [0.16, 23.81, 3.81],
    '20%' => [0.20, 25.0, 5.0],
    'none' => [0.0, 20.0, 0.0],
]);

it('hits the net amount to the cent for any amount', function () {
    $excise = app(ExciseDutyService::class);

    foreach ([0.05, 0.16] as $rate) {
        foreach (range(1, 300) as $net) {
            expect($excise->grossUpForNet((float) $net, $rate)['net'])->toBe((float) $net);
        }
    }
});

it('credits the net amount without duty when excise duty is off', function () {
    config(['finance.excise_duty.enabled' => false]);

    reportVerified($this->customer)->assertOk()
        ->assertJsonPath('data.signup_bonus.gross_amount', 20)
        ->assertJsonPath('data.signup_bonus.excise_amount', 0);

    expect((float) $this->wallet->fresh()->balance)->toBe(20.0)
        ->and((float) $this->houseWallet->fresh()->balance)->toBe(980.0)
        ->and(ExciseDutyCharge::count())->toBe(0);
});

// granting

it('grants the bonus on verification, paid by the house and grossed up for excise', function () {
    reportVerified($this->customer)
        ->assertOk()
        ->assertJsonPath('data.referred', false)
        ->assertJsonPath('data.signup_bonus.gross_amount', 21.05)
        ->assertJsonPath('data.signup_bonus.excise_amount', 1.05)
        ->assertJsonPath('data.signup_bonus.net_amount', 20)
        ->assertJsonPath('data.signup_bonus.locked_amount', 20)
        ->assertJsonPath('data.signup_bonus.unlocked', false);

    $credit = PromotionCredit::sole();
    $charge = ExciseDutyCharge::sole();

    expect((float) $this->wallet->fresh()->balance)->toBe(20.0)
        ->and((float) $this->houseWallet->fresh()->balance)->toBe(978.95)
        ->and($this->customer->fresh()->phone_no_verified_at)->not->toBeNull()
        ->and($charge->deposit_id)->toBeNull()
        ->and($charge->promotion_credit_id)->toBe($credit->id)
        ->and((float) $charge->excise_amount)->toBe(1.05)
        ->and(LedgerEntry::where('entry_type', 'promo_credit')->count())->toBe(2)
        ->and((float) $credit->ledgerEntry->credit)->toBe(21.05)
        ->and((float) $credit->houseLedgerEntry->debit)->toBe(21.05);
});

it('locks the player wallet before the house wallet when granting', function () {
    $walletLocks = [];
    DB::listen(function (QueryExecuted $query) use (&$walletLocks) {
        if (preg_match('/^select .* from [`"]?wallets[`"]?.* for update$/i', $query->sql)) {
            $walletLocks[] = str_contains($query->sql, 'customer_id') ? 'player' : 'house';
        }
    });

    reportVerified($this->customer)->assertOk()->assertJsonPath('data.signup_bonus.net_amount', 20);

    expect(array_slice($walletLocks, 0, 2))->toBe(['player', 'house']);
});

it('grants the bonus once however often verification is reported', function () {
    reportVerified($this->customer)->assertOk();
    reportVerified($this->customer)->assertOk()->assertJsonPath('data.signup_bonus.net_amount', 20);
    $this->postJson('/api/v1/customers/'.encryptId($this->customer->id).'/referral/verified', [], $this->headers)->assertOk();

    expect(PromotionCredit::count())->toBe(1)
        ->and((float) $this->wallet->fresh()->balance)->toBe(20.0);
});

it('grants the bonus from the referral verification call too', function () {
    $this->postJson('/api/v1/customers/'.encryptId($this->customer->id).'/referral/verified', [], $this->headers)
        ->assertOk()
        ->assertJsonPath('referred', false)
        ->assertJsonPath('signup_bonus.net_amount', 20);
});

it('does not grant the bonus when the promotion is off', function () {
    config(['promotions.signup_bonus.enabled' => false]);

    reportVerified($this->customer)->assertOk()->assertJsonPath('data.signup_bonus', null);

    expect(PromotionCredit::count())->toBe(0);
});

// promo codes

it('only grants the bonus to customers who signed up with a promo code', function () {
    $noCode = bonusCustomer('254711000002', false);

    reportVerified($noCode)->assertOk()->assertJsonPath('data.signup_bonus', null);

    expect((float) $noCode->wallet->fresh()->balance)->toBe(0.0);
});

it('records a usable promo code at signup, in any case', function () {
    signUp('711000011', 'kadi20')->assertCreated()->assertJsonPath('promo_code_applied', true);

    $customer = Customer::where('account_no', '711000011')->sole();
    expect($customer->promo_code_id)->toBe($this->promoCode->id);

    reportVerified($customer)->assertOk()->assertJsonPath('data.signup_bonus.promo_code', 'KADI20');
});

it('still signs the player up when the promo code cannot be used', function (string $code) {
    PromoCode::factory()->expired()->create(['code' => 'OLD20']);
    PromoCode::factory()->deactivated()->create(['code' => 'OFF20']);

    signUp('711000012', $code)->assertCreated()->assertJsonPath('promo_code_applied', false);

    expect(Customer::where('account_no', '711000012')->sole()->promo_code_id)->toBeNull();
})->with(['unknown' => ['NOPE20'], 'expired' => ['OLD20'], 'deactivated' => ['OFF20']]);

it('ignores promo codes at signup while the promotion is off', function () {
    config(['promotions.signup_bonus.enabled' => false]);

    signUp('711000013', 'KADI20')->assertCreated()->assertJsonPath('promo_code_applied', false);
});

it('does not grant the bonus when the code expired before verification', function () {
    $this->promoCode->update(['expires_at' => now()->subMinute()]);

    reportVerified($this->customer)->assertOk()->assertJsonPath('data.signup_bonus', null);

    expect(PromotionCredit::count())->toBe(0);
});

it('does not grant the bonus when the code was deactivated before verification', function () {
    $this->promoCode->update(['deactivated_at' => now()]);

    reportVerified($this->customer)->assertOk()->assertJsonPath('data.signup_bonus', null);
});

it('stops a code paying bonuses once its redemptions run out', function () {
    $this->promoCode->update(['max_redemptions' => 1]);
    $second = bonusCustomer('254711000005');

    reportVerified($this->customer)->assertOk()->assertJsonPath('data.signup_bonus.net_amount', 20);
    reportVerified($second)->assertOk()->assertJsonPath('data.signup_bonus', null);

    $this->getJson('/api/v1/promo-codes/lookup?code=KADI20', $this->headers)->assertNotFound();
});

it('tells the signup screen whether a code can be used', function () {
    $this->getJson('/api/v1/promo-codes/lookup?code=kadi20', $this->headers)
        ->assertOk()
        ->assertJsonPath('data.code', 'KADI20');

    $this->getJson('/api/v1/promo-codes/lookup?code=NOPE', $this->headers)->assertNotFound();
});

it('lets the GMS create, list and deactivate promo codes', function () {
    $this->postJson('/api/v1/promo-codes', ['code' => 'launch-oct', 'expires_at' => now()->addDays(10)->format('Y-m-d H:i'), 'max_redemptions' => 500, 'note' => 'October launch'], $this->headers)
        ->assertCreated()
        ->assertJsonPath('data.code', 'LAUNCH-OCT')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.remaining', 500)
        ->assertJsonPath('data.created_by', 'api_key:'.$this->apiKey->id);

    $this->postJson('/api/v1/promo-codes', ['code' => 'LAUNCH-OCT', 'expires_at' => now()->addDay()->toDateTimeString()], $this->headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
    $this->postJson('/api/v1/promo-codes', ['code' => 'PAST20', 'expires_at' => now()->subDay()->toDateTimeString()], $this->headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expires_at');

    reportVerified($this->customer);

    $items = $this->getJson('/api/v1/promo-codes?status=active', $this->headers)->assertOk()->json('data.items');
    expect(collect($items)->firstWhere('code', 'KADI20'))->toMatchArray(['signups' => 1, 'redemptions' => 1]);

    $launch = PromoCode::where('code', 'LAUNCH-OCT')->sole();
    $this->postJson('/api/v1/promo-codes/'.encryptId($launch->id).'/deactivate', [], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.status', 'deactivated');
    $this->postJson('/api/v1/promo-codes/'.encryptId($launch->id).'/deactivate', [], $this->headers)->assertStatus(409);

    expect(array_column($this->getJson('/api/v1/promo-codes?status=deactivated', $this->headers)->json('data.items'), 'code'))->toBe(['LAUNCH-OCT']);
});

it('gives the bonus once per phone number, whatever form it is stored in', function () {
    reportVerified($this->customer)->assertOk();

    $sameNumber = bonusCustomer('0711000001');

    reportVerified($sameNumber)->assertOk()->assertJsonPath('data.signup_bonus', null);

    expect(PromotionCredit::count())->toBe(1);
});

it('stops granting once the budget cap is reached', function () {
    config(['promotions.signup_bonus.budget_cap' => 30]);

    reportVerified($this->customer)->assertOk()->assertJsonPath('data.signup_bonus.gross_amount', 21.05);
    reportVerified(bonusCustomer('254711000003'))->assertOk()->assertJsonPath('data.signup_bonus', null);

    expect(PromotionCredit::count())->toBe(1);
});

it('does not grant the bonus when the house wallet cannot pay for it', function () {
    $this->houseWallet->update(['balance' => 10]);

    reportVerified($this->customer)->assertOk()->assertJsonPath('data.signup_bonus', null);

    expect((float) $this->houseWallet->fresh()->balance)->toBe(10.0);
});

// the lock

it('keeps the bonus locked until it has been staked, not counting refunded stakes', function () {
    reportVerified($this->customer);

    expect(lockedBonus($this->customer))->toBe(20.0);

    $gameWallet = stake($this->customer, 15);
    expect(lockedBonus($this->customer))->toBe(5.0);

    app(GameWalletService::class)->processRefund($gameWallet, $this->customer->id, 15);
    expect(lockedBonus($this->customer))->toBe(20.0);

    stake($this->customer, 20);
    expect(lockedBonus($this->customer))->toBe(0.0);
});

it('counts a cash wager toward the signup bonus and ignores a free bet', function () {
    reportVerified($this->customer);
    $this->customer->forceFill(['status' => 1])->save();

    $game = Game::factory()->create(['code' => 'crash']);
    $gameKey = ApiKey::create(['key' => 'game_bonus_key', 'name' => 'crash', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $game->id, 'is_active' => true]);
    Product::factory()->create(['game_id' => $game->id, 'code' => 'crash_classic']);
    $headers = apiHeaders($gameKey->key) + ['Authorization' => 'Bearer '.app(PlayerTokenService::class)->issue($this->customer, $game)->plainTextToken];

    $place = fn (string $reference, array $money) => $this->postJson('/api/v1/wagers', array_merge(['product' => 'crash_classic', 'reference' => $reference], $money), $headers)->assertSuccessful();
    $settle = fn (string $reference, string $outcome) => $this->postJson("/api/v1/wagers/{$reference}/settle", ['product' => 'crash_classic', 'version' => 1, 'outcome' => $outcome, 'payout' => 0, 'bonus' => 0], apiHeaders($gameKey->key))->assertSuccessful();

    expect(lockedBonus($this->customer))->toBe(20.0);

    $place('cash-1', ['funding' => 'cash', 'stake' => 8]);
    expect(lockedBonus($this->customer))->toBe(12.0);

    $place('free-1', ['funding' => 'free_bet', 'free_bet_amount' => 50]);
    expect(lockedBonus($this->customer))->toBe(12.0);

    $place('cash-2', ['funding' => 'cash', 'stake' => 5]);
    expect(lockedBonus($this->customer))->toBe(7.0);

    $settle('cash-2', 'void');
    expect(lockedBonus($this->customer))->toBe(12.0);

    $settle('cash-1', 'lost');
    $settle('free-1', 'lost');
    expect(lockedBonus($this->customer))->toBe(12.0);
});

it('refuses a withdrawal that would take locked bonus', function () {
    reportVerified($this->customer);
    // 40 of the player's own money on top of the 20 locked bonus: a withdrawal of 50 (the minimum) would take 10 of the bonus.
    app(LedgerService::class)->recordAdjustment($this->wallet->fresh(), 40, 'test top-up');

    $this->postJson('/api/v1/withdraw/'.encryptId($this->customer->id), ['amount' => 50], $this->headers)
        ->assertStatus(400)
        ->assertJsonPath('code', 'signup_bonus_locked')
        ->assertJsonPath('locked_amount', 20)
        ->assertJsonPath('available', 40);

    expect((float) $this->wallet->fresh()->balance)->toBe(60.0);
});

it('lets the customer move money above the locked bonus', function () {
    reportVerified($this->customer);
    app(LedgerService::class)->recordAdjustment($this->wallet->fresh(), 100, 'test top-up');

    $wallet = $this->wallet->fresh();
    $bonus = app(SignupBonusService::class);

    expect($bonus->wouldSpendLockedBonus($wallet, 100))->toBeFalse()
        ->and($bonus->wouldSpendLockedBonus($wallet, 100.01))->toBeTrue();
});

it('refuses a wallet transfer or coin purchase that would take locked bonus', function () {
    reportVerified($this->customer);
    $other = bonusCustomer('254711000004');

    $this->postJson('/api/v1/wallets/transfer/'.encryptId($this->wallet->id), ['amount' => 10, 'wallet_id' => $other->wallet->id], $this->headers)
        ->assertStatus(400)
        ->assertJsonPath('code', 'signup_bonus_locked');

    $this->postJson('/api/v1/coins/buy/'.encryptId($this->customer->id), ['amount' => 10], $this->headers)
        ->assertStatus(400)
        ->assertJsonPath('code', 'signup_bonus_locked');

    expect((float) $this->wallet->fresh()->balance)->toBe(20.0)
        ->and((float) $other->wallet->fresh()->balance)->toBe(0.0);
});

it('shows the customer their promotions and what is still locked', function () {
    reportVerified($this->customer);
    stake($this->customer, 5);

    $this->getJson('/api/v1/customers/'.encryptId($this->customer->id).'/promotions', $this->headers)
        ->assertOk()
        ->assertJsonPath('data.locked_amount', 15)
        ->assertJsonPath('data.items.0.promotion', 'signup_bonus')
        ->assertJsonPath('data.items.0.wagered', 5)
        ->assertJsonPath('data.items.0.locked_amount', 15);
});

// finance

it('reports the bonus as a promotions expense with its excise owed to KRA', function () {
    reportVerified($this->customer);
    Cache::flush();

    $statement = $this->getJson('/api/v1/finance/income-statement', $this->headers)->assertOk()->json('data');
    expect($statement['promotions'])->toEqual(21.05)
        ->and($statement['net_income'])->toEqual(-21.05);

    $trial = $this->getJson('/api/v1/finance/trial-balance', $this->headers)->assertOk()->json('data');
    expect($trial['check']['balanced'])->toBeTrue();

    $checks = collect($this->getJson('/api/v1/finance/reconciliation', $this->headers)->assertOk()->json('data.checks'))->keyBy('key');
    expect($checks['promotion_credits_ledger']['status'])->toBe('pass')
        ->and($checks['excise_duty_ledger']['status'])->toBe('pass')
        ->and($checks['ledger_balance']['status'])->toBe('pass')
        ->and($checks['negative_balances']['status'])->toBe('pass');

    $charges = $this->getJson('/api/v1/finance/excise-duty/charges', $this->headers)->assertOk()->json('data');
    expect($charges['items'][0])->toMatchArray(['source' => 'promotion', 'deposit_id' => null, 'excise_amount' => 1.05]);

    $this->getJson('/api/v1/finance/excise-duty', $this->headers)->assertOk()->assertJsonPath('data.payable.outstanding', 1.05);

    $promotions = $this->getJson('/api/v1/finance/promotions', $this->headers)->assertOk()->json('data');
    expect($promotions['summary'])->toMatchArray(['credits' => 1, 'gross_amount' => 21.05, 'excise_amount' => 1.05, 'net_amount' => 20])
        ->and($promotions['items'][0])->toMatchArray(['customer_id' => $this->customer->id, 'promo_code' => 'KADI20']);

    expect($this->getJson('/api/v1/finance/promotions?kind=kadi20', $this->headers)->json('data.summary.credits'))->toBe(1)
        ->and($this->getJson('/api/v1/finance/promotions?kind=OTHER', $this->headers)->json('data.summary.credits'))->toBe(0);

    $this->get('/api/v1/finance/export/promotions', $this->headers)->assertOk();
});

it('flags a promotion credit that does not match the ledger', function () {
    reportVerified($this->customer);
    PromotionCredit::sole()->houseLedgerEntry->update(['debit' => 5]);
    Cache::flush();

    $check = collect($this->getJson('/api/v1/finance/reconciliation', $this->headers)->assertOk()->json('data.checks'))->firstWhere('key', 'promotion_credits_ledger');

    expect($check['status'])->toBe('fail')
        ->and($check['count'])->toBe(1);
});
