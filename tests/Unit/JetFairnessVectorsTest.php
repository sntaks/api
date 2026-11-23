<?php

use App\Domain\Jet\CrashPointGenerator;
use App\Domain\Jet\ProvablyFairService;

/**
 * The shared vectors (crash tests/Fixtures/fairness-vectors.json, identical in the Godot client) must keep
 * verifying, so a published Jet proof is checkable by the same client code.
 */
it('verifies every crash fairness vector and rejects a tampered seed', function () {
    $fair = new ProvablyFairService(new CrashPointGenerator);
    $vectors = json_decode(file_get_contents(__DIR__.'/../Fixtures/jet-fairness-vectors.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($vectors)->not->toBeEmpty();

    foreach ($vectors as $round) {
        $args = [$round['client_seed'], (int) $round['nonce'], (float) $round['proof']['house_edge'], (float) $round['proof']['max_multiplier_cap'], (float) $round['crash_multiplier']];

        expect($fair->verify($round['server_seed'], $round['server_seed_hash'], ...$args))->toBeTrue("round {$round['round_number']}")
            ->and($fair->verify($round['server_seed'].'x', $round['server_seed_hash'], ...$args))->toBeFalse();
    }
});
