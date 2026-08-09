<?php

namespace Tests\Feature\Domain;

use App\Enums\ArtistRole;
use App\Enums\OrganizationRole;
use App\Models\Artist;
use App\Models\DeviceSession;
use App\Models\Organization;
use App\Models\RefreshToken;
use App\Models\Release;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformAdministrationTest extends TestCase
{
    private const PASSWORD = 'a secure passphrase';

    public function test_platform_overview_is_superadmin_only_and_returns_status_breakdowns(): void
    {
        $superadmin = $this->user('root@example.com', 'Root', true);
        $member = $this->user('member@example.com', 'Member');
        $suspended = $this->user('suspended@example.com', 'Suspended');
        $suspended->forceFill([
            'status' => 'suspended',
            'suspended_at' => now(),
            'suspension_reason' => 'Confirmed policy violation.',
        ])->save();
        $organization = $this->organization($member, 'North Records');
        $artist = $this->artist($member, 'North Artist');
        $this->releaseForOrganization($organization, 'Draft Album', 'draft');
        $this->releaseForArtist($artist, 'Published Single', 'published');

        $this->getApi('/platform/overview')->assertUnauthorized();
        Sanctum::actingAs($member, ['portal:access']);
        $this->getApi('/platform/overview')->assertForbidden();

        Sanctum::actingAs($superadmin, ['portal:access']);
        $this->getApi('/platform/overview')
            ->assertOk()
            ->assertJsonPath('data.users.total', 3)
            ->assertJsonPath('data.users.by_status.active', 2)
            ->assertJsonPath('data.users.by_status.suspended', 1)
            ->assertJsonPath('data.users.superadmins', 1)
            ->assertJsonPath('data.organizations.total', 1)
            ->assertJsonPath('data.organizations.by_status.active', 1)
            ->assertJsonPath('data.artists.total', 1)
            ->assertJsonPath('data.releases.total', 2)
            ->assertJsonPath('data.releases.by_status.draft', 1)
            ->assertJsonPath('data.releases.by_status.published', 1)
            ->assertJsonPath('data.releases.by_status.archived', 0);
    }

    public function test_user_detail_returns_memberships_and_resource_hierarchy_to_superadmins_only(): void
    {
        $superadmin = $this->user('root@example.com', 'Root', true);
        $target = $this->user('target@example.com', 'Target');
        $organization = $this->organization($target, 'Hierarchy Label');
        $artist = $this->artist($target, 'Hierarchy Artist');
        $relationship = $organization->artistRelationships()->create([
            'artist_id' => $artist->id,
            'relationship_type' => 'managing_label',
            'created_by_user_id' => $target->id,
            'started_at' => now(),
        ]);
        $organizationRelease = $this->releaseForOrganization($organization, 'Label Release', 'draft');
        $artistRelease = $this->releaseForArtist($artist, 'Artist Release', 'review');
        $organizationRelease->editorAssignments()->create([
            'user_id' => $target->id,
            'granted_by_user_id' => $superadmin->id,
        ]);

        Sanctum::actingAs($target, ['portal:access']);
        $this->getApi("/users/{$target->public_id}")->assertForbidden();

        Sanctum::actingAs($superadmin, ['portal:access']);
        $response = $this->getApi("/users/{$target->public_id}")
            ->assertOk()
            ->assertJsonPath('data.id', $target->public_id)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.memberships.organizations.0.organization.id', $organization->public_id)
            ->assertJsonPath('data.memberships.organizations.0.organization.artists.0.relationship_id', $relationship->public_id)
            ->assertJsonPath('data.memberships.organizations.0.organization.artists.0.id', $artist->public_id)
            ->assertJsonPath('data.memberships.organizations.0.organization.releases.0.id', $organizationRelease->public_id)
            ->assertJsonPath('data.memberships.artists.0.artist.id', $artist->public_id)
            ->assertJsonPath('data.memberships.artists.0.artist.organizations.0.id', $organization->public_id)
            ->assertJsonPath('data.memberships.artists.0.artist.releases.0.id', $artistRelease->public_id)
            ->assertJsonPath('data.release_editor_assignments.0.id', $organizationRelease->public_id);

        $response->assertJsonMissingPath('data.memberships.organizations.0.organization.internal_id');
    }

    public function test_account_suspension_requires_confirmation_revokes_access_and_is_audited(): void
    {
        Carbon::setTestNow('2026-08-09 12:00:00');
        $superadmin = $this->user('root@example.com', 'Root', true);
        $target = $this->user('target@example.com', 'Target');
        [$session, $refreshToken, $plainRefreshToken] = $this->activeMobileSession($target);
        $payload = [
            'status' => 'suspended',
            'reason' => 'Confirmed repeated abuse of platform access.',
            'confirmation' => $target->public_id,
        ];

        Sanctum::actingAs($target, ['portal:access']);
        $this->patchApi("/users/{$target->public_id}/status", $payload)->assertForbidden();

        Sanctum::actingAs($superadmin, ['portal:access']);
        $this->patchApi("/users/{$target->public_id}/status", [
            ...$payload,
            'confirmation' => $superadmin->public_id,
        ])->assertUnprocessable();

        $this->patchApi("/users/{$target->public_id}/status", $payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspended_at', '2026-08-09T12:00:00.000Z')
            ->assertJsonPath('data.suspension_reason', $payload['reason']);

        $target->refresh();
        $this->assertSame('suspended', $target->status->value);
        $this->assertSame('account_suspended', $session->fresh()->revocation_reason);
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertNotNull($refreshToken->fresh()->revoked_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $event = SecurityAuditEvent::query()->where('event_type', 'user.account_suspended')->sole();
        $this->assertSame($superadmin->id, $event->user_id);
        $this->assertSame($target->public_id, $event->metadata['target_user_id']);
        $this->assertSame($payload['reason'], $event->metadata['reason']);
        $this->assertSame(1, $event->metadata['revoked_sessions']);

        $this->postApi('/auth/login', $this->loginPayload($target->email))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'account_suspended');
        $this->postApi('/auth/refresh', ['refresh_token' => $plainRefreshToken])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_refresh_token');

        Sanctum::actingAs($target, ['portal:access']);
        $this->getApi('/me')->assertUnauthorized();

    }

    public function test_account_restoration_is_audited_and_does_not_restore_old_sessions(): void
    {
        $superadmin = $this->user('root@example.com', 'Root', true);
        $target = $this->user('target@example.com', 'Target');
        [$session] = $this->activeMobileSession($target);
        Sanctum::actingAs($superadmin, ['portal:access']);

        $this->patchApi("/users/{$target->public_id}/status", [
            'status' => 'suspended',
            'reason' => 'Temporary suspension pending account review.',
            'confirmation' => $target->public_id,
        ])->assertOk();
        $this->patchApi("/users/{$target->public_id}/status", [
            'status' => 'active',
            'reason' => 'Manual review completed and access approved.',
            'confirmation' => $target->public_id,
        ])->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.suspended_at', null)
            ->assertJsonPath('data.suspension_reason', null);

        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertDatabaseHas('security_audit_events', ['event_type' => 'user.account_restored']);
        $this->postApi('/auth/login', $this->loginPayload($target->email))->assertOk();
    }

    public function test_superadmin_cannot_self_suspend_or_repeat_an_existing_status(): void
    {
        $superadmin = $this->user('root@example.com', 'Root', true);
        Sanctum::actingAs($superadmin, ['portal:access']);

        $this->patchApi("/users/{$superadmin->public_id}/status", [
            'status' => 'suspended',
            'reason' => 'Attempting a prohibited self suspension.',
            'confirmation' => $superadmin->public_id,
        ])->assertConflict();

        $target = $this->user('target@example.com', 'Target');
        $this->patchApi("/users/{$target->public_id}/status", [
            'status' => 'active',
            'reason' => 'No status transition should be performed.',
            'confirmation' => $target->public_id,
        ])->assertConflict();
    }

    public function test_role_correction_is_superadmin_only_confirmed_scoped_and_audited(): void
    {
        $superadmin = $this->user('root@example.com', 'Root', true);
        $owner = $this->user('owner@example.com', 'Owner');
        $target = $this->user('target@example.com', 'Target');
        $other = $this->user('other@example.com', 'Other');
        $organization = $this->organization($owner, 'Role Label');
        $organizationMembership = $organization->memberships()->create([
            'user_id' => $target->id,
            'role' => OrganizationRole::User->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $artist = $this->artist($owner, 'Role Artist');
        $artistMembership = $artist->memberships()->create([
            'user_id' => $target->id,
            'role' => ArtistRole::User->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $reason = 'Correcting an incorrectly imported administrator role.';

        Sanctum::actingAs($owner, ['portal:access']);
        $this->patchApi("/users/{$target->public_id}/organization-memberships/{$organizationMembership->public_id}/role", [
            'role' => OrganizationRole::Admin->value,
            'reason' => $reason,
            'confirmation' => $target->public_id,
        ])->assertForbidden();
        $this->patchApi("/users/{$target->public_id}/artist-memberships/{$artistMembership->public_id}/role", [
            'role' => ArtistRole::Admin->value,
            'reason' => $reason,
            'confirmation' => $target->public_id,
        ])->assertForbidden();

        Sanctum::actingAs($superadmin, ['portal:access']);
        $this->patchApi("/users/{$target->public_id}/organization-memberships/{$organizationMembership->public_id}/role", [
            'role' => OrganizationRole::Admin->value,
            'reason' => $reason,
            'confirmation' => $target->public_id,
        ])->assertOk()
            ->assertJsonPath('data.type', 'organization')
            ->assertJsonPath('data.scope.id', $organization->public_id)
            ->assertJsonPath('data.role', OrganizationRole::Admin->value);

        $this->patchApi("/users/{$target->public_id}/artist-memberships/{$artistMembership->public_id}/role", [
            'role' => ArtistRole::Admin->value,
            'reason' => $reason,
            'confirmation' => $target->public_id,
        ])->assertOk()
            ->assertJsonPath('data.type', 'artist')
            ->assertJsonPath('data.scope.id', $artist->public_id)
            ->assertJsonPath('data.role', ArtistRole::Admin->value);

        $this->patchApi("/users/{$other->public_id}/organization-memberships/{$organizationMembership->public_id}/role", [
            'role' => OrganizationRole::User->value,
            'reason' => $reason,
            'confirmation' => $other->public_id,
        ])->assertNotFound();

        $events = SecurityAuditEvent::query()
            ->whereIn('event_type', ['organization.membership_role_changed', 'artist.membership_role_changed'])
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $events);
        $this->assertSame($target->public_id, $events[0]->metadata['target_user_id']);
        $this->assertSame($reason, $events[0]->metadata['reason']);
        $this->assertSame('platform_administration', $events[0]->metadata['correction_source']);
        $this->assertSame(OrganizationRole::User->value, $events[0]->metadata['previous_role']);
        $this->assertSame(OrganizationRole::Admin->value, $events[0]->metadata['new_role']);
    }

    public function test_openapi_documents_platform_administration_contracts(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('docs/openapi.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertArrayHasKey('/api/v1/platform/overview', $document['paths']);
        $this->assertArrayHasKey('/api/v1/users/{user}', $document['paths']);
        $this->assertArrayHasKey('/api/v1/users/{user}/status', $document['paths']);
        $this->assertArrayHasKey('/api/v1/users/{user}/organization-memberships/{membership}/role', $document['paths']);
        $this->assertArrayHasKey('/api/v1/users/{user}/artist-memberships/{membership}/role', $document['paths']);

        $statusSchema = $document['paths']['/api/v1/users/{user}/status']['patch']['requestBody']['content']['application/json']['schema'];
        $this->assertSame(['status', 'reason', 'confirmation'], $statusSchema['required']);
        $this->assertSame(['active', 'suspended'], $statusSchema['properties']['status']['enum']);
        $this->assertFalse($statusSchema['additionalProperties']);

        $userParameters = collect($document['paths']['/api/v1/users']['get']['parameters'])
            ->keyBy('name');
        $this->assertSame(
            ['active', 'suspended'],
            $userParameters->get('filter[status]')['schema']['enum'],
        );
    }

    private function user(string $email, string $displayName, bool $superadmin = false): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => self::PASSWORD,
            'is_superadmin' => $superadmin,
        ]);
        $user->profile()->create(['display_name' => $displayName]);

        return $user;
    }

    private function organization(User $creator, string $name): Organization
    {
        $organization = Organization::query()->create(['created_by_user_id' => $creator->id]);
        $organization->profile()->create(['name' => $name]);
        $organization->memberships()->create([
            'user_id' => $creator->id,
            'role' => OrganizationRole::Admin->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $organization;
    }

    private function artist(User $creator, string $name): Artist
    {
        $artist = Artist::query()->create(['created_by_user_id' => $creator->id]);
        $artist->profile()->create(['name' => $name]);
        $artist->memberships()->create([
            'user_id' => $creator->id,
            'role' => ArtistRole::Admin->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $artist;
    }

    private function releaseForOrganization(Organization $organization, string $title, string $status): Release
    {
        return Release::query()->create([
            'organization_id' => $organization->id,
            'type' => 'album',
            'status' => $status,
            'title' => $title,
            'created_by_user_id' => $organization->created_by_user_id,
            'updated_by_user_id' => $organization->created_by_user_id,
        ]);
    }

    private function releaseForArtist(Artist $artist, string $title, string $status): Release
    {
        return Release::query()->create([
            'artist_id' => $artist->id,
            'type' => 'single',
            'status' => $status,
            'title' => $title,
            'created_by_user_id' => $artist->created_by_user_id,
            'updated_by_user_id' => $artist->created_by_user_id,
        ]);
    }

    /** @return array{DeviceSession, RefreshToken, string} */
    private function activeMobileSession(User $user): array
    {
        $session = DeviceSession::query()->create([
            'user_id' => $user->id,
            'client_type' => 'mobile',
            'device_name' => 'Test phone',
            'last_used_at' => now(),
            'idle_expires_at' => now()->addDay(),
            'absolute_expires_at' => now()->addDays(30),
        ]);
        $accessToken = $user->createToken('test', ['mobile:access'], now()->addMinutes(15));
        $accessToken->accessToken->device_session_id = $session->id;
        $accessToken->accessToken->save();
        $plainRefreshToken = 'uncovr_refresh_'.str_repeat('A', 43);
        $refreshToken = RefreshToken::query()->create([
            'device_session_id' => $session->id,
            'token_hash' => hash('sha256', $plainRefreshToken),
            'generation' => 0,
            'expires_at' => now()->addDay(),
        ]);

        return [$session, $refreshToken, $plainRefreshToken];
    }

    /** @return array<string, mixed> */
    private function loginPayload(string $email): array
    {
        return [
            'email' => $email,
            'password' => self::PASSWORD,
            'client_type' => 'mobile',
            'device' => ['name' => 'Test phone'],
        ];
    }
}
