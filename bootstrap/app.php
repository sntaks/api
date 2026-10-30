<?php

use App\Http\Middleware\AuthenticatePlayer;
use App\Http\Middleware\CheckApiKey;
use App\Http\Middleware\CheckUserRole;
use App\Http\Middleware\CheckUserSession;
use App\Http\Middleware\DecryptIdentifier;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\EnsureShopKey;
use App\Http\Middleware\LogRequests;
use App\Http\Middleware\RequireIdempotencyKey;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'apikey.checker' => CheckApiKey::class,
            'decrypt.identifier' => DecryptIdentifier::class,
            'session.checker' => CheckUserSession::class,
            'role.checker' => CheckUserRole::class,
            'idempotency' => EnsureIdempotency::class,
            'idempotency.required' => RequireIdempotencyKey::class,
            'shop.key' => EnsureShopKey::class,
            'log.requests' => LogRequests::class,
            'player.token' => AuthenticatePlayer::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Wager and round routes always answer {success, message, code, retryable}, also for a rate limit or a
        // crash, so a game's outbox can tell what to retry. The exception is still reported and logged as usual.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/v1/wagers') || $request->is('api/v1/wagers/*') || $request->is('api/v1/rounds/*'))) {
                return null;
            }

            if ($e instanceof ThrottleRequestsException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many requests',
                    'code' => 'rate_limited',
                    'retryable' => true,
                ], 429, $e->getHeaders());
            }

            $shapedElsewhere = $e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof AuthorizationException
                || $e instanceof ModelNotFoundException
                || ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500);

            if ($shapedElsewhere) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Server error',
                'code' => 'server_error',
                'retryable' => true,
            ], 500);
        });
    })
    ->withSchedule(function (Schedule $schedule) {
        // Schedule SMSCode expiration every minute
        // $schedule->command('sms:expire-codes')->everyMinute();
        $schedule->command('idempotency:clean')->daily();
        // $schedule->command('logs:clean')->daily();
        // $schedule->command('mpesa:fetch-balances')->hourly();
        $schedule->command('shop-withdrawals:expire')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('finance:snapshot')->dailyAt(config('finance.snapshot_time'))->withoutOverlapping();
        // Launch tokens live 120 seconds and player tokens 12 hours; keep a day of each for audit.
        $schedule->command('launch-tokens:prune')->daily();
        $schedule->command('sanctum:prune-expired --hours=24')->daily();
        $schedule->command('stk-pushes:prune')->daily();
    })
    ->create();
