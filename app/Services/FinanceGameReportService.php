<?php

namespace App\Services;

use App\Models\CompetitionTransaction;
use App\Models\Complaint;
use App\Services\Concerns\BuildsFinanceQueries;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Drill-downs into single games and competitions: what was staked, what the house took and what
 * was paid out or is still held.
 */
class FinanceGameReportService
{
    use BuildsFinanceQueries;

    /** Customer id the game system uses for the house on game transactions. */
    private const HOUSE_CUSTOMER_ID = 1;

    /** Values of the open wallets report's `kind` filter. */
    private const OPEN_WALLET_KINDS = ['game', 'tournament', 'jackpot'];

    /**
     * One row per game, dated by when the game wallet was created.
     *
     * house_take is stakes minus what players were paid and refunded minus anything still in the game
     * wallet, so it is only shown once the game is closed. A negative take means players were paid
     * or refunded more than they staked.
     *
     * Filters: outcome (open, completed, dropped, refunded, closed_empty), game_type, players.
     * `exclude_test` drops games any test customer staked in.
     *
     * @param  array<string, string>  $filters
     */
    public function games(FinanceDateRange $range, array $filters): FinanceListing
    {
        $base = fn () => $this->gameBase($range, $filters);

        $query = $base()->orderByDesc('x.id');

        $summary = function () use ($base) {
            $totals = 'COUNT(*) as games, COALESCE(SUM(x.stakes), 0) as stakes, COALESCE(SUM(x.paid_to_players), 0) as paid_to_players, COALESCE(SUM(x.refunded), 0) as refunded, COALESCE(SUM(x.house_take), 0) as house_take';

            $byOutcome = DB::query()->fromSub($base()->select('x.*'), 'y')
                ->selectRaw('y.outcome as `group`, COUNT(*) as games, COALESCE(SUM(y.stakes), 0) as stakes, COALESCE(SUM(y.paid_to_players), 0) as paid_to_players, COALESCE(SUM(y.refunded), 0) as refunded, COALESCE(SUM(y.house_take), 0) as house_take')
                ->groupBy('y.outcome')->get();

            $byPlayers = DB::query()->fromSub($base()->select('x.*'), 'y')
                ->selectRaw('y.players as `group`, COUNT(*) as games, COALESCE(SUM(y.stakes), 0) as stakes, COALESCE(SUM(y.paid_to_players), 0) as paid_to_players, COALESCE(SUM(y.refunded), 0) as refunded, COALESCE(SUM(y.house_take), 0) as house_take')
                ->groupBy('y.players')->orderBy('y.players')->get();

            $all = $base()->selectRaw($totals)->first();

            $shape = fn ($row) => [
                'games' => (int) $row->games,
                'stakes' => $this->money($row->stakes),
                'paid_to_players' => $this->money($row->paid_to_players),
                'refunded' => $this->money($row->refunded),
                'house_take' => $this->money($row->house_take),
            ];

            return $shape($all) + [
                'by_outcome' => $byOutcome->mapWithKeys(fn ($row) => [$row->group => $shape($row)])->all(),
                'by_players' => $byPlayers->mapWithKeys(fn ($row) => [(string) $row->group => $shape($row)])->all(),
            ];
        };

        return new FinanceListing(
            $query->select('x.*'),
            fn (object $row) => [
                'id' => (int) $row->id,
                'game_id' => $row->game_id,
                'game_type' => (int) $row->game_type,
                'outcome' => $row->outcome,
                'players' => (int) $row->players,
                'stakes' => $this->money($row->stakes),
                'paid_to_players' => $this->money($row->paid_to_players),
                'house_payout' => $this->money($row->house_payout),
                'refunded' => $this->money($row->refunded),
                'escrow_balance' => $this->money($row->balance),
                'house_take' => $row->house_take === null ? null : $this->money($row->house_take),
                'created_at' => $this->moment($row->created_at),
            ],
            $summary,
            ['id', 'game_id', 'game_type', 'outcome', 'players', 'stakes', 'paid_to_players', 'house_payout', 'refunded', 'escrow_balance', 'house_take', 'created_at'],
        );
    }

    /**
     * One row per competition (all its players' wallets together), dated by when the first wallet was created.
     *
     * pool is entries minus the house cut, and unaccounted is pool minus prizes paid minus what is
     * still held in open wallets. The house cut is only known for entries linked to their ledger
     * cut (from the Phase 0 fixes onward), so older competitions show the whole entry as pool.
     *
     * Filters: game_type (1 tournament, 2 jackpot), jp_rounds.
     * `exclude_test` drops competitions any test customer played in.
     *
     * @param  array<string, string>  $filters
     */
    public function competitions(FinanceDateRange $range, array $filters): FinanceListing
    {
        $base = fn () => $this->competitionBase($range, $filters);

        $query = $base()->orderByRaw('MIN(cw.id) DESC');

        $summary = function () use ($base) {
            $rows = DB::query()->fromSub($base(), 'k')
                ->selectRaw('k.game_type, k.jp_rounds, COUNT(*) as competitions, COALESCE(SUM(k.players), 0) as players, COALESCE(SUM(k.entries), 0) as entries, COALESCE(SUM(k.house_cut), 0) as house_cut, COALESCE(SUM(k.prizes_paid), 0) as prizes_paid, COALESCE(SUM(k.outstanding), 0) as outstanding')
                ->groupBy('k.game_type', 'k.jp_rounds')
                ->orderBy('k.game_type')->orderBy('k.jp_rounds')
                ->get();

            $shape = fn ($row) => [
                'competitions' => (int) $row->competitions,
                'players' => (int) $row->players,
                'entries' => $this->money($row->entries),
                'house_cut' => $this->money($row->house_cut),
                'prizes_paid' => $this->money($row->prizes_paid),
                'outstanding' => $this->money($row->outstanding),
            ];

            $totals = ['competitions' => 0, 'players' => 0, 'entries' => 0.0, 'house_cut' => 0.0, 'prizes_paid' => 0.0, 'outstanding' => 0.0];
            $groups = [];
            foreach ($rows as $row) {
                $figures = $shape($row);
                $groups[] = ['type' => $this->competitionType((int) $row->game_type), 'rounds' => (int) $row->jp_rounds] + $figures;
                foreach ($totals as $key => $value) {
                    $totals[$key] = $value + $figures[$key];
                }
            }

            return array_map(fn ($value) => is_float($value) ? round($value, 2) : $value, $totals) + ['by_type_and_rounds' => $groups];
        };

        return new FinanceListing(
            $query,
            function (object $row) {
                $entries = $this->money($row->entries);
                $houseCut = $this->money($row->house_cut);
                $pool = round($entries - $houseCut, 2);

                return [
                    'cmp_uid' => $row->cmp_uid,
                    'competition_id' => $row->competition_id,
                    'type' => $this->competitionType((int) $row->game_type),
                    'rounds' => (int) $row->jp_rounds,
                    'players' => (int) $row->players,
                    'entries' => $entries,
                    'house_cut' => $houseCut,
                    'pool' => $pool,
                    'prizes_paid' => $this->money($row->prizes_paid),
                    'outstanding' => $this->money($row->outstanding),
                    'unaccounted' => round($pool - (float) $row->prizes_paid - (float) $row->outstanding, 2),
                    'started_at' => $this->moment($row->started_at),
                ];
            },
            $summary,
            ['cmp_uid', 'competition_id', 'type', 'rounds', 'players', 'entries', 'house_cut', 'pool', 'prizes_paid', 'outstanding', 'unaccounted', 'started_at'],
        );
    }

    /**
     * Open game and competition wallets nothing has happened on for a while: money that may be stranded.
     * A position as of now, so the date range is not applied (only `exclude_test` is read from it).
     *
     * A wallet is untouched when it is open (status 1) and its last activity (its newest transaction, or
     * its creation when it has none) is at least `idle_minutes` old. The default comes from
     * `finance.open_wallets` per kind: single games and competitions run at very different speeds.
     *
     * One row per game wallet (all its players) and one per competition wallet (each competition player
     * has their own). Single games are grouped by the players who staked in them, including 1-player
     * games still waiting for an opponent; tournaments and jackpots by their rounds.
     *
     * Filters: kind (game, tournament, jackpot), players (games), jp_rounds (competitions), idle_minutes.
     *
     * @param  array<string, string>  $filters
     *
     * @throws ValidationException
     */
    public function openWallets(FinanceDateRange $range, array $filters): FinanceListing
    {
        $kind = $filters['kind'] ?? null;

        if ($kind !== null && ! in_array($kind, self::OPEN_WALLET_KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'The kind must be one of: '.implode(', ', self::OPEN_WALLET_KINDS).'.']);
        }

        $thresholds = $this->idleThresholds($filters);
        $base = fn () => $this->openWalletBase($range, $filters, $thresholds);

        $summary = function () use ($base, $thresholds) {
            $groups = DB::query()->fromSub($base(), 'g')
                ->selectRaw('g.kind, g.players, g.rounds, COUNT(*) as wallets, COALESCE(SUM(g.balance), 0) as held, MIN(g.last_activity_at) as oldest_activity_at')
                ->groupBy('g.kind', 'g.players', 'g.rounds')
                ->get();

            return [
                'games' => $this->openWalletSection($groups->where('kind', 'game'), 'players', [1, 2, 3, 4]),
                'tournaments' => $this->openWalletSection($groups->where('kind', 'tournament'), 'rounds', [3, 4, 5]),
                'jackpots' => $this->openWalletSection($groups->where('kind', 'jackpot'), 'rounds', [13, 17, 21]),
                'other_competitions' => $this->openWalletTotals($groups->where('kind', 'competition')),
                'total' => $this->openWalletTotals($groups),
                'idle_minutes' => $thresholds,
            ];
        };

        return new FinanceListing(
            DB::query()->fromSub($base(), 'o')->orderBy('o.last_activity_at')->orderBy('o.kind')->orderBy('o.wallet_id'),
            function (object $row) {
                $isGame = $row->kind === 'game';

                return [
                    'kind' => $row->kind,
                    'wallet_id' => (int) $row->wallet_id,
                    'game_id' => $isGame ? $row->reference : null,
                    'cmp_uid' => $isGame ? null : $row->reference,
                    'competition_id' => $row->competition_id,
                    'game_type' => $row->game_type === null ? null : (int) $row->game_type,
                    'players' => $row->players === null ? null : (int) $row->players,
                    'rounds' => $row->rounds === null ? null : (int) $row->rounds,
                    'level' => $row->level === null ? null : (int) $row->level,
                    'balance' => $this->money($row->balance),
                    'status' => (int) $row->status,
                    'participants' => $this->participants($row->participants),
                    'under_complaint' => (bool) $row->under_complaint,
                    'created_at' => $this->moment($row->created_at),
                    'last_activity_at' => $this->moment($row->last_activity_at),
                    'idle_minutes' => (int) floor(abs(CarbonImmutable::parse($row->last_activity_at, config('app.timezone'))->diffInMinutes(now()))),
                ];
            },
            $summary,
            ['kind', 'wallet_id', 'game_id', 'cmp_uid', 'competition_id', 'game_type', 'players', 'rounds', 'level', 'balance', 'status', 'participants', 'under_complaint', 'created_at', 'last_activity_at', 'idle_minutes'],
        );
    }

    /**
     * @param  array<string, string>  $filters
     * @param  array{game: int, competition: int}  $thresholds
     */
    private function openWalletBase(FinanceDateRange $range, array $filters, array $thresholds): Builder
    {
        $excluded = $this->excludedCustomerIds($range);
        $kind = $filters['kind'] ?? null;
        $cutoff = fn (int $minutes) => now()->subMinutes($minutes)->format('Y-m-d H:i:s');

        $gameActivity = DB::table('game_transactions')
            ->selectRaw("game_wallet_id, MAX(created_at) as last_transaction_at, {$this->playersSql()} as players")
            ->groupBy('game_wallet_id');

        $gameLastActivity = 'GREATEST(gw.created_at, COALESCE(ga.last_transaction_at, gw.created_at))';

        $games = DB::table('game_wallets as gw')
            ->leftJoinSub($gameActivity, 'ga', 'ga.game_wallet_id', '=', 'gw.id')
            ->where('gw.status', 1)
            ->whereRaw("{$gameLastActivity} <= ?", [$cutoff($thresholds['game'])])
            ->when($excluded !== [], fn (Builder $query) => $query->whereNotExists(
                fn (Builder $test) => $test->select(DB::raw(1))
                    ->from('game_transactions as t2')
                    ->whereColumn('t2.game_wallet_id', 'gw.id')
                    ->where('t2.payment_type', 'deposit')
                    ->whereIn('t2.customer_id', $excluded)
            ))
            ->when($kind !== null && $kind !== 'game', fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->selectRaw("'game' as kind, gw.id as wallet_id, gw.game_id as reference, NULL as competition_id, gw.game_type,
                COALESCE(ga.players, 0) as players, NULL as rounds, NULL as level, gw.balance, gw.status, gw.created_at,
                {$gameLastActivity} as last_activity_at,
                EXISTS (SELECT 1 FROM complaints c WHERE c.game_wallet_id = gw.id AND c.status = ?) as under_complaint,
                (SELECT JSON_ARRAYAGG(JSON_OBJECT('stake_id', p.id, 'customer_id', p.customer_id, 'name', pc.name, 'stake', p.amount))
                    FROM game_transactions p LEFT JOIN customers pc ON pc.id = p.customer_id
                    WHERE p.game_wallet_id = gw.id AND p.payment_type = 'deposit') as participants", [Complaint::STATUS_PENDING]);

        $competitionActivity = DB::table('competition_transactions')
            ->selectRaw("competition_wallet_id, MAX(created_at) as last_transaction_at, COALESCE(SUM(CASE WHEN payment_type NOT IN ('payout', 'win', 'loss') THEN amount END), 0) as entries")
            ->groupBy('competition_wallet_id');

        $competitionLastActivity = 'GREATEST(cw.created_at, COALESCE(ca.last_transaction_at, cw.created_at))';
        $competitionKind = "CASE cw.game_type WHEN 1 THEN 'tournament' WHEN 2 THEN 'jackpot' ELSE 'competition' END";

        $competitions = DB::table('competition_wallets as cw')
            ->leftJoinSub($competitionActivity, 'ca', 'ca.competition_wallet_id', '=', 'cw.id')
            ->leftJoin('customers as oc', 'oc.id', '=', 'cw.customer_id')
            ->where('cw.status', 1)
            ->whereRaw("{$competitionLastActivity} <= ?", [$cutoff($thresholds['competition'])])
            ->when($excluded !== [], fn (Builder $query) => $query->whereNotIn('cw.customer_id', $excluded))
            ->when($kind === 'game', fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($kind === 'tournament', fn (Builder $query) => $query->where('cw.game_type', 1))
            ->when($kind === 'jackpot', fn (Builder $query) => $query->where('cw.game_type', 2))
            ->selectRaw("{$competitionKind} as kind, cw.id as wallet_id, cw.cmp_uid as reference, cw.competition_id, cw.game_type,
                NULL as players, cw.jp_rounds as rounds, cw.level, cw.balance, cw.status, cw.created_at,
                {$competitionLastActivity} as last_activity_at,
                EXISTS (SELECT 1 FROM complaints c WHERE c.competition_wallet_id = cw.id AND c.status = ?) as under_complaint,
                JSON_ARRAY(JSON_OBJECT('customer_id', cw.customer_id, 'name', oc.name, 'stake', COALESCE(ca.entries, 0))) as participants", [Complaint::STATUS_PENDING]);

        return DB::query()->fromSub($games->unionAll($competitions), 'w')
            ->select('w.*')
            ->when(isset($filters['players']), fn (Builder $query) => $query->where('w.players', (int) $filters['players']))
            ->when(isset($filters['jp_rounds']), fn (Builder $query) => $query->where('w.rounds', (int) $filters['jp_rounds']));
    }

    /**
     * Players in a game: the distinct customers who staked. A stake whose customer was deleted before
     * customers were soft-deleted has no customer_id left (the foreign key nulls it), so each such stake
     * counts as one player of its own rather than none.
     *
     * @param  string  $alias  Table alias prefix for game_transactions, e.g. "gt.".
     */
    private function playersSql(string $alias = ''): string
    {
        return "COUNT(DISTINCT CASE WHEN {$alias}payment_type = 'deposit' THEN COALESCE(CAST({$alias}customer_id AS CHAR), CONCAT('stake:', {$alias}id)) END)";
    }

    /**
     * Minutes of inactivity before a wallet counts as untouched: `idle_minutes` when sent, otherwise the
     * configured default for each kind.
     *
     * @param  array<string, string>  $filters
     * @return array{game: int, competition: int}
     */
    private function idleThresholds(array $filters): array
    {
        if (isset($filters['idle_minutes'])) {
            return ['game' => (int) $filters['idle_minutes'], 'competition' => (int) $filters['idle_minutes']];
        }

        return [
            'game' => (int) config('finance.open_wallets.game_idle_minutes'),
            'competition' => (int) config('finance.open_wallets.competition_idle_minutes'),
        ];
    }

    /**
     * Totals for one kind, then one entry per players or rounds value, in order: the expected values
     * always (zero when there are none), plus any other value found.
     *
     * @param  Collection<int, object>  $groups
     * @param  list<int>  $expected
     * @return array<string, mixed>
     */
    private function openWalletSection(Collection $groups, string $key, array $expected): array
    {
        $values = collect($expected)->merge($groups->pluck($key)->map(fn ($value) => (int) $value))->unique()->sort()->values();

        return $this->openWalletTotals($groups) + [
            "by_{$key}" => $values->map(fn (int $value) => [$key => $value]
                + $this->openWalletTotals($groups->filter(fn (object $group) => (int) $group->{$key} === $value)))->all(),
        ];
    }

    /**
     * @param  Collection<int, object>  $groups
     * @return array{wallets: int, held: float, oldest_activity_at: ?string}
     */
    private function openWalletTotals(Collection $groups): array
    {
        $oldest = $groups->pluck('oldest_activity_at')->filter()->min();

        return [
            'wallets' => (int) $groups->sum('wallets'),
            'held' => $this->money($groups->sum(fn (object $group) => (float) $group->held)),
            'oldest_activity_at' => $this->moment($oldest),
        ];
    }

    /**
     * Each participant once, with everything they staked in the wallet. A stake whose customer was
     * deleted (customer_id null) stays a participant of its own.
     *
     * @return list<array{customer_id: ?int, name: ?string, stake: float}>
     */
    private function participants(?string $json): array
    {
        return collect(json_decode($json ?? '[]', true) ?: [])
            ->groupBy(fn (array $participant) => $participant['customer_id'] === null ? 'stake:'.($participant['stake_id'] ?? '') : (string) $participant['customer_id'])
            ->map(fn (Collection $stakes) => [
                'customer_id' => $stakes->first()['customer_id'] === null ? null : (int) $stakes->first()['customer_id'],
                'name' => $stakes->first()['name'],
                'stake' => $this->money($stakes->sum(fn (array $stake) => (float) $stake['stake'])),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function gameBase(FinanceDateRange $range, array $filters): Builder
    {
        $excluded = $this->excludedCustomerIds($range);
        $house = self::HOUSE_CUSTOMER_ID;

        $perGame = DB::table('game_wallets as gw')
            ->join('game_transactions as gt', 'gt.game_wallet_id', '=', 'gw.id')
            ->whereBetween('gw.created_at', [$range->from, $range->to])
            ->when($excluded !== [], fn (Builder $query) => $query->whereNotExists(
                fn (Builder $test) => $test->select(DB::raw(1))
                    ->from('game_transactions as t2')
                    ->whereColumn('t2.game_wallet_id', 'gw.id')
                    ->where('t2.payment_type', 'deposit')
                    ->whereIn('t2.customer_id', $excluded)
            ))
            ->selectRaw("gw.id, gw.game_id, gw.game_type, gw.status, gw.balance, gw.created_at,
                {$this->playersSql('gt.')} as players,
                COALESCE(SUM(CASE WHEN gt.payment_type = 'deposit' THEN gt.amount END), 0) as stakes,
                COALESCE(SUM(CASE WHEN gt.payment_type IN ('payout', 'payout|dropped') AND gt.customer_id <> {$house} THEN gt.amount END), 0) as paid_to_players,
                COALESCE(SUM(CASE WHEN gt.payment_type IN ('payout', 'payout|dropped') AND gt.customer_id = {$house} THEN gt.amount END), 0) as house_payout,
                COALESCE(SUM(CASE WHEN gt.payment_type LIKE 'refund%' THEN gt.amount END), 0) as refunded,
                COALESCE(SUM(CASE WHEN gt.payment_type LIKE '%dropped' THEN 1 END), 0) as dropped_rows")
            ->groupBy('gw.id', 'gw.game_id', 'gw.game_type', 'gw.status', 'gw.balance', 'gw.created_at');

        $classified = DB::query()->fromSub($perGame, 'g')
            ->selectRaw("g.*,
                CASE WHEN g.status = 1 THEN 'open'
                     WHEN g.dropped_rows > 0 THEN 'dropped'
                     WHEN g.refunded > 0 AND g.paid_to_players = 0 THEN 'refunded'
                     WHEN g.paid_to_players > 0 THEN 'completed'
                     ELSE 'closed_empty' END as outcome,
                CASE WHEN g.status = 1 THEN NULL ELSE g.stakes - g.paid_to_players - g.refunded - g.balance END as house_take");

        return DB::query()->fromSub($classified, 'x')
            ->when(isset($filters['outcome']), fn (Builder $query) => $query->where('x.outcome', $filters['outcome']))
            ->when(isset($filters['game_type']), fn (Builder $query) => $query->where('x.game_type', (int) $filters['game_type']))
            ->when(isset($filters['players']), fn (Builder $query) => $query->where('x.players', (int) $filters['players']));
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function competitionBase(FinanceDateRange $range, array $filters): Builder
    {
        $excluded = $this->excludedCustomerIds($range);

        $houseCut = "(SELECT COALESCE(SUM(l.credit), 0) FROM ledger_entries l
            JOIN competition_transactions c2 ON c2.id = l.referenceable_id AND l.referenceable_type = ?
            JOIN competition_wallets w2 ON w2.id = c2.competition_wallet_id
            WHERE l.entry_type = 'house_cut' AND w2.cmp_uid = cw.cmp_uid)";
        $outstanding = '(SELECT COALESCE(SUM(x.balance), 0) FROM competition_wallets x WHERE x.cmp_uid = cw.cmp_uid AND x.status = 1)';

        return DB::table('competition_wallets as cw')
            ->leftJoin('competition_transactions as ct', 'ct.competition_wallet_id', '=', 'cw.id')
            ->whereNotNull('cw.cmp_uid')
            ->where('cw.cmp_uid', '!=', '')
            ->whereBetween('cw.created_at', [$range->from, $range->to])
            ->when($excluded !== [], fn (Builder $query) => $query->whereNotExists(
                fn (Builder $test) => $test->select(DB::raw(1))
                    ->from('competition_wallets as t2')
                    ->whereColumn('t2.cmp_uid', 'cw.cmp_uid')
                    ->whereIn('t2.customer_id', $excluded)
            ))
            ->when(isset($filters['game_type']), fn (Builder $query) => $query->where('cw.game_type', (int) $filters['game_type']))
            ->when(isset($filters['jp_rounds']), fn (Builder $query) => $query->where('cw.jp_rounds', (int) $filters['jp_rounds']))
            ->selectRaw("cw.cmp_uid, MIN(cw.competition_id) as competition_id, cw.game_type, MAX(cw.jp_rounds) as jp_rounds, MIN(cw.created_at) as started_at,
                COUNT(DISTINCT cw.customer_id) as players,
                COALESCE(SUM(CASE WHEN ct.payment_type NOT IN ('payout', 'win', 'loss') THEN ct.amount END), 0) as entries,
                COALESCE(SUM(CASE WHEN ct.payment_type = 'payout' THEN ct.amount END), 0) as prizes_paid,
                {$houseCut} as house_cut, {$outstanding} as outstanding", [CompetitionTransaction::class])
            ->groupBy('cw.cmp_uid', 'cw.game_type');
    }

    private function competitionType(int $gameType): string
    {
        return match ($gameType) {
            1 => 'tournament',
            2 => 'jackpot',
            default => 'other',
        };
    }
}
