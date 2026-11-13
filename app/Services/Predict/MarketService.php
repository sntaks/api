<?php

namespace App\Services\Predict;

use App\Exceptions\PolymarketUnavailable;
use App\Models\PredictBet;
use App\Models\PredictExposure;
use App\Models\PredictLmsrState;
use App\Models\PredictMarket;
use App\Models\PredictMarketBlock;
use App\Models\PredictPool;
use App\Models\Wager;
use App\Models\WagerRound;
use App\Services\Predict\Polymarket\EventImporter;
use App\Services\Predict\Polymarket\ReferencePriceRecorder;
use App\Services\WagerRoundService;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Market lifecycle and admin actions. Every transition locks the market row, checks the state it comes from
 * and writes the audit log in the same transaction.
 *
 * DRAFT → OPEN (publish, also approves an imported draft) · DRAFT → REJECTED (reject) · OPEN → SUSPENDED (suspend;
 * the price sync too, for an imported market) · SUSPENDED → OPEN (resume) · OPEN|SUSPENDED → CLOSED
 * (close, or the close time) · CLOSED → RESOLVED (resolve, starts settlement) · RESOLVED → SETTLED (settlement
 * job) · DRAFT|OPEN|SUSPENDED|CLOSED → VOIDED (void, refunds). A resolved market is never voided: its payouts
 * may have started.
 */
class MarketService
{
    /** Fields an admin may still change once the market is open; the pool terms are frozen at publish */
    private const EDITABLE_WHEN_LIVE = [
        'question', 'description', 'resolution_source', 'image_url', 'category', 'countries', 'close_at', 'resolution_deadline_at',
        // Fixed-odds terms apply to the next quote, never to bets already placed
        'margin_bp', 'max_liability_minor', 'max_customer_stake_minor',
    ];

    public function __construct(
        private PredictContext $context,
        private AuditLogger $audit,
        private SettlementService $settlements,
        private ReferencePriceRecorder $prices,
        private WagerRoundService $rounds,
    ) {}

    /**
     * A custom pool market as a draft. Amounts arrive in KES and rates as fractions; they are stored in minor
     * units and basis points; omitted pool terms take the config defaults.
     *
     * @param  array<string, mixed>  $data
     */
    public function createCustom(array $data, string $adminUser): PredictMarket
    {
        $engine = $data['engine'] ?? PredictMarket::ENGINE_POOL;
        unset($data['engine']);

        // An LMSR market's fee is stored as its cut, defaulting to the LMSR fee rather than the pool cut
        if ($engine === PredictMarket::ENGINE_LMSR) {
            $data['cut'] ??= config('predict.lmsr.fee');
            $data['liquidity'] ??= config('predict.lmsr.liquidity');
        }

        return DB::transaction(function () use ($data, $adminUser, $engine) {
            $market = PredictMarket::create([
                'source' => PredictMarket::SOURCE_CUSTOM,
                'engine' => $engine,
                'product_id' => $this->context->productFor($engine)->id,
                'round_reference' => 'predict-'.Str::lower((string) Str::ulid()),
                'status' => PredictMarket::STATUS_DRAFT,
                'created_by' => $adminUser,
                ...$this->attributes($data, withDefaults: true),
            ]);

            $this->audit->record('create', $market, $adminUser, null, null, $this->snapshot($market));

            return $market;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PredictMarket $market, array $data, string $adminUser, ?string $reason): PredictResult
    {
        return DB::transaction(function () use ($market, $data, $adminUser, $reason) {
            $locked = PredictMarket::whereKey($market->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [PredictMarket::STATUS_DRAFT, PredictMarket::STATUS_OPEN, PredictMarket::STATUS_SUSPENDED], true)) {
                return PredictResult::refused('market_not_editable', 'This market can no longer be edited', 409, ['status' => $locked->status]);
            }

            $attributes = $this->attributes($data);

            if ($locked->status !== PredictMarket::STATUS_DRAFT) {
                $frozen = array_keys(Arr::except($attributes, self::EDITABLE_WHEN_LIVE));

                if ($frozen !== []) {
                    return PredictResult::refused('market_terms_frozen', 'The pool terms cannot change once the market is published', 409, ['fields' => $frozen]);
                }
            }

            if ($locked->isImported()) {
                // The sync stops overwriting a field once an admin has localised it
                $overridden = array_intersect(array_keys($attributes), EventImporter::SYNCED_FIELDS);
                $attributes['overridden_fields'] = array_values(array_unique([...($locked->overridden_fields ?? []), ...$overridden]));
            }

            $before = $this->snapshot($locked);
            $locked->update($attributes);
            $this->audit->record('update', $locked, $adminUser, $reason, $before, $this->snapshot($locked));

            return PredictResult::ok(['market' => $locked]);
        });
    }

    /**
     * Publish a draft. An imported draft is approved on fresh CLOB prices, fetched here first unless the caller
     * just did (a bulk approval fetches them for the whole batch at once).
     */
    public function publish(PredictMarket $market, string $adminUser, ?string $reason, bool $refreshPrices = true): PredictResult
    {
        if ($refreshPrices && $market->isImported() && $market->status === PredictMarket::STATUS_DRAFT) {
            try {
                $this->prices->refresh(collect([$market->load('outcomes')]), keepHistory: false);
            } catch (PolymarketUnavailable) {
                return $this->polymarketUnavailable();
            }
        }

        return $this->transition($market, 'publish', [PredictMarket::STATUS_DRAFT], $adminUser, $reason, function (PredictMarket $locked) {
            if ($locked->close_at->isPast()) {
                return PredictResult::refused('close_time_passed', 'The close time has passed; edit it before publishing', 409);
            }

            if ($locked->followsReferencePrice()) {
                return $this->openImported($locked);
            }

            if ($locked->isImported()) {
                $opening = $this->openingProbabilityBp($locked);

                if ($opening === null) {
                    return PredictResult::refused('price_unavailable', 'This market has no usable Polymarket price to open the pool at', 409);
                }

                $locked->update(['start_probability_bp' => $opening]);
            }

            if ($locked->engine === PredictMarket::ENGINE_LMSR) {
                return $this->openLmsr($locked);
            }

            $this->openPool($locked);

            $locked->update(['status' => PredictMarket::STATUS_OPEN, 'opened_at' => now()]);

            return null;
        });
    }

    /**
     * Publish many drafts, fetching the imported ones' prices in one batch first. Each market is its own
     * transition and audit entry; one refusal does not stop the rest.
     *
     * @param  Collection<int, PredictMarket>  $markets
     * @return array{done: list<int>, refused: list<array{id: int, code: string}>}
     */
    public function bulkPublish(Collection $markets, string $adminUser, ?string $reason): array
    {
        $imported = $markets->filter(fn (PredictMarket $market) => $market->isImported() && $market->status === PredictMarket::STATUS_DRAFT);
        $pricesFailed = false;

        if ($imported->isNotEmpty()) {
            try {
                $this->prices->refresh($imported->each->load('outcomes'), keepHistory: false);
            } catch (PolymarketUnavailable) {
                $pricesFailed = true;
            }
        }

        return $this->each($markets, fn (PredictMarket $market) => $pricesFailed && $market->isImported()
            ? $this->polymarketUnavailable()
            : $this->publish($market, $adminUser, $reason, refreshPrices: false));
    }

    /**
     * Move an imported fixed-odds draft to our pool, with the import seed and the pool cut, as a new import would
     * be. A draft has no round, exposures or bets yet, so only its terms change; anything else is refused.
     */
    public function convertDraftToPool(PredictMarket $market, string $adminUser): PredictResult
    {
        return DB::transaction(function () use ($market, $adminUser) {
            $locked = PredictMarket::whereKey($market->id)->lockForUpdate()->firstOrFail();

            if (! $locked->followsReferencePrice() || $locked->status !== PredictMarket::STATUS_DRAFT) {
                return PredictResult::refused('not_convertible', 'Only an imported fixed-odds draft can move to the pool', 409, ['status' => $locked->status, 'engine' => $locked->engine]);
            }

            $before = $this->snapshot($locked);
            $locked->update([
                'engine' => PredictMarket::ENGINE_POOL,
                'product_id' => $this->context->productFor(PredictMarket::ENGINE_POOL)->id,
                'seed_minor' => (int) round(config('predict.polymarket.seed') * 100),
                'cut_bp' => (int) round(config('predict.pool.cut') * 10000),
            ]);
            $this->audit->record('convert_engine', $locked, $adminUser, 'Imported drafts are priced by our pool', $before, $this->snapshot($locked));

            return PredictResult::ok(['market' => $locked]);
        });
    }

    /**
     * Move a published fixed-odds import to our pool (decided 2026-11-13): seed it at Polymarket's current YES price
     * with the import seed and the pool terms, on a new round under the pool product. Only a market nobody has bet
     * on can move. A bet takes a shared lock on the market row before it picks its engine, so holding the row FOR
     * UPDATE here keeps the two apart. The old fixed-odds round is empty and is voided once this commits.
     */
    public function convertLiveToPool(PredictMarket $market, string $adminUser): PredictResult
    {
        $converted = DB::transaction(function () use ($market, $adminUser) {
            $locked = PredictMarket::whereKey($market->id)->with('product')->lockForUpdate()->firstOrFail();
            $live = [PredictMarket::STATUS_OPEN, PredictMarket::STATUS_SUSPENDED];

            if (! $locked->followsReferencePrice() || ! in_array($locked->status, $live, true)) {
                return PredictResult::refused('not_convertible', 'Only a published fixed-odds import can move to the pool', 409, ['status' => $locked->status, 'engine' => $locked->engine]);
            }

            $oldRound = WagerRound::where('product_id', $locked->product_id)->where('reference', $locked->round_reference)->first();

            if (PredictBet::where('predict_market_id', $locked->id)->exists() || ($oldRound && Wager::where('wager_round_id', $oldRound->id)->exists())) {
                return PredictResult::refused('market_has_bets', 'A market with bets keeps its fixed odds', 409);
            }

            $opening = $this->openingProbabilityBp($locked);

            if ($opening === null) {
                return PredictResult::refused('price_unavailable', 'This market has no usable Polymarket price to open the pool at', 409);
            }

            $before = $this->snapshot($locked);
            $oldProductCode = $locked->product->code;
            $oldReference = $locked->round_reference;
            $locked->unsetRelation('product');
            $reopen = $locked->status === PredictMarket::STATUS_SUSPENDED && $locked->suspension_cause === PredictMarket::SUSPENDED_FOR_PRICE;

            $locked->update([
                'engine' => PredictMarket::ENGINE_POOL,
                'product_id' => $this->context->productFor(PredictMarket::ENGINE_POOL)->id,
                'round_reference' => 'predict-'.Str::lower((string) Str::ulid()),
                'seed_minor' => (int) round(config('predict.polymarket.seed') * 100),
                'cut_bp' => (int) round(config('predict.pool.cut') * 10000),
                'cut_on' => PredictMarket::CUT_ON_WINNINGS,
                'min_multiplier_x100' => (int) round(config('predict.pool.min_multiplier') * 100),
                'start_probability_bp' => $opening,
                ...($reopen ? ['status' => PredictMarket::STATUS_OPEN, 'suspension_cause' => null] : []),
            ]);

            PredictExposure::where('predict_market_id', $locked->id)->delete();
            $this->openPool($locked);
            $this->audit->record('convert_engine', $locked, $adminUser, 'Published imports are priced by our pool', $before, $this->snapshot($locked));

            return ['market' => $locked, 'product' => $oldProductCode, 'round' => $oldReference];
        });

        if ($converted instanceof PredictResult) {
            return $converted;
        }

        // The old round has no wagers; voiding it only closes it. A failure leaves an empty round nothing points at
        $voided = $this->rounds->void($this->context->wagerKey(), $converted['product'], $converted['round'], 'Market moved to the pool');

        return PredictResult::ok(['market' => $converted['market'], 'old_round_voided' => ! $voided->isRefused()]);
    }

    /**
     * @param  Collection<int, PredictMarket>  $markets
     * @return array{done: list<int>, refused: list<array{id: int, code: string}>}
     */
    public function bulkReject(Collection $markets, string $adminUser, string $reason): array
    {
        return $this->each($markets, fn (PredictMarket $market) => $this->reject($market, $adminUser, $reason));
    }

    /**
     * @param  Collection<int, PredictMarket>  $markets
     * @param  callable(PredictMarket): PredictResult  $action
     * @return array{done: list<int>, refused: list<array{id: int, code: string}>}
     */
    private function each(Collection $markets, callable $action): array
    {
        $done = [];
        $refused = [];

        foreach ($markets as $market) {
            $result = $action($market);

            $result->isRefused()
                ? $refused[] = ['id' => $market->id, 'code' => $result->refusal['code']]
                : $done[] = $market->id;
        }

        return ['done' => $done, 'refused' => $refused];
    }

    private function polymarketUnavailable(): PredictResult
    {
        return PredictResult::refused('polymarket_unavailable', 'Polymarket cannot be reached for a fresh price; try again shortly', 503);
    }

    /**
     * Turn down a draft (an imported market not worth showing, or a custom draft). The sync never re-imports it.
     */
    public function reject(PredictMarket $market, string $adminUser, string $reason): PredictResult
    {
        return $this->transition($market, 'reject', [PredictMarket::STATUS_DRAFT], $adminUser, $reason, function (PredictMarket $locked) use ($reason) {
            $locked->update(['status' => PredictMarket::STATUS_REJECTED, 'rejected_at' => now(), 'reject_reason' => $reason]);

            return null;
        });
    }

    /**
     * An admin suspension. It also stops the price sync from reopening the market by itself.
     */
    public function suspend(PredictMarket $market, string $adminUser, ?string $reason): PredictResult
    {
        $from = [PredictMarket::STATUS_OPEN, PredictMarket::STATUS_SUSPENDED];

        return $this->transition($market, 'suspend', $from, $adminUser, $reason, function (PredictMarket $locked) {
            if ($locked->status === PredictMarket::STATUS_SUSPENDED && $locked->suspension_cause === PredictMarket::SUSPENDED_BY_ADMIN) {
                return PredictResult::refused('invalid_transition', 'This market is already suspended by an admin', 409, ['status' => $locked->status]);
            }

            $locked->update(['status' => PredictMarket::STATUS_SUSPENDED, 'suspension_cause' => PredictMarket::SUSPENDED_BY_ADMIN]);

            return null;
        });
    }

    public function resume(PredictMarket $market, string $adminUser, ?string $reason): PredictResult
    {
        return $this->transition($market, 'resume', [PredictMarket::STATUS_SUSPENDED], $adminUser, $reason, function (PredictMarket $locked) {
            if ($locked->close_at->isPast()) {
                return PredictResult::refused('close_time_passed', 'The close time has passed; the market can only be closed', 409);
            }

            if ($locked->followsReferencePrice() && ! $this->hasUsablePrices($locked)) {
                return PredictResult::refused('price_unavailable', 'This market has no usable reference price', 409);
            }

            $locked->update(['status' => PredictMarket::STATUS_OPEN, 'suspension_cause' => null]);

            return null;
        });
    }

    /**
     * The price sync suspends an open imported market whose reference price is unusable.
     */
    public function suspendForPrice(PredictMarket $market, string $reason): PredictResult
    {
        return $this->transition($market, 'suspend', [PredictMarket::STATUS_OPEN], 'price-sync', $reason, function (PredictMarket $locked) {
            $locked->update(['status' => PredictMarket::STATUS_SUSPENDED, 'suspension_cause' => PredictMarket::SUSPENDED_FOR_PRICE]);

            return null;
        });
    }

    /**
     * The price sync reopens a market it suspended, once prices are usable and the close time is not reached.
     * A market an admin suspended stays suspended.
     */
    public function resumeAfterPrice(PredictMarket $market): PredictResult
    {
        return $this->transition($market, 'resume', [PredictMarket::STATUS_SUSPENDED], 'price-sync', 'Reference price usable again', function (PredictMarket $locked) {
            if ($locked->suspension_cause !== PredictMarket::SUSPENDED_FOR_PRICE || $locked->close_at->isPast() || ! $this->hasUsablePrices($locked)) {
                return PredictResult::refused('not_resumable', 'This market stays suspended', 409);
            }

            $locked->update(['status' => PredictMarket::STATUS_OPEN, 'suspension_cause' => null]);

            return null;
        });
    }

    public function close(PredictMarket $market, string $adminUser, ?string $reason): PredictResult
    {
        return $this->transition($market, 'close', [PredictMarket::STATUS_OPEN, PredictMarket::STATUS_SUSPENDED], $adminUser, $reason, function (PredictMarket $locked) {
            $locked->update(['status' => PredictMarket::STATUS_CLOSED, 'closed_at' => now(), 'suspension_cause' => null]);

            return null;
        });
    }

    /**
     * Resolve a closed market and start paying it out at once (no dispute window).
     */
    public function resolve(PredictMarket $market, string $result, string $adminUser, ?string $reason): PredictResult
    {
        return $this->transition($market, 'resolve', [PredictMarket::STATUS_CLOSED], $adminUser, $reason, function (PredictMarket $locked) use ($result, $adminUser) {
            $locked->update([
                'status' => PredictMarket::STATUS_RESOLVED,
                'result' => $result,
                'resolved_at' => now(),
                'resolved_by' => $adminUser,
            ]);

            $this->settlements->start($locked, $adminUser);

            return null;
        });
    }

    /**
     * Void the market and refund every stake. A market that never opened has nothing to refund.
     */
    public function void(PredictMarket $market, string $adminUser, string $reason): PredictResult
    {
        $from = [PredictMarket::STATUS_DRAFT, PredictMarket::STATUS_OPEN, PredictMarket::STATUS_SUSPENDED, PredictMarket::STATUS_CLOSED];

        return $this->transition($market, 'void', $from, $adminUser, $reason, function (PredictMarket $locked) use ($adminUser, $reason) {
            $wasOpened = $locked->opened_at !== null;

            $locked->update(['status' => PredictMarket::STATUS_VOIDED, 'voided_at' => now(), 'void_reason' => $reason]);

            if ($wasOpened) {
                $this->settlements->startVoid($locked, $adminUser, $reason);
            }

            return null;
        });
    }

    /**
     * Close every open or suspended market whose close time has passed (the scheduler).
     */
    public function closeDue(): int
    {
        $closed = 0;

        PredictMarket::whereIn('status', [PredictMarket::STATUS_OPEN, PredictMarket::STATUS_SUSPENDED])
            ->where('close_at', '<=', now())
            ->pluck('id')
            ->each(function (int $id) use (&$closed) {
                $result = $this->close(PredictMarket::findOrFail($id), 'system', 'Close time reached');
                $closed += $result->isRefused() ? 0 : 1;
            });

        return $closed;
    }

    /**
     * Open an approved imported market for fixed-odds betting: its wager round and exposure rows. Needs prices
     * that pass the betting guards on both sides.
     */
    private function openImported(PredictMarket $locked): ?PredictResult
    {
        if (! $this->hasUsablePrices($locked)) {
            return PredictResult::refused('price_unavailable', 'This market has no price that passes the betting guards yet', 409);
        }

        WagerRound::create(['product_id' => $locked->product_id, 'reference' => $locked->round_reference, 'status' => WagerRound::STATUS_OPEN]);

        // The rows every fixed-odds bet locks; created here, never on the first bet
        foreach (PredictMarket::SIDES as $side) {
            PredictExposure::create(['predict_market_id' => $locked->id, 'side' => $side, 'stakes_minor' => 0, 'payouts_minor' => 0]);
        }

        $locked->update(['status' => PredictMarket::STATUS_OPEN, 'opened_at' => now()]);

        return null;
    }

    /**
     * Open a custom LMSR market: shares set so the price starts at the admin's probability, plus the wager round
     * and the exposure rows its bets update.
     */
    private function openLmsr(PredictMarket $locked): ?PredictResult
    {
        $liquidity = bcdiv((string) $locked->liquidity_minor, '100', 2);
        $opening = LmsrMath::opening(bcdiv((string) $locked->start_probability_bp, '10000', 4), $liquidity);

        PredictLmsrState::create(['predict_market_id' => $locked->id, 'q_yes' => $opening['yes'], 'q_no' => $opening['no'], 'liquidity' => $liquidity]);
        WagerRound::create(['product_id' => $locked->product_id, 'reference' => $locked->round_reference, 'status' => WagerRound::STATUS_OPEN]);

        foreach (PredictMarket::SIDES as $side) {
            PredictExposure::create(['predict_market_id' => $locked->id, 'side' => $side, 'stakes_minor' => 0, 'payouts_minor' => 0]);
        }

        $locked->update(['status' => PredictMarket::STATUS_OPEN, 'opened_at' => now()]);

        return null;
    }

    /**
     * Seed a pool market's pool by its opening probability and open its wager round. The round is created here,
     * never on the first bet: a bet must not insert the round under its locks.
     */
    private function openPool(PredictMarket $locked): void
    {
        $seed = PoolMath::seedSplit($locked->seed_minor, $locked->start_probability_bp);

        PredictPool::create([
            'predict_market_id' => $locked->id,
            'yes_minor' => $seed['yes'],
            'no_minor' => $seed['no'],
            'seed_yes_minor' => $seed['yes'],
            'seed_no_minor' => $seed['no'],
        ]);

        WagerRound::create(['product_id' => $locked->product_id, 'reference' => $locked->round_reference, 'status' => WagerRound::STATUS_OPEN]);
    }

    /**
     * The opening YES probability of an imported pool market, in basis points: Polymarket's fresh YES midpoint,
     * read once, kept within `predict.polymarket.opening_min`/`opening_max`. Null when there is no usable price.
     */
    private function openingProbabilityBp(PredictMarket $market): ?int
    {
        $yes = $market->outcomes()->where('side', PredictMarket::SIDE_YES)->first();

        if (! $yes?->hasUsablePrice()) {
            return null;
        }

        $bp = (int) round((float) $yes->midpoint * 10000);
        $min = (int) round(config('predict.polymarket.opening_min') * 10000);
        $max = (int) round(config('predict.polymarket.opening_max') * 10000);

        return max($min, min($max, $bp));
    }

    /**
     * Both sides pass the betting guards (fresh, reliable, tight and deep enough to bet on).
     */
    private function hasUsablePrices(PredictMarket $market): bool
    {
        $outcomes = $market->outcomes()->get();

        return $outcomes->count() === 2 && $outcomes->every(fn ($outcome) => $outcome->bettingGuardFailure() === null);
    }

    public function block(PredictMarket $market, int $customerId, string $adminUser, string $reason): PredictResult
    {
        return DB::transaction(function () use ($market, $customerId, $adminUser, $reason) {
            $block = PredictMarketBlock::firstOrCreate(
                ['predict_market_id' => $market->id, 'customer_id' => $customerId],
                ['reason' => $reason, 'admin_user' => $adminUser]
            );

            if ($block->wasRecentlyCreated) {
                $this->audit->record('block', $market, $adminUser, $reason, null, ['customer_id' => $customerId]);
            }

            return PredictResult::ok(['block' => $block], $block->wasRecentlyCreated);
        });
    }

    public function unblock(PredictMarket $market, int $customerId, string $adminUser, ?string $reason): PredictResult
    {
        return DB::transaction(function () use ($market, $customerId, $adminUser, $reason) {
            $deleted = PredictMarketBlock::where('predict_market_id', $market->id)->where('customer_id', $customerId)->delete();

            if ($deleted === 0) {
                return PredictResult::refused('block_not_found', 'That customer is not blocked on this market', 404);
            }

            $this->audit->record('unblock', $market, $adminUser, $reason, ['customer_id' => $customerId], null);

            return PredictResult::ok([]);
        });
    }

    /**
     * Lock the market, check it is in one of $from, run the change and log it.
     *
     * @param  list<string>  $from
     * @param  callable(PredictMarket): ?PredictResult  $change  returns a refusal, or null when applied
     */
    private function transition(PredictMarket $market, string $action, array $from, string $adminUser, ?string $reason, callable $change): PredictResult
    {
        return DB::transaction(function () use ($market, $action, $from, $adminUser, $reason, $change) {
            $locked = PredictMarket::whereKey($market->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                return PredictResult::refused('invalid_transition', "A {$locked->status} market cannot {$action}", 409, ['status' => $locked->status]);
            }

            $before = $this->snapshot($locked);
            $refusal = $change($locked);

            if ($refusal instanceof PredictResult) {
                return $refusal;
            }

            $this->audit->record($action, $locked, $adminUser, $reason, $before, $this->snapshot($locked));

            return PredictResult::ok(['market' => $locked->fresh()]);
        }, 3);
    }

    /**
     * Request fields (KES, fractions) as columns (minor units, basis points, hundredths). Only the keys present
     * are returned, so an update changes only what it sends; a create fills omitted pool terms from config.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, bool $withDefaults = false): array
    {
        $minor = fn ($kes) => (int) round(((float) $kes) * 100);
        $bp = fn ($fraction) => (int) round(((float) $fraction) * 10000);

        $map = [
            'question' => fn ($v) => $v,
            'description' => fn ($v) => $v,
            'resolution_criteria' => fn ($v) => $v,
            'resolution_source' => fn ($v) => $v,
            'category' => fn ($v) => $v,
            'image_url' => fn ($v) => $v,
            'countries' => fn ($v) => array_values($v),
            'close_at' => fn ($v) => $v,
            'resolution_deadline_at' => fn ($v) => $v,
        ];

        $attributes = [];

        foreach ($map as $field => $convert) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $convert($data[$field]);
            }
        }

        $defaults = [
            'cut' => ['cut_bp', $bp, config('predict.pool.cut')],
            'seed' => ['seed_minor', $minor, config('predict.pool.seed')],
            'start_probability' => ['start_probability_bp', $bp, null],
            'min_stake' => ['min_stake_minor', $minor, config('predict.limits.min_stake')],
            'max_stake' => ['max_stake_minor', $minor, config('predict.limits.max_stake')],
            'max_payout' => ['max_payout_minor', $minor, config('predict.limits.max_payout')],
            'min_multiplier' => ['min_multiplier_x100', fn ($v) => (int) round(((float) $v) * 100), config('predict.pool.min_multiplier')],
            'margin' => ['margin_bp', $bp, config('predict.fixed.margin')],
            'liquidity' => ['liquidity_minor', $minor, null],
            'max_liability' => ['max_liability_minor', $minor, config('predict.fixed.max_liability')],
            'max_customer_stake' => ['max_customer_stake_minor', $minor, config('predict.fixed.max_customer_stake')],
        ];

        foreach ($defaults as $field => [$column, $convert, $default]) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $attributes[$column] = $convert($data[$field]);
            } elseif ($withDefaults && $default !== null) {
                $attributes[$column] = $convert($default);
            }
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(PredictMarket $market): array
    {
        return Arr::except($market->attributesToArray(), ['created_at', 'updated_at']);
    }
}
