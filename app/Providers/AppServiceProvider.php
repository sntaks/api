<?php

namespace App\Providers;

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

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('write', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('financial', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('sensitive', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('callback', function (Request $request) {
            return Limit::perMinute(100)->by($request->ip());
        });

        RateLimiter::for('stats', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        // Shop systems are limited per shop API key, so one busy shop never slows another.
        RateLimiter::for('shop', function (Request $request) {
            return Limit::perMinute((int) config('bet_shops.rate_limits.requests'))->by('shop:'.hash('sha256', (string) $request->header('X-API-KEY')));
        });

        RateLimiter::for('shop-lookup', function (Request $request) {
            return Limit::perMinute((int) config('bet_shops.rate_limits.player_lookups'))->by('shop-lookup:'.hash('sha256', (string) $request->header('X-API-KEY')));
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
