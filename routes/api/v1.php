<?php

use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutAllController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RefreshTokenController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\ResendVerificationController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\DeviceSessionController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
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
        Route::post('/forgot-password', ForgotPasswordController::class)
            ->name('forgot-password');
        Route::post('/reset-password', ResetPasswordController::class)
            ->name('reset-password');
    });

    Route::post('/refresh', RefreshTokenController::class)
        ->middleware('throttle:refresh')
        ->name('refresh');

    Route::get('/verify-email/{user}/{version}/{hash}', VerifyEmailController::class)
        ->whereUlid('user')
        ->whereNumber('version')
        ->middleware(['signed', 'throttle:public'])
        ->name('verify-email');
});

Route::middleware(['auth:sanctum', 'active-device-session', 'throttle:authenticated'])
    ->group(function (): void {
        Route::post('/auth/logout', LogoutController::class)->name('auth.logout');
        Route::post('/auth/logout-all', LogoutAllController::class)->name('auth.logout-all');

        Route::get('/me', [MeController::class, 'show'])->name('me.show');
        Route::patch('/me', [MeController::class, 'update'])->name('me.update');
        Route::get('/me/sessions', [DeviceSessionController::class, 'index'])
            ->name('me.sessions.index');
        Route::delete('/me/sessions/{deviceSession}', [DeviceSessionController::class, 'destroy'])
            ->name('me.sessions.destroy');
    });
