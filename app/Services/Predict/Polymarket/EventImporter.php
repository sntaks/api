<?php

namespace App\Services\Predict\Polymarket;

use App\Models\PredictEvent;
use App\Models\PredictMarket;
use App\Models\PredictOutcome;
use App\Services\Predict\MarketService;
use App\Services\Predict\PredictContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports open Polymarket events under the configured tags. New binary markets arrive as drafts for an admin to
 * review; known ones get Polymarket's latest data, except the fields an admin localised. A market Polymarket
 * closed is closed here too (a draft is rejected).
 */
class EventImporter
{
    /** Display fields the sync keeps in step with Polymarket until an admin overrides them */
    public const SYNCED_FIELDS = ['question', 'description', 'image_url', 'close_at', 'resolution_deadline_at'];

    public function __construct(
        private GammaClient $gamma,
        private MarketMapper $mapper,
        private MarketService $markets,
        private PredictContext $context,
        private ReferencePriceRecorder $prices,
    ) {}

    /**
     * @return array{events: int, created: int, updated: int, closed: int, skipped: int}
     */
    public function sync(): array
    {
        $counts = ['events' => 0, 'created' => 0, 'updated' => 0, 'closed' => 0, 'skipped' => 0];

        foreach (config('predict.polymarket.tags') as $category => $slugs) {
            foreach ($slugs as $slug) {
                $tagId = $this->gamma->tagId($slug);

                if ($tagId === null) {
                    continue;
                }

                $cursor = null;

                for ($page = 0; $page < (int) config('predict.polymarket.max_pages_per_tag'); $page++) {
                    $result = $this->gamma->openEvents($tagId, $cursor);

                    foreach ($result['events'] as $event) {
                        $counts['events']++;
                        $this->syncEvent($event, $category, $counts);
                    }

                    if ($result['next_cursor'] === null) {
                        break;
                    }

                    $cursor = $result['next_cursor'];
                }
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array{events: int, created: int, updated: int, closed: int, skipped: int}  $counts
     */
    private function syncEvent(array $event, string $category, array &$counts): void
    {
        if (! isset($event['id'], $event['title'])) {
            $counts['skipped']++;

            return;
        }

        $model = PredictEvent::updateOrCreate(
            ['source' => PredictMarket::SOURCE_POLYMARKET, 'external_event_id' => (string) $event['id']],
            [
                'slug' => $event['slug'] ?? null,
                'title' => mb_substr((string) $event['title'], 0, 255),
                'description' => $event['description'] ?? null,
                'image_url' => isset($event['image']) ? mb_substr((string) $event['image'], 0, 500) : null,
                'category' => $category,
                'tags' => collect($event['tags'] ?? [])->pluck('slug')->filter()->values()->all(),
                'starts_at' => $event['startDate'] ?? null,
                'ends_at' => $event['endDate'] ?? null,
                'is_closed' => (bool) ($event['closed'] ?? false),
                'last_synced_at' => now(),
            ]
        );

        foreach ($event['markets'] ?? [] as $raw) {
            $imported = is_array($raw) ? $this->mapper->map($raw) : null;

            if (! $imported) {
                $counts['skipped']++;

                continue;
            }

            $existing = PredictMarket::where('source', PredictMarket::SOURCE_POLYMARKET)->where('external_market_id', $imported->externalId)->first();

            $existing
                ? $this->update($existing, $imported, $counts)
                : $this->create($model, $imported, $category, $counts);
        }
    }

    /**
     * @param  array{events: int, created: int, updated: int, closed: int, skipped: int}  $counts
     */
    private function create(PredictEvent $event, ImportedMarket $imported, string $category, array &$counts): void
    {
        $closeAt = $imported->endAt ?? $event->ends_at;

        if ($imported->closed || $closeAt === null || $closeAt->isPast() || ($imported->volume ?? 0) < (float) config('predict.polymarket.min_volume')) {
            $counts['skipped']++;

            return;
        }

        DB::transaction(function () use ($event, $imported, $category, $closeAt) {
            $market = PredictMarket::create([
                'source' => PredictMarket::SOURCE_POLYMARKET,
                'predict_event_id' => $event->id,
                'engine' => PredictMarket::ENGINE_REFERENCE,
                'product_id' => $this->context->productFor(PredictMarket::ENGINE_REFERENCE)->id,
                'round_reference' => 'predict-'.Str::lower((string) Str::ulid()),
                'status' => PredictMarket::STATUS_DRAFT,
                'category' => $category,
                'countries' => [config('predict.default_country')],
                'resolution_criteria' => $imported->description ?: 'Resolves as the market resolves on Polymarket; see the resolution source.',
                'resolution_source' => $event->slug ? 'https://polymarket.com/event/'.$event->slug : null,
                'created_by' => 'polymarket-sync',
                // Pool terms do not apply to a fixed-odds market; its terms and limits come from config
                'cut_bp' => 0,
                'seed_minor' => 0,
                'start_probability_bp' => 5000,
                'min_stake_minor' => (int) round(config('predict.limits.min_stake') * 100),
                'max_stake_minor' => (int) round(config('predict.limits.max_stake') * 100),
                'max_payout_minor' => (int) round(config('predict.limits.max_payout') * 100),
                'min_multiplier_x100' => (int) round(config('predict.pool.min_multiplier') * 100),
                'margin_bp' => (int) round(config('predict.fixed.margin') * 10000),
                'max_liability_minor' => (int) round(config('predict.fixed.max_liability') * 100),
                'max_customer_stake_minor' => (int) round(config('predict.fixed.max_customer_stake') * 100),
                ...$this->externalAttributes($imported),
                ...$this->syncedAttributes($imported, $closeAt, $event->image_url),
            ]);

            foreach ([PredictMarket::SIDE_YES => [$imported->yesLabel, $imported->yesTokenId], PredictMarket::SIDE_NO => [$imported->noLabel, $imported->noTokenId]] as $side => [$label, $token]) {
                PredictOutcome::create(['predict_market_id' => $market->id, 'side' => $side, 'label' => $label, 'external_token_id' => $token]);
            }

            $this->prices->recordFromGamma($market, $imported);
        });

        $counts['created']++;
    }

    /**
     * @param  array{events: int, created: int, updated: int, closed: int, skipped: int}  $counts
     */
    private function update(PredictMarket $market, ImportedMarket $imported, array &$counts): void
    {
        $attributes = $this->externalAttributes($imported);
        $live = [PredictMarket::STATUS_DRAFT, PredictMarket::STATUS_OPEN, PredictMarket::STATUS_SUSPENDED];

        if (in_array($market->status, $live, true)) {
            $closeAt = $imported->endAt ?? $market->close_at;
            $synced = $this->syncedAttributes($imported, $closeAt, $market->event?->image_url);
            $attributes += array_diff_key($synced, array_flip($market->overridden_fields ?? []));
        }

        $market->update($attributes);

        foreach ([PredictMarket::SIDE_YES => $imported->yesTokenId, PredictMarket::SIDE_NO => $imported->noTokenId] as $side => $token) {
            PredictOutcome::where('predict_market_id', $market->id)->where('side', $side)->update(['external_token_id' => $token]);
        }

        // A draft's price comes from Gamma; live markets are priced by the price sync
        if ($market->status === PredictMarket::STATUS_DRAFT) {
            $this->prices->recordFromGamma($market, $imported);
        }

        $counts['updated']++;

        if ($imported->closed) {
            $result = $market->status === PredictMarket::STATUS_DRAFT
                ? $this->markets->reject($market, 'polymarket-sync', 'Closed on Polymarket before review')
                : $this->markets->close($market, 'polymarket-sync', 'Closed on Polymarket');

            $counts['closed'] += $result->isRefused() ? 0 : 1;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function externalAttributes(ImportedMarket $imported): array
    {
        return [
            'external_market_id' => $imported->externalId,
            'condition_id' => $imported->conditionId,
            'protocol_version' => $imported->version,
            'external_slug' => $imported->slug,
            'external_question' => $imported->question,
            'external_end_at' => $imported->endAt,
            'external_game_start_at' => $imported->gameStartAt,
            'external_closed' => $imported->closed,
            'external_volume' => $imported->volume,
            'external_liquidity' => $imported->liquidity,
            'external_spread' => $imported->spread,
            'external_synced_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function syncedAttributes(ImportedMarket $imported, Carbon $closeAt, ?string $eventImage): array
    {
        return [
            'question' => $imported->question,
            'description' => $imported->description,
            'image_url' => $imported->imageUrl ?? $eventImage,
            'close_at' => $closeAt,
            'resolution_deadline_at' => $closeAt->copy()->addDays((int) config('predict.polymarket.resolution_days')),
        ];
    }
}
