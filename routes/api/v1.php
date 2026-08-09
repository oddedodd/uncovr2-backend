<?php

use App\Http\Controllers\Api\V1\ArtistController;
use App\Http\Controllers\Api\V1\ArtistInvitationController;
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
use App\Http\Controllers\Api\V1\ListenerCollectionController;
use App\Http\Controllers\Api\V1\ListenerInsightsController;
use App\Http\Controllers\Api\V1\ListenerLibraryController;
use App\Http\Controllers\Api\V1\ListenerNotificationController;
use App\Http\Controllers\Api\V1\ListenerPreferenceController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\MediaUploadController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\OrganizationArtistController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OrganizationInvitationController;
use App\Http\Controllers\Api\V1\OrganizationMembershipController;
use App\Http\Controllers\Api\V1\PageController;
use App\Http\Controllers\Api\V1\PlatformController;
use App\Http\Controllers\Api\V1\PrivacyController;
use App\Http\Controllers\Api\V1\PublicCatalogController;
use App\Http\Controllers\Api\V1\PushDeviceController;
use App\Http\Controllers\Api\V1\ReleaseActivityController;
use App\Http\Controllers\Api\V1\ReleaseArtistController;
use App\Http\Controllers\Api\V1\ReleaseController;
use App\Http\Controllers\Api\V1\ReleaseEditorController;
use App\Http\Controllers\Api\V1\ReleasePublicationController;
use App\Http\Controllers\Api\V1\ResendWebhookController;
use App\Http\Controllers\Api\V1\StreamingLinkController;
use App\Http\Controllers\Api\V1\TrackController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserMembershipRoleController;
use App\Http\Middleware\PreventPrivateResponseCaching;
use App\Http\Responses\ApiResponse;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:public')->group(function (): void {
    Route::get('/', fn () => ApiResponse::success([
        'service' => 'uncovr',
        'version' => 'v1',
    ]))->name('index');

    Route::prefix('public')->name('public.')->group(function (): void {
        Route::get('/labels', [PublicCatalogController::class, 'labels'])->name('labels.index');
        Route::get('/labels/{label}', [PublicCatalogController::class, 'label'])->name('labels.show');
        Route::get('/artists', [PublicCatalogController::class, 'artists'])->name('artists.index');
        Route::get('/artists/{artist}', [PublicCatalogController::class, 'artist'])->name('artists.show');
        Route::get('/releases/recent', [PublicCatalogController::class, 'recent'])->name('releases.recent');
        Route::get('/releases/featured', [PublicCatalogController::class, 'featured'])->name('releases.featured');
        Route::get('/releases', [PublicCatalogController::class, 'releases'])->name('releases.index');
        Route::get('/releases/{release}', [PublicCatalogController::class, 'release'])->name('releases.show');
        Route::get('/tracks/{track}', [PublicCatalogController::class, 'track'])->name('tracks.show');
    });
});

Route::prefix('health')->name('health.')->group(function (): void {
    Route::get('/live', [HealthController::class, 'live'])->name('live');
    Route::get('/ready', [HealthController::class, 'ready'])->name('ready');
});

Route::post('/webhooks/resend', ResendWebhookController::class)
    ->middleware('throttle:webhooks')
    ->name('webhooks.resend');

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

Route::middleware(['auth:sanctum', 'active-device-session', 'throttle:authenticated', PreventPrivateResponseCaching::class])
    ->group(function (): void {
        Route::post('/auth/logout', LogoutController::class)->name('auth.logout');
        Route::post('/auth/logout-all', LogoutAllController::class)->name('auth.logout-all');

        Route::get('/me', [MeController::class, 'show'])->name('me.show');
        Route::patch('/me', [MeController::class, 'update'])->name('me.update');
        Route::get('/me/sessions', [DeviceSessionController::class, 'index'])
            ->name('me.sessions.index');
        Route::delete('/me/sessions/{deviceSession}', [DeviceSessionController::class, 'destroy'])
            ->name('me.sessions.destroy');

        Route::get('/me/follows/artists', [ListenerLibraryController::class, 'followedArtists'])->name('me.follows.artists.index');
        Route::put('/me/follows/artists/{artist}', [ListenerLibraryController::class, 'followArtist'])->name('me.follows.artists.store');
        Route::delete('/me/follows/artists/{artist}', [ListenerLibraryController::class, 'unfollowArtist'])->name('me.follows.artists.destroy');
        Route::get('/me/favorites/releases', [ListenerLibraryController::class, 'favoriteReleases'])->name('me.favorites.releases.index');
        Route::put('/me/favorites/releases/{release}', [ListenerLibraryController::class, 'favoriteRelease'])->name('me.favorites.releases.store');
        Route::delete('/me/favorites/releases/{release}', [ListenerLibraryController::class, 'unfavoriteRelease'])->name('me.favorites.releases.destroy');
        Route::get('/me/favorites/tracks', [ListenerLibraryController::class, 'favoriteTracks'])->name('me.favorites.tracks.index');
        Route::put('/me/favorites/tracks/{track}', [ListenerLibraryController::class, 'favoriteTrack'])->name('me.favorites.tracks.store');
        Route::delete('/me/favorites/tracks/{track}', [ListenerLibraryController::class, 'unfavoriteTrack'])->name('me.favorites.tracks.destroy');
        Route::get('/me/collections', [ListenerCollectionController::class, 'index'])->name('me.collections.index');
        Route::post('/me/collections', [ListenerCollectionController::class, 'store'])->name('me.collections.store');
        Route::get('/me/collections/{collection}', [ListenerCollectionController::class, 'show'])->name('me.collections.show');
        Route::patch('/me/collections/{collection}', [ListenerCollectionController::class, 'update'])->name('me.collections.update');
        Route::delete('/me/collections/{collection}', [ListenerCollectionController::class, 'destroy'])->name('me.collections.destroy');
        Route::put('/me/collections/{collection}/items', [ListenerCollectionController::class, 'replaceItems'])->name('me.collections.items.replace');
        Route::get('/me/notification-preferences', [ListenerPreferenceController::class, 'index'])->name('me.notification-preferences.index');
        Route::put('/me/notification-preferences/{topic}', [ListenerPreferenceController::class, 'update'])->name('me.notification-preferences.update');
        Route::put('/me/push-devices/{deviceSession}', [PushDeviceController::class, 'upsert'])->name('me.push-devices.upsert');
        Route::delete('/me/push-devices/{pushDevice}', [PushDeviceController::class, 'destroy'])->name('me.push-devices.destroy');
        Route::get('/me/notifications', [ListenerNotificationController::class, 'index'])->name('me.notifications.index');
        Route::patch('/me/notifications/read-all', [ListenerNotificationController::class, 'readAll'])->name('me.notifications.read-all');
        Route::patch('/me/notifications/{notification}/read', [ListenerNotificationController::class, 'read'])->name('me.notifications.read');
        Route::get('/me/privacy/consents', [PrivacyController::class, 'consents'])->name('me.privacy.consents.index');
        Route::post('/me/privacy/consents', [PrivacyController::class, 'recordConsent'])->name('me.privacy.consents.store');
        Route::get('/me/privacy/export', [PrivacyController::class, 'export'])->name('me.privacy.export');
        Route::get('/me/privacy/deletion', [PrivacyController::class, 'deletionStatus'])->name('me.privacy.deletion.show');
        Route::post('/me/privacy/deletion', [PrivacyController::class, 'requestDeletion'])->name('me.privacy.deletion.store');
        Route::delete('/me/privacy/deletion', [PrivacyController::class, 'cancelDeletion'])->name('me.privacy.deletion.destroy');

        Route::get('/platform/overview', [PlatformController::class, 'overview'])->name('platform.overview');
        Route::post('/platform/organization-onboardings', [OnboardingController::class, 'organization'])->name('platform.organization-onboardings.store');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::patch('/users/{user}/status', [UserController::class, 'updateStatus'])->name('users.status.update');
        Route::patch('/users/{user}/organization-memberships/{membership}/role', [UserMembershipRoleController::class, 'organization'])->name('users.organization-memberships.role.update');
        Route::patch('/users/{user}/artist-memberships/{membership}/role', [UserMembershipRoleController::class, 'artist'])->name('users.artist-memberships.role.update');

        Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
        Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])->name('organizations.show');
        Route::patch('/organizations/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');
        Route::patch('/organizations/{organization}/status', [OrganizationController::class, 'updateStatus'])->name('organizations.status.update');
        Route::get('/organizations/{organization}/listener-insights', [ListenerInsightsController::class, 'organization'])->name('organizations.listener-insights.show');
        Route::get('/organizations/{organization}/members', [OrganizationMembershipController::class, 'index'])->name('organizations.members.index');
        Route::patch('/organizations/{organization}/members/{membership}', [OrganizationMembershipController::class, 'update'])->name('organizations.members.update');
        Route::delete('/organizations/{organization}/members/{membership}', [OrganizationMembershipController::class, 'destroy'])->name('organizations.members.destroy');
        Route::post('/organizations/{organization}/invitations', [OrganizationInvitationController::class, 'store'])->name('organizations.invitations.store');
        Route::post('/organizations/{organization}/artist-onboardings', [OnboardingController::class, 'artist'])->name('organizations.artist-onboardings.store');
        Route::post('/organization-invitations/{invitation}/resend', [OrganizationInvitationController::class, 'resend'])->name('organization-invitations.resend');
        Route::post('/organization-invitations/accept', [OrganizationInvitationController::class, 'accept'])->name('organization-invitations.accept');

        Route::get('/artists', [ArtistController::class, 'index'])->name('artists.index');
        Route::post('/artists', [ArtistController::class, 'store'])->name('artists.store');
        Route::get('/artists/{artist}', [ArtistController::class, 'show'])->name('artists.show');
        Route::patch('/artists/{artist}', [ArtistController::class, 'update'])->name('artists.update');
        Route::patch('/artists/{artist}/status', [ArtistController::class, 'updateStatus'])->name('artists.status.update');
        Route::get('/artists/{artist}/listener-insights', [ListenerInsightsController::class, 'artist'])->name('artists.listener-insights.show');
        Route::get('/artists/{artist}/members', [ArtistMembershipController::class, 'index'])->name('artists.members.index');
        Route::post('/artists/{artist}/members', [ArtistMembershipController::class, 'store'])->name('artists.members.store');
        Route::patch('/artists/{artist}/members/{membership}', [ArtistMembershipController::class, 'update'])->name('artists.members.update');
        Route::delete('/artists/{artist}/members/{membership}', [ArtistMembershipController::class, 'destroy'])->name('artists.members.destroy');
        Route::post('/artists/{artist}/invitations', [ArtistInvitationController::class, 'store'])->name('artists.invitations.store');
        Route::post('/artist-invitations/{invitation}/resend', [ArtistInvitationController::class, 'resend'])->name('artist-invitations.resend');
        Route::post('/artist-invitations/accept', [ArtistInvitationController::class, 'accept'])->name('artist-invitations.accept');

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
        Route::patch('/releases/{release}/featured', [ReleasePublicationController::class, 'feature'])->name('releases.featured.update');
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
