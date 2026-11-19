<?php

use App\Models\PredictEvent;
use App\Models\PredictMarket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpPredict($this);
});

/**
 * A yes/no question in the local market file format; times are local (EAT) like the real file.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function localQuestion(int $id, array $overrides = []): array
{
    return [
        'id' => $id,
        'category' => 'politics',
        'group' => 'Nairobi',
        'question' => "Will candidate {$id} win?",
        'resolution_criteria' => 'YES if IEBC declares the candidate elected.',
        'resolution_source' => 'IEBC',
        'references' => ['https://www.iebc.or.ke/'],
        'image_url' => 'https://example.com/question.jpg',
        'close_at' => '2027-08-07 23:59',
        'resolution_deadline_at' => '2027-12-31 23:59',
        'gate' => null,
        'start_probability' => 0.4,
        ...$overrides,
    ];
}

/**
 * @param  list<array{key: string, name: string, image_url?: ?string, start_probability: ?float}>  $options
 * @return array<string, mixed>
 */
function localOptionQuestion(int $id, array $options): array
{
    return Arr::except(localQuestion($id, ['question' => 'Who will win the Nairobi governor seat?', 'options' => $options]), ['start_probability']);
}

/**
 * Writes a local market file and returns its path.
 *
 * @param  list<array<string, mixed>>  $markets
 */
function localMarketFile(array $markets): string
{
    $path = tempnam(sys_get_temp_dir(), 'predict-local-');
    file_put_contents($path, json_encode(['key_prefix' => 'ke2027', 'timezone' => 'Africa/Nairobi', 'countries' => ['KE'], 'markets' => $markets]));

    return $path;
}

it('only reports what it would create without --force', function () {
    $path = localMarketFile([localQuestion(2)]);

    $this->artisan('predict:import-local', ['file' => $path])
        ->expectsOutputToContain('Would create 1 draft market(s) for 1 question(s)')
        ->assertSuccessful();

    expect(PredictMarket::count())->toBe(0);
});

it('creates a yes/no question as one Kenyan draft market', function () {
    $path = localMarketFile([localQuestion(2)]);

    $this->artisan('predict:import-local', ['file' => $path, '--force' => true])->assertSuccessful();

    $market = PredictMarket::sole();
    expect($market->only(['source', 'status', 'engine', 'external_market_id', 'predict_event_id', 'question', 'countries', 'start_probability_bp', 'created_by']))->toBe([
        'source' => 'custom',
        'status' => 'draft',
        'engine' => 'pool',
        'external_market_id' => 'ke2027-2',
        'predict_event_id' => null,
        'question' => 'Will candidate 2 win?',
        'countries' => ['KE'],
        'start_probability_bp' => 4000,
        'created_by' => 'ops-cli',
    ])
        ->and($market->close_at->utc()->toDateTimeString())->toBe('2027-08-07 20:59:00')
        ->and($market->description)->toBe("Nairobi\n\nReferences:\nhttps://www.iebc.or.ke/");
});

it('creates a question with more options as an event with one market per option', function () {
    $path = localMarketFile([localOptionQuestion(34, [
        ['key' => 'johnson-sakaja', 'name' => 'Johnson Sakaja', 'image_url' => 'https://example.com/sakaja.jpg', 'start_probability' => 0.45],
        ['key' => 'other', 'name' => 'Other', 'image_url' => null, 'start_probability' => 0.55],
    ])]);

    $this->artisan('predict:import-local', ['file' => $path, '--force' => true])->assertSuccessful();

    $event = PredictEvent::sole();
    expect($event->only(['source', 'external_event_id', 'title']))->toBe(['source' => 'custom', 'external_event_id' => 'ke2027-34', 'title' => 'Who will win the Nairobi governor seat?'])
        ->and($event->markets()->orderBy('id')->get()->map->only(['external_market_id', 'question', 'image_url', 'start_probability_bp'])->all())->toBe([
            ['external_market_id' => 'ke2027-34-johnson-sakaja', 'question' => 'Who will win the Nairobi governor seat? — Johnson Sakaja', 'image_url' => 'https://example.com/sakaja.jpg', 'start_probability_bp' => 4500],
            ['external_market_id' => 'ke2027-34-other', 'question' => 'Who will win the Nairobi governor seat? — Other', 'image_url' => 'https://example.com/question.jpg', 'start_probability_bp' => 5500],
        ]);
});

it('only adds what is missing when run again', function () {
    $sakaja = ['key' => 'johnson-sakaja', 'name' => 'Johnson Sakaja', 'start_probability' => 0.45];
    $this->artisan('predict:import-local', ['file' => localMarketFile([localQuestion(2), localOptionQuestion(34, [$sakaja])]), '--force' => true])->assertSuccessful();

    $this->artisan('predict:import-local', ['file' => localMarketFile([localQuestion(2), localOptionQuestion(34, [$sakaja, ['key' => 'other', 'name' => 'Other', 'start_probability' => 0.55]])]), '--force' => true])
        ->expectsOutputToContain('Created 1 draft market(s) for 1 question(s); 1 question(s) already imported')
        ->assertSuccessful();

    expect(PredictMarket::count())->toBe(3)->and(PredictEvent::count())->toBe(1);
});

it('skips held questions unless asked and never imports excluded ones', function () {
    $path = localMarketFile([localQuestion(78, ['gate' => 'hold']), localQuestion(81, ['gate' => 'excluded'])]);

    $this->artisan('predict:import-local', ['file' => $path, '--force' => true])
        ->expectsOutputToContain('#78 Will candidate 78 win?: skipped (gate: hold)')
        ->assertSuccessful();
    expect(PredictMarket::count())->toBe(0);

    $this->artisan('predict:import-local', ['file' => $path, '--force' => true, '--include-held' => true])
        ->expectsOutputToContain('#81 Will candidate 81 win?: skipped (gate: excluded)')
        ->assertSuccessful();
    expect(PredictMarket::pluck('external_market_id')->all())->toBe(['ke2027-78']);
});

it('skips a whole option question when one option has no odds', function () {
    $path = localMarketFile([localOptionQuestion(34, [
        ['key' => 'johnson-sakaja', 'name' => 'Johnson Sakaja', 'start_probability' => 0.45],
        ['key' => 'other', 'name' => 'Other', 'start_probability' => null],
    ])]);

    $this->artisan('predict:import-local', ['file' => $path, '--force' => true])
        ->expectsOutputToContain('skipped (no start_probability)')
        ->assertSuccessful();

    expect(PredictMarket::count())->toBe(0)->and(PredictEvent::count())->toBe(0);
});

it('skips a question a market could not be created with', function (array $overrides, string $reason) {
    $path = localMarketFile([localQuestion(2, $overrides)]);

    $this->artisan('predict:import-local', ['file' => $path, '--force' => true])
        ->expectsOutputToContain($reason)
        ->assertSuccessful();

    expect(PredictMarket::count())->toBe(0);
})->with([
    'close time passed' => [['close_at' => '2020-01-01 23:59'], 'invalid: The close at field must be a date after now.'],
    'category the licence does not allow' => [['category' => 'crypto'], 'invalid: The selected category is invalid.'],
    'odds outside the pool bounds' => [['start_probability' => 0.995], 'invalid: The start probability field must be between'],
]);

it('accepts economy markets', function () {
    $this->artisan('predict:import-local', ['file' => localMarketFile([localQuestion(73, ['category' => 'economy'])]), '--force' => true])->assertSuccessful();

    expect(PredictMarket::sole()->category)->toBe('economy');
});

it('warns when the option odds of a question do not add up to about 1', function () {
    $path = localMarketFile([localOptionQuestion(34, [
        ['key' => 'johnson-sakaja', 'name' => 'Johnson Sakaja', 'start_probability' => 0.6],
        ['key' => 'other', 'name' => 'Other', 'start_probability' => 0.6],
    ])]);

    $this->artisan('predict:import-local', ['file' => $path])
        ->expectsOutputToContain('option odds add up to 1.20, not about 1')
        ->assertSuccessful();
});

it('fails on a file that is not a market file', function () {
    $this->artisan('predict:import-local', ['file' => '/nonexistent/markets.json'])->assertFailed();
});

it('accepts every question of the committed Kenya 2027 file except the held ones', function () {
    $this->artisan('predict:import-local', ['file' => base_path('database/data/predict/kenya-2027.json')])
        ->expectsOutputToContain('Would create 201 draft market(s) for 66 question(s); 0 question(s) already imported, 3 skipped.')
        ->doesntExpectOutputToContain('invalid:')
        ->assertSuccessful();
});
