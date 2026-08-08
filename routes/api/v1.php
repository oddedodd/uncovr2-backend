<?php

use App\Http\Controllers\Api\V1\ArtistController;
use App\Http\Controllers\Api\V1\ArtistMembershipController;
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
use App\Http\Controllers\Api\V1\OrganizationArtistController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OrganizationInvitationController;
use App\Http\Controllers\Api\V1\OrganizationMembershipController;
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

        Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
        Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])->name('organizations.show');
        Route::patch('/organizations/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');
        Route::patch('/organizations/{organization}/status', [OrganizationController::class, 'updateStatus'])->name('organizations.status.update');
        Route::get('/organizations/{organization}/members', [OrganizationMembershipController::class, 'index'])->name('organizations.members.index');
        Route::patch('/organizations/{organization}/members/{membership}', [OrganizationMembershipController::class, 'update'])->name('organizations.members.update');
        Route::delete('/organizations/{organization}/members/{membership}', [OrganizationMembershipController::class, 'destroy'])->name('organizations.members.destroy');
        Route::post('/organizations/{organization}/invitations', [OrganizationInvitationController::class, 'store'])->name('organizations.invitations.store');
        Route::post('/organization-invitations/{invitation}/resend', [OrganizationInvitationController::class, 'resend'])->name('organization-invitations.resend');
        Route::post('/organization-invitations/accept', [OrganizationInvitationController::class, 'accept'])->name('organization-invitations.accept');

        Route::get('/artists', [ArtistController::class, 'index'])->name('artists.index');
        Route::post('/artists', [ArtistController::class, 'store'])->name('artists.store');
        Route::get('/artists/{artist}', [ArtistController::class, 'show'])->name('artists.show');
        Route::patch('/artists/{artist}', [ArtistController::class, 'update'])->name('artists.update');
        Route::patch('/artists/{artist}/status', [ArtistController::class, 'updateStatus'])->name('artists.status.update');
        Route::get('/artists/{artist}/members', [ArtistMembershipController::class, 'index'])->name('artists.members.index');
        Route::post('/artists/{artist}/members', [ArtistMembershipController::class, 'store'])->name('artists.members.store');
        Route::patch('/artists/{artist}/members/{membership}', [ArtistMembershipController::class, 'update'])->name('artists.members.update');
        Route::delete('/artists/{artist}/members/{membership}', [ArtistMembershipController::class, 'destroy'])->name('artists.members.destroy');

        Route::post('/organizations/{organization}/artists', [OrganizationArtistController::class, 'store'])->name('organizations.artists.store');
        Route::delete('/organizations/{organization}/artists/{relationship}', [OrganizationArtistController::class, 'destroy'])->name('organizations.artists.destroy');
    });
