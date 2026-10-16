<?php

namespace App\Providers;

use App\Models\ApiKey;
use App\Models\User;
use App\Models\Wallet;
use App\Observers\WalletObserver;
use App\Services\WalletWebhookService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(WalletWebhookService $walletWebhookService): void
    {
        Model::unguard();

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

        // Shop systems are limited per shop API key, so one busy shop never slows another.
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
