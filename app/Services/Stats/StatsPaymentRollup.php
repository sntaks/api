<?php

namespace App\Services\Stats;

use App\Models\BetShopLoad;
use App\Models\Deposit;
use App\Models\ShopWithdrawal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Payment events of one day per hour, for /stats/payments. Each event is dated by the moment its outcome
 * was known, so a day's rows never change once the day is over: deposits when received, STK pushes when
 * sent (a push completes within minutes or never), B2C withdrawals when paid or failed, held withdrawals
 * when requested and when decided, bet shop loads when made and when reversed, cash-outs when paid.
 */
class StatsPaymentRollup
{
    public const KIND_DEPOSIT = 'deposit';

    public const KIND_STK_PUSH = 'stk_push';

    public const KIND_WITHDRAWAL_REQUEST = 'withdrawal_request';

    public const KIND_WITHDRAWAL = 'withdrawal';

    public const KIND_HELD_REQUEST = 'held_request';

    public const KIND_HELD_DECISION = 'held_decision';

    public const KIND_SHOP_LOAD = 'shop_load';

    public const KIND_SHOP_CASHOUT = 'shop_cashout';

    /** B2C failures by what M-Pesa said (only its description is stored, not the result code). */
    public const FAILURE_GROUPS = [
        'insufficient_funds' => '%insufficient%',
        'invalid_account' => ['%invalid%', '%not registered%', '%does not exist%', '%unknown%', '%restricted%'],
        'timeout' => ['%timeout%', '%timed out%', '%expired%'],
        'declined' => ['%declined%', '%reject%', '%cancel%'],
    ];

    /**
     * @param  array<int, int>  $testIds  Test customer ids (flipped).
     * @return array{payments: list<array<string, mixed>>, withdrawals: array<int, array{withdrawals: int, withdrawal_amount: float}>}
     */
    public function build(CarbonImmutable $day, array $testIds): array
    {
        $window = [$day->startOfDay(), $day->endOfDay()];
        $events = [
            ...$this->deposits($window),
            ...$this->stkPushes($window),
            ...$this->withdrawals($window),
            ...$this->held($window),
            ...$this->shops($window),
        ];

        $cells = [];
        $paidByCustomer = [];
        foreach ($events as $event) {
            $customerId = $event->customer_id === null ? null : (int) $event->customer_id;

            foreach ([true, false] as $excludesTest) {
                if ($excludesTest && $customerId !== null && isset($testIds[$customerId])) {
                    continue;
                }

                $key = implode(':', [(int) $excludesTest, $event->kind, $event->outcome, $event->hour]);
                $cells[$key] ??= ['hour' => (int) $event->hour, 'excludes_test' => $excludesTest, 'kind' => $event->kind, 'outcome' => $event->outcome,
                    'events' => 0, 'amount' => 0.0, 'seconds_total' => 0, 'seconds_count' => 0];
                $cells[$key]['events'] += (int) $event->events;
                $cells[$key]['amount'] += (float) $event->amount;
                $cells[$key]['seconds_total'] += (int) $event->seconds_total;
                $cells[$key]['seconds_count'] += (int) $event->seconds_count;
            }

            if ($event->kind === self::KIND_WITHDRAWAL && $event->outcome === 'paid' && $customerId !== null) {
                $paidByCustomer[$customerId] ??= ['withdrawals' => 0, 'withdrawal_amount' => 0.0];
                $paidByCustomer[$customerId]['withdrawals'] += (int) $event->events;
                $paidByCustomer[$customerId]['withdrawal_amount'] += (float) $event->amount;
            }
        }

        $date = $day->toDateString();

        return [
            'payments' => array_values(array_map(fn (array $cell) => ['date' => $date] + $cell + ['amount' => round($cell['amount'], 2)], $cells)),
            'withdrawals' => $paidByCustomer,
        ];
    }

    /**
     * Money received through the paybill for a wallet: credited (a wallet deposit or load) or unmatched.
     * House funding, bet shop float and gift or emoji purchases are left out, as in the dashboard deposits.
     *
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $window
     * @return list<object>
     */
    private function deposits(array $window): array
    {
        $customer = 'COALESCE(p.customer_id, l.customer_id)';

        return DB::query()->fromSub(DB::table('incoming_payments as i')
            ->leftJoin('purchases as p', 'p.deposit_id', '=', 'i.id')
            ->leftJoin('ledger_entries as l', fn ($join) => $join->on('l.referenceable_id', '=', 'i.id')->where('l.referenceable_type', Deposit::class)->where('l.entry_type', 'deposit'))
            ->leftJoin('house_fundings as h', fn ($join) => $join->on('h.deposit_id', '=', 'i.id')->whereNull('h.voided_at'))
            ->leftJoin('bet_shop_float_purchases as sf', fn ($join) => $join->on('sf.deposit_id', '=', 'i.id')->whereNull('sf.voided_at'))
            ->whereBetween('i.created_at', $window)
            ->whereNull('h.id')
            ->whereNull('sf.id')
            ->where(fn (Builder $query) => $query->whereNull('p.purchase_type')->orWhere('p.purchase_type', 'load')->orWhereIn('i.status', [0, 4]))
            ->selectRaw("HOUR(i.created_at) as hour, {$customer} as customer_id, CASE WHEN i.status IN (0, 4) THEN 'unmatched' ELSE 'credited' END as outcome, i.trans_amount as amount"), 'd')
            ->selectRaw("'".self::KIND_DEPOSIT."' as kind, hour, customer_id, outcome, COUNT(*) as events, SUM(amount) as amount, 0 as seconds_total, 0 as seconds_count")
            ->groupBy('hour', 'customer_id', 'outcome')
            ->get()
            ->all();
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $window
     * @return list<object>
     */
    private function stkPushes(array $window): array
    {
        $outcome = "CASE WHEN settled_at IS NULL THEN 'not_completed' ELSE 'completed' END";

        return DB::table('stk_pushes')
            ->whereBetween('created_at', $window)
            ->selectRaw("'".self::KIND_STK_PUSH."' as kind, HOUR(created_at) as hour, customer_id, {$outcome} as outcome, COUNT(*) as events, SUM(amount) as amount, COALESCE(SUM(TIMESTAMPDIFF(SECOND, created_at, settled_at)), 0) as seconds_total, SUM(settled_at IS NOT NULL) as seconds_count")
            ->groupByRaw("HOUR(created_at), customer_id, {$outcome}")
            ->get()
            ->all();
    }

    /**
     * B2C withdrawals: every request when it was made, and each payout when M-Pesa paid or refused it.
     *
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $window
     * @return list<object>
     */
    private function withdrawals(array $window): array
    {
        $base = fn () => DB::table('outgoing_payments as o')
            ->leftJoin('transactions as t', 't.id', '=', 'o.transaction_id')
            ->leftJoin('wallets as w', 'w.id', '=', 't.wallet_id');

        $requested = $base()->whereBetween('o.created_at', $window)
            ->selectRaw("'".self::KIND_WITHDRAWAL_REQUEST."' as kind, HOUR(o.created_at) as hour, w.customer_id, 'requested' as outcome, COUNT(*) as events, SUM(o.amount) as amount, 0 as seconds_total, 0 as seconds_count")
            ->groupByRaw('HOUR(o.created_at), w.customer_id')
            ->get();

        // The patterns are constants, written into the SQL so the GROUP BY repeats the SELECT expression exactly
        $group = "CASE WHEN o.disburse = 2 THEN 'paid'";
        foreach (self::FAILURE_GROUPS as $name => $patterns) {
            $likes = implode(' OR ', array_map(fn (string $pattern) => "LOWER(COALESCE(o.error_message, '')) LIKE '{$pattern}'", (array) $patterns));
            $group .= " WHEN {$likes} THEN 'failed_{$name}'";
        }
        $group .= " ELSE 'failed_other' END";

        $settled = $base()->whereIn('o.disburse', [2, 3])->whereBetween('o.updated_at', $window)
            ->selectRaw("'".self::KIND_WITHDRAWAL."' as kind, HOUR(o.updated_at) as hour, w.customer_id, {$group} as outcome, COUNT(*) as events, SUM(o.amount) as amount, SUM(TIMESTAMPDIFF(SECOND, o.created_at, o.updated_at)) as seconds_total, COUNT(*) as seconds_count")
            ->groupByRaw("HOUR(o.updated_at), w.customer_id, {$group}")
            ->get();

        return [...$requested->all(), ...$settled->all()];
    }

    /**
     * Withdrawals held for approval (at or above responsible_gambling.approval_threshold): when asked for, and
     * when approved, rejected or cancelled, with the wait.
     *
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $window
     * @return list<object>
     */
    private function held(array $window): array
    {
        $requested = DB::table('withdrawal_approvals')->whereBetween('created_at', $window)
            ->selectRaw("'".self::KIND_HELD_REQUEST."' as kind, HOUR(created_at) as hour, customer_id, 'requested' as outcome, COUNT(*) as events, SUM(amount) as amount, 0 as seconds_total, 0 as seconds_count")
            ->groupByRaw('HOUR(created_at), customer_id');

        $decided = DB::table('withdrawal_approvals')->whereNotNull('decided_at')->whereBetween('decided_at', $window)
            ->selectRaw("'".self::KIND_HELD_DECISION."' as kind, HOUR(decided_at) as hour, customer_id, status as outcome, COUNT(*) as events, SUM(amount) as amount, SUM(TIMESTAMPDIFF(SECOND, created_at, decided_at)) as seconds_total, COUNT(*) as seconds_count")
            ->groupByRaw('HOUR(decided_at), customer_id, status');

        return $requested->unionAll($decided)->get()->all();
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $window
     * @return list<object>
     */
    private function shops(array $window): array
    {
        $loads = DB::table('bet_shop_loads')->whereBetween('created_at', $window)
            ->selectRaw("'".self::KIND_SHOP_LOAD."' as kind, HOUR(created_at) as hour, customer_id, 'loaded' as outcome, COUNT(*) as events, SUM(gross_amount) as amount, 0 as seconds_total, 0 as seconds_count")
            ->groupByRaw('HOUR(created_at), customer_id');

        $reversed = DB::table('bet_shop_loads')->where('status', BetShopLoad::STATUS_REVERSED)->whereBetween('reversed_at', $window)
            ->selectRaw("'".self::KIND_SHOP_LOAD."' as kind, HOUR(reversed_at) as hour, customer_id, 'reversed' as outcome, COUNT(*) as events, SUM(gross_amount) as amount, 0 as seconds_total, 0 as seconds_count")
            ->groupByRaw('HOUR(reversed_at), customer_id');

        $cashOuts = DB::table('shop_withdrawals')->where('status', ShopWithdrawal::STATUS_PAID)->whereBetween('closed_at', $window)
            ->selectRaw("'".self::KIND_SHOP_CASHOUT."' as kind, HOUR(closed_at) as hour, customer_id, 'paid' as outcome, COUNT(*) as events, SUM(amount) as amount, SUM(TIMESTAMPDIFF(SECOND, created_at, closed_at)) as seconds_total, COUNT(*) as seconds_count")
            ->groupByRaw('HOUR(closed_at), customer_id');

        return $loads->unionAll($reversed)->unionAll($cashOuts)->get()->all();
    }
}
