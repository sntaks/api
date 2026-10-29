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
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
        //
    })
    ->withSchedule(function (Schedule $schedule) {
        // Schedule SMSCode expiration every minute
        // $schedule->command('sms:expire-codes')->everyMinute();
        $schedule->command('idempotency:clean')->daily();
        // $schedule->command('logs:clean')->daily();
        // $schedule->command('mpesa:fetch-balances')->hourly();
        $schedule->command('shop-withdrawals:expire')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('finance:snapshot')->dailyAt(config('finance.snapshot_time'))->withoutOverlapping();
    })
    ->create();
