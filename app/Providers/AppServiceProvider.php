<?php

namespace App\Providers;

use App\Domain\Jet\RoundRecoveryService;
use App\Models\ApiKey;
use App\Models\User;
use App\Models\Wallet;
use App\Observers\WalletObserver;
use App\Services\WalletWebhookService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\LogFile;
use Opcodes\LogViewer\LogFolder;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The Jet loop lease belongs to the process: jet:run and its RoundScheduler must share one owner
        $this->app->singleton(RoundRecoveryService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(WalletWebhookService $walletWebhookService): void
    {
        Model::unguard();

        // Only the Apache proxy in front of jet.kadi.online is trusted for X-Forwarded-*; none when unset
        if (config('jet.trusted_proxies') !== []) {
            TrustProxies::at(config('jet.trusted_proxies'));
        }

        Wallet::observe(WalletObserver::class);

        // boot() runs on every request under a traditional web server, so this is
        // throttled to roughly once a day rather than logged literally every boot.
        if (! $walletWebhookService->isEnabled()) {
            Cache::remember('wallet-webhook:boot-log', now()->addDay(), function () use ($walletWebhookService) {
                Log::channel('wallet-webhook')->info('disabled_at_boot', [
                    'configured' => $walletWebhookService->isConfigured(),
                ]);

                return true;
            });
        }

        // Callers are told apart by their API key: every request through one server (the GMS, the game
        // server) shares an IP, so limiting by IP would make all of that server's users share one budget.
        // Laravel's middleware priority runs throttling before CheckApiKey, so only an active key gets a
        // budget of its own; a missing or unknown key is limited by IP, so guessing keys is not a way round it.
        $caller = function (Request $request): string {
            if ($request->user()?->id) {
                return 'user:'.$request->user()->id;
            }

            $key = ApiKey::rateLimitProfileFor($request->header('X-API-KEY'));

            return $key ? 'key:'.$key['id'] : 'ip:'.$request->ip();
        };

        // A key's limits are multiplied by its rate_limit_multiplier (php artisan apikey:rate-limit), e.g. for the GMS,
        // which many staff work through. An unknown key gets the standard limits.
        $limit = fn (int $perMinute, Request $request): int => $perMinute * (ApiKey::rateLimitProfileFor($request->header('X-API-KEY'))['multiplier'] ?? 1);

        RateLimiter::for('api', function (Request $request) use ($caller, $limit) {
            return Limit::perMinute($limit(60, $request))->by($caller($request));
        });

        RateLimiter::for('write', function (Request $request) use ($caller, $limit) {
            return Limit::perMinute($limit(30, $request))->by($caller($request));
        });

        // Wager routes: a crash round can bring hundreds of bets a minute through one game key.
        RateLimiter::for('wagers', function (Request $request) use ($caller, $limit) {
            return Limit::perMinute($limit(1200, $request))->by('wagers:'.$caller($request));
        });

        // The player market pages and the polling feed: the Kadi site reads them for all its players through one key.
        RateLimiter::for('predict-read', function (Request $request) use ($caller, $limit) {
            return Limit::perMinute($limit(1200, $request))->by('predict-read:'.$caller($request));
        });

        RateLimiter::for('financial', function (Request $request) use ($caller, $limit) {
            return Limit::perMinute($limit(5, $request))->by($caller($request));
        });

        RateLimiter::for('sensitive', function (Request $request) use ($caller, $limit) {
            return Limit::perMinute($limit(10, $request))->by($caller($request));
        });

        RateLimiter::for('callback', function (Request $request) {
            return Limit::perMinute(100)->by($request->ip());
        });

        RateLimiter::for('stats', function (Request $request) use ($caller, $limit) {
            return Limit::perMinute($limit(20, $request))->by($caller($request));
        });

        // The GMS dashboard (/stats/dashboard, /stats/games, /stats/liability): its own budget, so it never starves finance reports.
        RateLimiter::for('stats-dashboard', function (Request $request) use ($caller, $limit) {
            return Limit::perMinute($limit((int) config('stats.rate_limit_per_minute'), $request))->by('stats-dashboard:'.$caller($request));
        });

        // Shop systems are limited per shop API key, so one busy shop never slows another.
        // Kadi Jet browser routes (no API key): reads per IP, with ceilings sized for many players behind one
        // CGNAT address (throttle runs before jet.player, so no validated player exists yet); money per player
        // token (a hash, never the token)
        RateLimiter::for('jet-reads', fn (Request $request) => Limit::perMinute((int) config('jet.rate_limits.reads_per_ip'))->by('ip:'.$request->ip()));
        RateLimiter::for('jet-presentation', fn (Request $request) => Limit::perMinute((int) config('jet.rate_limits.presentation_per_ip'))->by('ip:'.$request->ip()));
        RateLimiter::for('jet-actions', fn (Request $request) => Limit::perMinute(30)->by('player:'.hash('sha256', (string) ($request->bearerToken() ?? $request->ip()))));
        RateLimiter::for('jet-session', fn (Request $request) => Limit::perMinute(10)->by('ip:'.$request->ip()));

        RateLimiter::for('shop', function (Request $request) use ($caller) {
            return Limit::perMinute((int) config('bet_shops.rate_limits.requests'))->by('shop:'.$caller($request));
        });

        RateLimiter::for('shop-lookup', function (Request $request) use ($caller) {
            return Limit::perMinute((int) config('bet_shops.rate_limits.player_lookups'))->by('shop-lookup:'.$caller($request));
        });

        RateLimiter::for('public', function (Request $request) {
            return Limit::perMinute(100)->by($request->ip());
        });

        // AuthenticateLogViewerBasicAuth middleware is the real gatekeeper here;
        // these gates only need to exist so Log Viewer doesn't block everything in production.
        Gate::define('viewLogViewer', fn (?User $user) => true);
        Gate::define('downloadLogFile', fn (?User $user, LogFile $file) => true);
        Gate::define('downloadLogFolder', fn (?User $user, LogFolder $folder) => true);
        Gate::define('deleteLogFile', fn (?User $user, LogFile $file) => false);
        Gate::define('deleteLogFolder', fn (?User $user, LogFolder $folder) => false);
    }
}
