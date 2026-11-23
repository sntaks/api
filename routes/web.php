<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\JetLaunchController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\UserController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', function () {
    abort(404, 'Not Found');
});

/*Route::get('/login', [AuthController::class, 'login'])->name('login');
Route::middleware(['throttle:auth'])->group(function () {
    Route::post('/login', [AuthController::class, 'authenticate']);
});
Route::get('/logout', [AuthController::class, 'logout']);

Route::middleware(['auth', 'session.checker'])->group(function () {
    Route::get('/', [\App\Http\Controllers\HomeController::class, 'index'])->name('home');
    Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');

    Route::get('/roles', [RoleController::class, 'index']);
    Route::get('/roles/create', [RoleController::class, 'create']);
    Route::post('/roles/create', [RoleController::class, 'store']);

    Route::get('/users', [UserController::class, 'index'])->name('users');
    Route::get('/users/create', [UserController::class, 'create']);
    Route::post('/users/create', [UserController::class, 'store']);

    Route::get('/customers', [CustomerController::class, 'index']);

    Route::get('/shares', [StockController::class, 'index'])->name('shares');
    Route::get('/shares/create', [StockController::class, 'create']);
    Route::post('/shares/create', [StockController::class, 'store']);

    Route::middleware(['decrypt.identifier'])->group(function () {
        Route::get('/roles/{encryptedIdentifier}', [RoleController::class, 'show']);
        Route::get('/roles/edit/{encryptedIdentifier}', [RoleController::class, 'edit']);
        Route::put('/roles/edit/{encryptedIdentifier}', [RoleController::class, 'update']);

        Route::get('/users/{encryptedIdentifier}', [UserController::class, 'show'])->name('user');
        Route::get('/users/edit/{encryptedIdentifier}', [UserController::class, 'edit']);
        Route::put('/users/edit/{encryptedIdentifier}', [UserController::class, 'update']);
        Route::put('/users/reset/{encryptedIdentifier}', [UserController::class, 'reset']);

        Route::get('/customers/{encryptedIdentifier}', [CustomerController::class, 'show']);

        Route::get('/shares/{encryptedIdentifier}', [StockController::class, 'show']);
        Route::get('/shares/edit/{encryptedIdentifier}', [StockController::class, 'edit']);
        Route::put('/shares/edit/{encryptedIdentifier}', [StockController::class, 'update']);
    });

});
*/

// Kadi Jet launch (https://jet.kadi.online/launch through the proxy): no session, cookie or CSRF token, the
// launch token is the credential and works once
Route::post('/jet/launch', JetLaunchController::class)
    ->withoutMiddleware([
        ValidateCsrfToken::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        AddQueuedCookiesToResponse::class,
        EncryptCookies::class,
    ])
    ->middleware('throttle:jet-session');
