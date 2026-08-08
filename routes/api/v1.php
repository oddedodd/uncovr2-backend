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
use App\Http\Controllers\Api\V1\ContentBlockController;
use App\Http\Controllers\Api\V1\ContributorController;
use App\Http\Controllers\Api\V1\CreditController;
use App\Http\Controllers\Api\V1\DeviceSessionController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\MediaUploadController;
use App\Http\Controllers\Api\V1\OrganizationArtistController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OrganizationInvitationController;
use App\Http\Controllers\Api\V1\OrganizationMembershipController;
use App\Http\Controllers\Api\V1\PageController;
use App\Http\Controllers\Api\V1\ReleaseActivityController;
use App\Http\Controllers\Api\V1\ReleaseArtistController;
use App\Http\Controllers\Api\V1\ReleaseController;
use App\Http\Controllers\Api\V1\ReleaseEditorController;
use App\Http\Controllers\Api\V1\ReleasePublicationController;
use App\Http\Controllers\Api\V1\StreamingLinkController;
use App\Http\Controllers\Api\V1\TrackController;
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

        Route::get('/releases', [ReleaseController::class, 'index'])->name('releases.index');
        Route::post('/releases', [ReleaseController::class, 'store'])->name('releases.store');
        Route::get('/releases/{release}', [ReleaseController::class, 'show'])->name('releases.show');
        Route::patch('/releases/{release}', [ReleaseController::class, 'update'])->name('releases.update');
        Route::delete('/releases/{release}', [ReleaseController::class, 'destroy'])->name('releases.destroy');
        Route::get('/releases/{release}/preview', [ReleasePublicationController::class, 'preview'])->name('releases.preview');
        Route::post('/releases/{release}/submit', [ReleasePublicationController::class, 'submit'])->name('releases.submit');
        Route::post('/releases/{release}/approve', [ReleasePublicationController::class, 'approve'])->name('releases.approve');
        Route::post('/releases/{release}/reject', [ReleasePublicationController::class, 'reject'])->name('releases.reject');
        Route::post('/releases/{release}/schedule', [ReleasePublicationController::class, 'schedule'])->name('releases.schedule');
        Route::post('/releases/{release}/publish', [ReleasePublicationController::class, 'publish'])->name('releases.publish');
        Route::post('/releases/{release}/unpublish', [ReleasePublicationController::class, 'unpublish'])->name('releases.unpublish');
        Route::post('/releases/{release}/archive', [ReleasePublicationController::class, 'archive'])->name('releases.archive');
        Route::get('/releases/{release}/activity', [ReleaseActivityController::class, 'index'])->name('releases.activity.index');
        Route::post('/releases/{release}/artists', [ReleaseArtistController::class, 'store'])->name('releases.artists.store');
        Route::delete('/releases/{release}/artists/{artist}', [ReleaseArtistController::class, 'destroy'])->name('releases.artists.destroy');
        Route::post('/releases/{release}/editors', [ReleaseEditorController::class, 'store'])->name('releases.editors.store');
        Route::delete('/releases/{release}/editors/{user}', [ReleaseEditorController::class, 'destroy'])->name('releases.editors.destroy');

        Route::post('/releases/{release}/tracks', [TrackController::class, 'store'])->name('releases.tracks.store');
        Route::patch('/releases/{release}/tracks/{track}', [TrackController::class, 'update'])->name('releases.tracks.update');
        Route::delete('/releases/{release}/tracks/{track}', [TrackController::class, 'destroy'])->name('releases.tracks.destroy');
        Route::post('/releases/{release}/pages', [PageController::class, 'storeForRelease'])->name('releases.pages.store');
        Route::post('/tracks/{track}/pages', [PageController::class, 'storeForTrack'])->name('tracks.pages.store');
        Route::patch('/pages/{page}', [PageController::class, 'update'])->name('pages.update');
        Route::delete('/pages/{page}', [PageController::class, 'destroy'])->name('pages.destroy');

        Route::post('/pages/{page}/blocks', [ContentBlockController::class, 'store'])->name('pages.blocks.store');
        Route::patch('/pages/{page}/blocks/{block}', [ContentBlockController::class, 'update'])->name('pages.blocks.update');
        Route::delete('/pages/{page}/blocks/{block}', [ContentBlockController::class, 'destroy'])->name('pages.blocks.destroy');
        Route::get('/pages/{page}/blocks/{block}/versions', [ContentBlockController::class, 'versions'])->name('pages.blocks.versions');

        Route::post('/releases/{release}/streaming-links', [StreamingLinkController::class, 'storeForRelease'])->name('releases.streaming-links.store');
        Route::post('/tracks/{track}/streaming-links', [StreamingLinkController::class, 'storeForTrack'])->name('tracks.streaming-links.store');
        Route::patch('/streaming-links/{streamingLink}', [StreamingLinkController::class, 'update'])->name('streaming-links.update');
        Route::delete('/streaming-links/{streamingLink}', [StreamingLinkController::class, 'destroy'])->name('streaming-links.destroy');

        Route::post('/contributors', [ContributorController::class, 'store'])->name('contributors.store');
        Route::get('/contributors/{contributor}', [ContributorController::class, 'show'])->name('contributors.show');
        Route::patch('/contributors/{contributor}', [ContributorController::class, 'update'])->name('contributors.update');
        Route::delete('/contributors/{contributor}', [ContributorController::class, 'destroy'])->name('contributors.destroy');
        Route::post('/releases/{release}/credits', [CreditController::class, 'storeForRelease'])->name('releases.credits.store');
        Route::post('/tracks/{track}/credits', [CreditController::class, 'storeForTrack'])->name('tracks.credits.store');
        Route::patch('/credits/{credit}', [CreditController::class, 'update'])->name('credits.update');
        Route::delete('/credits/{credit}', [CreditController::class, 'destroy'])->name('credits.destroy');

        Route::post('/media', [MediaController::class, 'store'])->name('media.store');
        Route::get('/media/{media}', [MediaController::class, 'show'])->name('media.show');
        Route::patch('/media/{media}', [MediaController::class, 'update'])->name('media.update');
        Route::delete('/media/{media}', [MediaController::class, 'destroy'])->name('media.destroy');
        Route::post('/media/{media}/uploads', [MediaUploadController::class, 'store'])->name('media.uploads.store');
        Route::post('/media/{media}/uploads/{mediaUpload}/complete', [MediaUploadController::class, 'complete'])->name('media.uploads.complete');
        Route::get('/media/{media}/download', [MediaUploadController::class, 'download'])->name('media.download');
    });
