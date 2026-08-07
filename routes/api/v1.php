<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\ResendVerificationController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Responses\ApiResponse;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:public')->group(function (): void {
    Route::get('/', fn () => ApiResponse::success([
        'service' => 'uncovr',
        'version' => 'v1',
    ]))->name('index');
});

Route::prefix('health')->name('health.')->group(function (): void {
    Route::get('/live', [HealthController::class, 'live'])->name('live');
    Route::get('/ready', [HealthController::class, 'ready'])->name('ready');
});

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::middleware('throttle:authentication')->group(function (): void {
        Route::post('/login', LoginController::class)->name('login');
        Route::post('/register', RegisterController::class)->name('register');
        Route::post('/resend-verification', ResendVerificationController::class)
            ->name('resend-verification');
    });

    Route::get('/verify-email/{user}/{version}/{hash}', VerifyEmailController::class)
        ->whereUlid('user')
        ->whereNumber('version')
        ->middleware(['signed', 'throttle:public'])
        ->name('verify-email');
});
