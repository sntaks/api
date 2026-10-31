<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route as Router;

/**
 * Routes deliberately left out of public/documentation.html: Safaricom callbacks, and features whose
 * documentation was removed while their routes stay live (coins, purchases, playground).
 */
const UNDOCUMENTED_ROUTES = [
    // Safaricom callbacks and URL registration
    'GET /api/v1/c2b/register', 'POST /api/v1/c2b/confirm', 'POST /api/v1/c2b/validate', 'POST /api/v1/km/c2b/confirm', 'POST /api/v1/km/c2b/validate',
    'POST /api/v1/b2c/result', 'POST /api/v1/b2c/timeout', 'POST /api/v1/balance/b2c/result', 'POST /api/v1/balance/b2c/timeout',
    'POST /api/v1/balance/c2b/result', 'POST /api/v1/balance/c2b/timeout', 'POST /api/v1/stk/callback',
    'POST /api/v1/referral/b2c/result', 'POST /api/v1/referral/b2c/timeout', 'POST /api/v1/referral/balance/b2c/result', 'POST /api/v1/referral/balance/b2c/timeout',
    // Direct B2C send: internal only, kept off the public page
    'POST /api/v1/b2c/send',
    // Coins
    'GET /api/v1/coins', 'GET /api/v1/coins/{encryptedIdentifier}', 'PUT /api/v1/coins/{encryptedIdentifier}', 'POST /api/v1/coins/buy/{encryptedIdentifier}',
    'PUT /api/v1/coins/exchange/{encryptedIdentifier}', 'POST /api/v1/coins/transfer/{encryptedIdentifier}',
    // Purchases
    'GET /api/v1/purchases', 'GET /api/v1/purchases/{encryptedIdentifier}', 'DELETE /api/v1/purchases/{encryptedIdentifier}', 'POST /api/v1/purchases/referrals',
    'GET /api/v1/customers/purchases/{encryptedIdentifier}', 'GET /api/v1/stats/purchases', 'POST /api/v1/stats/purchases/referrals',
    // Playground
    'GET /api/v1/playground', 'POST /api/v1/playground', 'GET /api/v1/playground/{encryptedIdentifier}', 'PUT /api/v1/playground/{encryptedIdentifier}',
    'DELETE /api/v1/playground/{encryptedIdentifier}',
];

/**
 * Every endpoint on the docs page, keyed "METHOD /path", with the tags shown on it.
 *
 * @return Collection<string, list<string>>
 */
function documentedEndpoints(): Collection
{
    $page = file_get_contents(public_path('documentation.html'));
    $blocks = array_slice(explode('<div class="endpoint">', $page), 1);

    return collect($blocks)->mapWithKeys(function (string $block) {
        preg_match('/class="method method-\w+">(\w+)</', $block, $method);
        preg_match('/class="endpoint-path">([^<]+)</', $block, $path);
        preg_match('/<div class="tags">(.*?)<\/div>/s', $block, $tagHtml);
        preg_match_all('/<span class="tag (tag-\w+)">([^<]+)<\/span>/', $tagHtml[1] ?? '', $tags, PREG_SET_ORDER);

        $labels = array_map(fn (array $tag) => $tag[1] === 'tag-rate' ? 'rate:'.$tag[2] : $tag[1], $tags);
        sort($labels);

        return [$method[1].' '.html_entity_decode($path[1]) => $labels];
    });
}

/**
 * Every API v1 route, keyed "METHOD /path", with the tags its middleware calls for.
 *
 * @return Collection<string, list<string>>
 */
function apiRoutes(): Collection
{
    $limits = ['wagers' => '1200/min', 'write' => '30/min', 'financial' => '5/min', 'stats' => '20/min', 'callback' => '100/min'];

    return collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/v1/'))
        ->flatMap(function (Route $route) use ($limits) {
            $middleware = $route->gatherMiddleware();
            $tags = [];
            if (in_array('apikey.checker', $middleware, true)) {
                $tags[] = 'tag-auth';
            }
            if (in_array('decrypt.identifier', $middleware, true) && str_contains($route->uri(), '{encryptedIdentifier}')) {
                $tags[] = 'tag-encrypted';
            }
            if (in_array('idempotency', $middleware, true)) {
                $tags[] = 'tag-idempotent';
            }
            foreach ($middleware as $name) {
                if (str_starts_with($name, 'throttle:') && isset($limits[substr($name, 9)])) {
                    $tags[] = 'rate:'.$limits[substr($name, 9)];
                }
            }
            $tags = array_values(array_unique($tags));
            sort($tags);

            return collect($route->methods())
                ->reject(fn (string $method) => $method === 'HEAD')
                ->mapWithKeys(fn (string $method) => [$method.' /'.$route->uri() => $tags]);
        });
}

it('documents every API route', function () {
    $missing = apiRoutes()->keys()
        ->diff(documentedEndpoints()->keys())
        ->diff(UNDOCUMENTED_ROUTES)
        ->values()
        ->all();

    expect($missing)->toBe([], 'Add these routes to public/documentation.html, or to UNDOCUMENTED_ROUTES with a reason.');
});

it('only documents routes that exist', function () {
    $stale = documentedEndpoints()->keys()->diff(apiRoutes()->keys())->values()->all();

    expect($stale)->toBe([], 'These endpoints are on the docs page but have no route.');
});

it('keeps the undocumented list to real, undocumented routes', function () {
    $routes = apiRoutes()->keys();
    $documented = documentedEndpoints()->keys();

    expect(collect(UNDOCUMENTED_ROUTES)->diff($routes)->values()->all())->toBe([], 'These excluded routes no longer exist.')
        ->and(collect(UNDOCUMENTED_ROUTES)->intersect($documented)->values()->all())->toBe([], 'These excluded routes are documented after all.');
});

it('shows each endpoint\'s API key, encrypted id, idempotency and rate limit tags as its route applies them', function () {
    $routes = apiRoutes();

    $wrong = documentedEndpoints()
        ->filter(fn (array $tags, string $endpoint) => $routes->has($endpoint) && $routes[$endpoint] !== $tags)
        ->map(fn (array $tags, string $endpoint) => $endpoint.': page ['.implode(', ', $tags).'], route ['.implode(', ', $routes[$endpoint]).']')
        ->values()
        ->all();

    expect($wrong)->toBe([]);
});

it('documents each endpoint once', function () {
    $page = file_get_contents(public_path('documentation.html'));
    preg_match_all('/class="method method-\w+">(\w+)<\/span>\s*<span class="endpoint-path">([^<]+)</', $page, $matches, PREG_SET_ORDER);

    $duplicates = collect($matches)->map(fn (array $match) => $match[1].' '.$match[2])->duplicates()->values()->all();

    expect($duplicates)->toBe([]);
});

it('points every game page step at an endpoint on the page', function () {
    $page = file_get_contents(public_path('documentation.html'));
    $slugs = documentedEndpoints()->keys()->map(function (string $endpoint) {
        [$method, $path] = explode(' ', $endpoint, 2);

        return trim(strtolower($method).'-'.preg_replace('/[^a-z0-9]+/', '-', strtolower(substr($path, strlen('/api/v1/')))), '-');
    });
    preg_match_all('/class="game-step" data-endpoint="([^"]+)"/', $page, $steps);
    $referenced = collect($steps[1])->flatMap(fn (string $list) => explode(' ', $list))->unique();

    expect($referenced)->not->toBeEmpty()
        ->and($referenced->diff($slugs)->values()->all())->toBe([], 'These game page steps name an endpoint that is not on the page.');
});
