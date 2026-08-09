<?php

namespace Tests\Feature\Domain;

use App\Contracts\MediaStorage;
use App\Models\ConsentRecord;
use App\Models\DeviceSession;
use App\Models\Media;
use App\Models\PushDevice;
use App\Services\Auth\DeviceSessionRevocationService;
use App\Services\Listeners\InAppNotificationService;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Fakes\FakeMediaStorage;
use Tests\Feature\Domain\Concerns\BuildsReleaseDomain;
use Tests\TestCase;

class ListenerPrivacyTest extends TestCase
{
    use BuildsReleaseDomain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MediaStorage::class, new FakeMediaStorage);
    }

    public function test_follows_and_favorites_are_idempotent_and_private(): void
    {
        [$release, , , $artist, $track] = $this->publishedRelease('Listener Library');
        $listener = $this->domainUser('listener-one@example.com');
        $other = $this->domainUser('listener-two@example.com');
        $this->actAsDomain($listener);

        $this->putApi("/me/follows/artists/{$artist->public_id}")->assertOk();
        $this->putApi("/me/follows/artists/{$artist->public_id}")->assertOk();
        $this->putApi("/me/favorites/releases/{$release->public_id}")->assertOk();
        $this->putApi("/me/favorites/releases/{$release->public_id}")->assertOk();
        $this->putApi("/me/favorites/tracks/{$track->public_id}")->assertOk();
        $this->assertDatabaseCount('artist_follows', 1);
        $this->assertDatabaseCount('release_favorites', 1);
        $this->assertDatabaseCount('track_favorites', 1);
        $this->getApi('/me/follows/artists')->assertOk()->assertJsonPath('data.0.id', $artist->public_id);

        $this->actAsDomain($other);
        $this->getApi('/me/follows/artists')->assertOk()->assertJsonCount(0, 'data');
        $this->getApi('/me/favorites/releases')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteApi("/me/follows/artists/{$artist->public_id}")->assertOk();
        $this->assertDatabaseCount('artist_follows', 1);
    }

    public function test_collections_preserve_order_and_cannot_be_read_by_another_listener(): void
    {
        [$release, $admin, , , $track] = $this->publishedRelease('Private Collection');
        $owner = $this->domainUser('collection-owner@example.com');
        $intruder = $this->domainUser('collection-intruder@example.com');
        $this->actAsDomain($owner);
        $this->putApi("/me/favorites/releases/{$release->public_id}")->assertOk();
        $collectionId = $this->postApi('/me/collections', ['name' => 'My private picks', 'description' => 'Only mine'])
            ->assertCreated()->json('data.id');
        $this->putApi("/me/collections/{$collectionId}/items", ['items' => [
            ['type' => 'track', 'id' => $track->public_id],
            ['type' => 'release', 'id' => $release->public_id],
        ]])->assertOk()->assertJsonPath('data.items.0.position', 1)->assertJsonPath('data.items.0.target.id', $track->public_id)
            ->assertJsonPath('data.items.1.target.id', $release->public_id);

        $this->actAsDomain($admin);
        $this->postApi("/releases/{$release->public_id}/unpublish")->assertOk();
        $this->patchApi("/releases/{$release->public_id}", ['title' => 'Secret revised draft'])->assertOk();
        $this->patchApi("/releases/{$release->public_id}/tracks/{$track->public_id}", ['title' => 'Secret track draft'])->assertOk();
        $this->actAsDomain($owner);
        $this->getApi("/me/collections/{$collectionId}")->assertOk()->assertJsonPath('data.items.0.available', false)
            ->assertJsonMissing(['Secret revised draft', 'Secret track draft']);
        $this->getApi('/me/favorites/releases')->assertJsonCount(0, 'data');

        $this->actAsDomain($intruder);
        $this->getApi("/me/collections/{$collectionId}")->assertNotFound();
        $this->patchApi("/me/collections/{$collectionId}", ['name' => 'Stolen'])->assertNotFound();
        $this->deleteApi("/me/collections/{$collectionId}")->assertNotFound();
        $this->getApi('/me/collections')->assertJsonCount(0, 'data');
    }

    public function test_marketing_preferences_require_separate_consent_and_push_tokens_are_encrypted_and_revoked(): void
    {
        $listener = $this->domainUser('push-listener@example.com');
        $session = $this->mobileSession($listener);
        $this->actAsDomain($listener);
        $preference = ['email_enabled' => true, 'push_enabled' => true, 'in_app_enabled' => true];
        $this->putApi('/me/notification-preferences/marketing', $preference)->assertUnprocessable();
        $this->postApi('/me/privacy/consents', ['purpose' => 'marketing_email', 'granted' => true])->assertCreated();
        $this->postApi('/me/privacy/consents', ['purpose' => 'marketing_push', 'granted' => true])->assertCreated();
        $this->putApi('/me/notification-preferences/marketing', $preference)->assertOk()->assertJsonPath('data.push_enabled', true);
        $this->postApi('/me/privacy/consents', ['purpose' => 'marketing_push', 'granted' => false])->assertCreated();
        $this->getApi('/me/notification-preferences')->assertOk()->assertJsonPath('data.preferences.3.push_enabled', false)
            ->assertJsonPath('data.preferences.3.email_enabled', true);

        $token = str_repeat('ExponentPushToken-secret-', 2);
        $deviceId = $this->putApi("/me/push-devices/{$session->public_id}", ['platform' => 'ios', 'push_token' => $token])
            ->assertCreated()->json('data.id');
        $device = PushDevice::query()->where('public_id', $deviceId)->sole();
        $this->assertSame($token, $device->push_token);
        $this->assertNotSame($token, DB::table('push_devices')->where('id', $device->id)->value('push_token'));
        $this->app->make(DeviceSessionRevocationService::class)->revoke($session, 'test_logout');
        $this->assertNotNull($device->fresh()->disabled_at);
    }

    public function test_notifications_are_private_paginated_and_respect_optional_preferences(): void
    {
        $listener = $this->domainUser('notifications-owner@example.com');
        $other = $this->domainUser('notifications-other@example.com');
        $service = $this->app->make(InAppNotificationService::class);
        $first = $service->create($listener, 'release.published', 'New release', 'Listen now', ['release_id' => '01TEST'], 'release_updates');
        $service->create($other, 'private', 'Other user', 'Secret');
        $this->actAsDomain($listener);
        $this->getApi('/me/notifications?page[size]=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.unread_count', 1)
            ->assertJsonMissing(['Other user', 'Secret']);
        $this->patchApi("/me/notifications/{$first->public_id}/read")->assertOk()->assertJsonPath('data.read_at', fn ($value) => is_string($value));

        $this->putApi('/me/notification-preferences/release_updates', ['email_enabled' => false, 'push_enabled' => false, 'in_app_enabled' => false])->assertOk();
        $this->assertNull($service->create($listener, 'release.published', 'Hidden', 'Hidden', topic: 'release_updates'));
        $this->assertNotNull($service->create($listener, 'security.required', 'Security', 'Required', topic: 'release_updates', required: true));

        $this->actAsDomain($other);
        $this->patchApi("/me/notifications/{$first->public_id}/read")->assertNotFound();
    }

    public function test_export_contains_only_the_listener_data_and_due_deletion_anonymizes_it(): void
    {
        [$release, , , $artist] = $this->publishedRelease('Privacy Export');
        $listener = $this->domainUser('delete-me@example.com');
        $session = $this->mobileSession($listener);
        $this->actAsDomain($listener);
        $this->putApi("/me/follows/artists/{$artist->public_id}")->assertOk();
        $this->putApi("/me/favorites/releases/{$release->public_id}")->assertOk();
        $this->postApi('/me/privacy/consents', ['purpose' => 'analytics', 'granted' => false])->assertCreated();
        $pushId = $this->putApi("/me/push-devices/{$session->public_id}", ['platform' => 'ios', 'push_token' => str_repeat('deletion-token-', 3)])->assertCreated()->json('data.id');
        $this->getApi('/me/privacy/export')->assertOk()->assertHeader('Content-Disposition')
            ->assertJsonPath('data.account.email', 'delete-me@example.com')->assertJsonCount(1, 'data.followed_artists')
            ->assertJsonMissingPath('data.push_devices.0.push_token');
        $this->postApi('/me/privacy/deletion', ['password' => 'password', 'confirmation' => 'DELETE'])->assertAccepted();
        $this->assertNotNull($listener->fresh()->deletion_requested_at);
        $this->assertNotNull(PushDevice::query()->where('public_id', $pushId)->sole()->disabled_at);
        $listener->accountDeletionRequest()->update(['scheduled_for' => now()->subSecond()]);
        $this->artisan('privacy:process-account-deletions')->expectsOutput('Processed 1 account deletion(s).')->assertSuccessful();
        $anonymized = $listener->fresh();
        $this->assertNotNull($anonymized->anonymized_at);
        $this->assertStringEndsWith('@users.invalid', $anonymized->email);
        $this->assertSame('Deleted user', $anonymized->profile->display_name);
        $this->assertDatabaseMissing('artist_follows', ['user_id' => $listener->id]);
        $this->assertDatabaseMissing('release_favorites', ['user_id' => $listener->id]);
        $this->assertDatabaseHas('consent_records', ['user_id' => $listener->id, 'purpose' => 'analytics']);
    }

    public function test_scheduled_deletion_can_be_cancelled_during_the_grace_period(): void
    {
        $listener = $this->domainUser('cancel-deletion@example.com');
        $this->actAsDomain($listener);
        $this->postApi('/me/privacy/deletion', ['password' => 'wrong-password', 'confirmation' => 'DELETE'])->assertUnprocessable();
        $this->postApi('/me/privacy/deletion', ['password' => 'password', 'confirmation' => 'DELETE'])->assertAccepted();
        $this->deleteApi('/me/privacy/deletion')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertNull($listener->fresh()->deletion_requested_at);
        $this->artisan('privacy:process-account-deletions')->expectsOutput('Processed 0 account deletion(s).')->assertSuccessful();
        $this->assertNull($listener->fresh()->anonymized_at);
    }

    public function test_scope_insights_are_aggregate_only_and_cross_tenant_access_is_rejected(): void
    {
        [$release, $admin, $organization, $artist, $track] = $this->publishedRelease('Aggregate Insights');
        $listener = $this->domainUser('insight-listener@example.com');
        $this->actAsDomain($listener);
        $this->putApi("/me/follows/artists/{$artist->public_id}")->assertOk();
        $this->putApi("/me/favorites/releases/{$release->public_id}")->assertOk();
        $this->putApi("/me/favorites/tracks/{$track->public_id}")->assertOk();
        $this->actAsDomain($admin);
        $this->getApi("/organizations/{$organization->public_id}/listener-insights")->assertOk()
            ->assertJsonPath('data.totals.artist_followers', 1)->assertJsonPath('data.totals.release_favorites', 1)
            ->assertJsonMissing(['insight-listener@example.com', $listener->public_id]);
        $this->getApi("/artists/{$artist->public_id}/listener-insights")->assertOk()->assertJsonPath('data.totals.track_favorites', 1);
        $outsider = $this->domainUser('insight-outsider@example.com');
        $this->actAsDomain($outsider);
        $this->getApi("/organizations/{$organization->public_id}/listener-insights")->assertForbidden();
    }

    public function test_consent_records_are_immutable(): void
    {
        $listener = $this->domainUser('immutable-consent@example.com');
        $consent = ConsentRecord::query()->create(['user_id' => $listener->id, 'purpose' => 'analytics', 'granted' => true, 'policy_version' => '1', 'source' => 'settings', 'recorded_at' => now()]);
        $this->expectException(LogicException::class);
        $consent->update(['granted' => false]);
    }

    private function publishedRelease(string $title): array
    {
        $admin = $this->domainUser(str($title)->slug().'@example.com');
        $organization = $this->domainOrganization($admin, "{$title} Label");
        $artist = $this->domainArtist($admin, "{$title} Artist");
        $this->linkArtist($organization, $artist, $admin);
        $cover = Media::query()->create([
            'organization_id' => $organization->id, 'kind' => 'image', 'status' => 'ready', 'original_filename' => 'cover.png',
            'mime_type' => 'image/png', 'byte_size' => 100, 'width' => 1200, 'height' => 1200,
            'storage_disk' => 'uncovr-private-media', 'storage_key' => 'covers/'.str($title)->slug().'.png',
            'verified_at' => now(), 'created_by_user_id' => $admin->id, 'updated_by_user_id' => $admin->id,
        ]);
        $release = $this->createOrganizationRelease($admin, $organization, $artist, ['title' => $title, 'cover_media_id' => $cover->public_id]);
        $trackId = $this->postApi("/releases/{$release->public_id}/tracks", ['position' => 1, 'title' => "{$title} Track", 'duration_ms' => 180000, 'is_explicit' => false])->assertCreated()->json('data.id');
        $this->postApi("/releases/{$release->public_id}/submit")->assertCreated();
        $this->postApi("/releases/{$release->public_id}/approve")->assertOk();
        $this->postApi("/releases/{$release->public_id}/publish")->assertOk();

        return [$release->fresh(), $admin, $organization, $artist, $release->tracks()->where('public_id', $trackId)->sole()];
    }

    private function mobileSession($user): DeviceSession
    {
        return DeviceSession::query()->create([
            'user_id' => $user->id, 'client_type' => 'mobile', 'device_name' => 'Test iPhone', 'platform' => 'ios',
            'last_ip_address' => '127.0.0.1', 'user_agent' => 'Uncovr/Test', 'last_used_at' => now(),
            'idle_expires_at' => now()->addHour(), 'absolute_expires_at' => now()->addDay(),
        ]);
    }
}
