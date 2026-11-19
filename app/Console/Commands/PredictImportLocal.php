<?php

namespace App\Console\Commands;

use App\Http\Requests\Predict\PredictMarketTerms;
use App\Http\Requests\Predict\StorePredictMarketRequest;
use App\Models\PredictMarket;
use App\Services\Predict\MarketService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Loads custom draft markets from a local JSON file (e.g. database/data/predict/kenya-2027.json). A yes/no
 * question is one market; a question with more options is a custom event with one binary market per option.
 * Every market is keyed by "<key_prefix>-<id>[-<option key>]", so a rerun only adds what is missing.
 */
class PredictImportLocal extends Command
{
    /** Option odds of one question should describe one outcome; outside this total the command warns */
    private const OPTION_SUM_TOLERANCE = 0.1;

    /**
     * @var string
     */
    protected $signature = 'predict:import-local
                            {file : Path to the market file}
                            {--force : Create the drafts; without it the command only reports what it would do}
                            {--include-held : Also import questions on hold (excluded ones never are)}
                            {--admin-user=ops-cli : Who the audit log names}';

    /**
     * @var string
     */
    protected $description = 'Create custom draft markets from a local market file (a dry run unless --force)';

    public function handle(MarketService $markets): int
    {
        $path = (string) $this->argument('file');
        $file = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($file) || ! isset($file['key_prefix'], $file['timezone'], $file['countries'], $file['markets'])) {
            $this->error("{$path} is missing or not a market file.");

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $totals = ['created' => 0, 'questions' => 0, 'existing' => 0, 'skipped' => 0];

        foreach ($file['markets'] as $question) {
            $label = "#{$question['id']} {$question['question']}";
            $planned = $this->plannedMarkets($file, $question);
            $skip = $this->skipReason($question, $planned);

            if ($skip !== null) {
                $this->line("{$label}: skipped ({$skip})");
                $totals['skipped']++;

                continue;
            }

            $existing = PredictMarket::where('source', PredictMarket::SOURCE_CUSTOM)->whereIn('external_market_id', array_keys($planned))->pluck('external_market_id')->all();
            $missing = Arr::except($planned, $existing);

            if ($missing === []) {
                $totals['existing']++;

                continue;
            }

            if ($warning = $this->oddsWarning($question)) {
                $this->warn("{$label}: {$warning}");
            }

            if ($force) {
                DB::transaction(function () use ($markets, $file, $question, $missing) {
                    $event = isset($question['options']) ? $markets->customEvent("{$file['key_prefix']}-{$question['id']}", [
                        'title' => $question['question'],
                        'description' => $question['group'] ?? null,
                        'image_url' => $question['image_url'] ?? null,
                        'category' => $question['category'],
                        'ends_at' => $this->time($question['close_at'], $file['timezone']),
                    ]) : null;

                    foreach ($missing as $externalId => $data) {
                        $markets->createCustom($data, (string) $this->option('admin-user'), $event, $externalId);
                    }
                });
            }

            $this->line(sprintf('%s: %s %d market(s)', $label, $force ? 'created' : 'would create', count($missing)));
            $totals['created'] += count($missing);
            $totals['questions']++;
        }

        $this->info(sprintf(
            '%s %d draft market(s) for %d question(s); %d question(s) already imported, %d skipped.%s',
            $force ? 'Created' : 'Would create',
            $totals['created'],
            $totals['questions'],
            $totals['existing'],
            $totals['skipped'],
            $force ? '' : ' Run with --force to create them.',
        ));

        return self::SUCCESS;
    }

    /**
     * The createCustom payload of every market a question becomes, keyed by external id.
     *
     * @param  array<string, mixed>  $file
     * @param  array<string, mixed>  $question
     * @return array<string, array<string, mixed>>
     */
    private function plannedMarkets(array $file, array $question): array
    {
        $references = $question['references'] ?? [];
        $shared = [
            'description' => trim(($question['group'] ?? '').($references ? "\n\nReferences:\n".implode("\n", $references) : '')) ?: null,
            'resolution_criteria' => $question['resolution_criteria'],
            'resolution_source' => $question['resolution_source'] ?? null,
            'category' => $question['category'],
            'countries' => $file['countries'],
            'close_at' => $this->time($question['close_at'], $file['timezone']),
            'resolution_deadline_at' => $this->time($question['resolution_deadline_at'], $file['timezone']),
        ];
        $key = "{$file['key_prefix']}-{$question['id']}";

        if (! isset($question['options'])) {
            return [$key => [...$shared, 'question' => $question['question'], 'image_url' => $question['image_url'] ?? null, 'start_probability' => $question['start_probability'] ?? null]];
        }

        $planned = [];

        foreach ($question['options'] as $option) {
            $planned["{$key}-{$option['key']}"] = [
                ...$shared,
                'question' => "{$question['question']} — {$option['name']}",
                'image_url' => $option['image_url'] ?? $question['image_url'] ?? null,
                'start_probability' => $option['start_probability'] ?? null,
            ];
        }

        return $planned;
    }

    /**
     * Why a question is not imported: its risk gate, missing odds, or the first rule one of its markets breaks.
     *
     * @param  array<string, mixed>  $question
     * @param  array<string, array<string, mixed>>  $planned
     */
    private function skipReason(array $question, array $planned): ?string
    {
        $gate = $question['gate'] ?? null;

        if ($gate === 'excluded' || ($gate === 'hold' && ! $this->option('include-held'))) {
            return "gate: {$gate}";
        }

        if (collect($planned)->contains(fn (array $data) => $data['start_probability'] === null)) {
            return 'no start_probability';
        }

        $rules = Arr::except((new StorePredictMarketRequest)->rules(), ['admin_user']);

        foreach ($planned as $data) {
            $validator = Validator::make($data, $rules)->after(fn ($validator) => PredictMarketTerms::check($validator, Arr::only($data, ['start_probability'])));

            if ($validator->fails()) {
                return 'invalid: '.$validator->errors()->first();
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $question
     */
    private function oddsWarning(array $question): ?string
    {
        if (! isset($question['options'])) {
            return null;
        }

        $sum = array_sum(array_map(fn (array $option) => (float) $option['start_probability'], $question['options']));

        return abs($sum - 1) > self::OPTION_SUM_TOLERANCE ? sprintf('option odds add up to %.2f, not about 1', $sum) : null;
    }

    private function time(string $localTime, string $timezone): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d H:i', $localTime, $timezone)->setTimezone(config('app.timezone'));
    }
}
