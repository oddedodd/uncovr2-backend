<?php

namespace Tests\Feature\Domain;

use App\Enums\ArtistRole;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\Artist;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationArtistAuthorizationTest extends TestCase
{
    public function test_creators_become_admins_and_resources_use_public_ids(): void
    {
        $user = $this->user('creator@example.com');
        Sanctum::actingAs($user, ['portal:access']);

        $organization = $this->postApi('/organizations', [
            'name' => 'North Label',
            'legal_name' => 'North Label AS',
            'description' => null,
            'website_url' => 'https://label.example',
        ])->assertCreated()->json('data');

        $artist = $this->postApi('/artists', [
            'name' => 'Ada Artist',
            'biography' => null,
            'website_url' => null,
        ])->assertCreated()->json('data');

        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $organization['id']);
        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $artist['id']);
        $this->assertDatabaseHas('organization_memberships', ['user_id' => $user->id, 'role' => 'label_admin']);
        $this->assertDatabaseHas('artist_memberships', ['user_id' => $user->id, 'role' => 'artist_admin']);
    }

    public function test_complete_role_matrix_is_scope_bound(): void
    {
        $superadmin = $this->user('super@example.com', true);
        $labelAdmin = $this->user('label-admin@example.com');
        $labelUser = $this->user('label-user@example.com');
        $otherLabelAdmin = $this->user('other-label@example.com');
        $artistAdmin = $this->user('artist-admin@example.com');
        $artistUser = $this->user('artist-user@example.com');
        $outsider = $this->user('outsider@example.com');

        $organization = $this->organization($labelAdmin, 'Scope Label');
        $otherOrganization = $this->organization($otherLabelAdmin, 'Other Label');
        $this->addOrganizationMember($organization, $labelUser, OrganizationRole::User);
        $artist = $this->artist($artistAdmin, 'Scoped Artist');
        $this->addArtistMember($artist, $artistUser, ArtistRole::User);
        $organization->artistRelationships()->create([
            'artist_id' => $artist->id, 'created_by_user_id' => $labelAdmin->id,
            'relationship_type' => 'managing_label', 'started_at' => now(),
        ]);

        $this->assertTrue(Gate::forUser($superadmin)->allows('update', $organization));
        $this->assertTrue(Gate::forUser($superadmin)->allows('manageMembers', $artist));
        $this->assertTrue(Gate::forUser($labelAdmin)->allows('update', $organization));
        $this->assertTrue(Gate::forUser($labelAdmin)->allows('manageMembers', $artist));
        $this->assertTrue(Gate::forUser($labelUser)->allows('view', $organization));
        $this->assertFalse(Gate::forUser($labelUser)->allows('update', $organization));
        $this->assertTrue(Gate::forUser($labelUser)->allows('view', $artist));
        $this->assertFalse(Gate::forUser($labelUser)->allows('update', $artist));
        $this->assertTrue(Gate::forUser($artistAdmin)->allows('manageMembers', $artist));
        $this->assertTrue(Gate::forUser($artistUser)->allows('update', $artist));
        $this->assertFalse(Gate::forUser($artistUser)->allows('manageMembers', $artist));
        $this->assertFalse(Gate::forUser($otherLabelAdmin)->allows('view', $organization));
        $this->assertFalse(Gate::forUser($otherLabelAdmin)->allows('view', $artist));
        $this->assertFalse(Gate::forUser($outsider)->allows('view', $organization));
        $this->assertTrue(Gate::forUser($otherLabelAdmin)->allows('update', $otherOrganization));
    }

    public function test_same_user_can_hold_independent_roles_at_multiple_scopes(): void
    {
        $user = $this->user('multi@example.com');
        $orgCreator = $this->user('org@example.com');
        $artistCreator = $this->user('artist@example.com');
        $organization = $this->organization($orgCreator, 'Multi Label');
        $artist = $this->artist($artistCreator, 'Multi Artist');
        $this->addOrganizationMember($organization, $user, OrganizationRole::User);
        $this->addArtistMember($artist, $user, ArtistRole::Admin);

        $this->assertTrue(Gate::forUser($user)->allows('view', $organization));
        $this->assertFalse(Gate::forUser($user)->allows('update', $organization));
        $this->assertTrue(Gate::forUser($user)->allows('manageMembers', $artist));
    }

    public function test_ending_relationship_and_removing_membership_revoke_access_immediately(): void
    {
        $admin = $this->user('admin@example.com');
        $labelUser = $this->user('member@example.com');
        $artistAdmin = $this->user('artist@example.com');
        $organization = $this->organization($admin, 'Access Label');
        $membership = $this->addOrganizationMember($organization, $labelUser, OrganizationRole::User);
        $artist = $this->artist($artistAdmin, 'Access Artist');
        $relationship = $organization->artistRelationships()->create([
            'artist_id' => $artist->id, 'created_by_user_id' => $admin->id,
            'relationship_type' => 'managing_label', 'started_at' => now(),
        ]);

        $this->assertTrue(Gate::forUser($labelUser)->allows('view', $artist));
        $relationship->update(['ended_at' => now()]);
        $this->assertFalse(Gate::forUser($labelUser)->allows('view', $artist));

        Sanctum::actingAs($admin, ['portal:access']);
        $this->deleteApi("/organizations/{$organization->public_id}/members/{$membership->public_id}")->assertOk();
        $this->assertFalse(Gate::forUser($labelUser)->allows('view', $organization));
        $this->assertDatabaseHas('security_audit_events', ['event_type' => 'organization.membership_removed']);
    }

    public function test_last_active_admin_cannot_be_removed_or_suspended(): void
    {
        $admin = $this->user('admin@example.com');
        $organization = $this->organization($admin, 'Safe Label');
        $membership = $organization->memberships()->sole();
        Sanctum::actingAs($admin, ['portal:access']);

        $this->assertApiError($this->patchApi("/organizations/{$organization->public_id}/members/{$membership->public_id}", [
            'status' => MembershipStatus::Suspended->value,
        ]), 409, 'conflict');
        $this->assertApiError($this->deleteApi("/organizations/{$organization->public_id}/members/{$membership->public_id}"), 409, 'conflict');
    }

    public function test_only_superadmin_can_suspend_and_restore_domain_scopes(): void
    {
        $admin = $this->user('admin@example.com');
        $superadmin = $this->user('super@example.com', true);
        $organization = $this->organization($admin, 'Moderated Label');
        $artist = $this->artist($admin, 'Moderated Artist');

        Sanctum::actingAs($admin, ['portal:access']);
        $this->patchApi("/organizations/{$organization->public_id}/status", ['status' => 'suspended'])->assertForbidden();

        Sanctum::actingAs($superadmin, ['portal:access']);
        $this->patchApi("/organizations/{$organization->public_id}/status", ['status' => 'suspended'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->patchApi("/artists/{$artist->public_id}/status", ['status' => 'suspended'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertFalse(Gate::forUser($admin)->allows('view', $organization->fresh()));
        $this->assertDatabaseHas('security_audit_events', ['event_type' => 'organization.status_changed']);
        $this->assertDatabaseHas('security_audit_events', ['event_type' => 'artist.status_changed']);
    }

    public function test_role_and_suspension_changes_are_audited_and_revoke_access(): void
    {
        $admin = $this->user('admin@example.com');
        $member = $this->user('member@example.com');
        $secondAdmin = $this->user('second-admin@example.com');
        $organization = $this->organization($admin, 'Audit Label');
        $membership = $this->addOrganizationMember($organization, $member, OrganizationRole::User);
        $this->addOrganizationMember($organization, $secondAdmin, OrganizationRole::Admin);
        Sanctum::actingAs($admin, ['portal:access']);

        $this->patchApi("/organizations/{$organization->public_id}/members/{$membership->public_id}", ['role' => 'label_admin'])->assertOk();
        $this->patchApi("/organizations/{$organization->public_id}/members/{$membership->public_id}", ['status' => 'suspended'])->assertOk();

        $this->assertFalse(Gate::forUser($member)->allows('view', $organization));
        $this->assertDatabaseHas('security_audit_events', ['event_type' => 'organization.membership_role_changed']);
        $this->assertDatabaseHas('security_audit_events', ['event_type' => 'organization.membership_status_changed']);
    }

    public function test_label_admin_cannot_claim_an_unmanaged_artist(): void
    {
        $labelAdmin = $this->user('label@example.com');
        $artistAdmin = $this->user('artist@example.com');
        $organization = $this->organization($labelAdmin, 'Safe Label');
        $artist = $this->artist($artistAdmin, 'Independent Artist');
        Sanctum::actingAs($labelAdmin, ['portal:access']);

        $this->postApi("/organizations/{$organization->public_id}/artists", [
            'artist_id' => $artist->public_id,
        ])->assertForbidden();
        $this->assertDatabaseCount('organization_artist_relationships', 0);
    }

    public function test_artist_admin_can_end_a_managing_relationship(): void
    {
        $labelAdmin = $this->user('label@example.com');
        $artistAdmin = $this->user('artist@example.com');
        $organization = $this->organization($labelAdmin, 'Former Label');
        $artist = $this->artist($artistAdmin, 'Independent Artist');
        $relationship = $organization->artistRelationships()->create([
            'artist_id' => $artist->id,
            'created_by_user_id' => $labelAdmin->id,
            'relationship_type' => 'managing_label',
            'started_at' => now(),
        ]);
        Sanctum::actingAs($artistAdmin, ['portal:access']);

        $this->deleteApi("/organizations/{$organization->public_id}/artists/{$relationship->public_id}")->assertOk();
        $this->assertNotNull($relationship->fresh()->ended_at);
    }

    private function user(string $email, bool $superadmin = false): User
    {
        $user = User::factory()->create(['email' => $email, 'is_superadmin' => $superadmin]);
        $user->profile()->create(['display_name' => str($email)->before('@')->headline()]);

        return $user;
    }

    private function organization(User $creator, string $name): Organization
    {
        $organization = Organization::query()->create(['created_by_user_id' => $creator->id]);
        $organization->profile()->create(['name' => $name]);
        $this->addOrganizationMember($organization, $creator, OrganizationRole::Admin);

        return $organization;
    }

    private function artist(User $creator, string $name): Artist
    {
        $artist = Artist::query()->create(['created_by_user_id' => $creator->id]);
        $artist->profile()->create(['name' => $name]);
        $this->addArtistMember($artist, $creator, ArtistRole::Admin);

        return $artist;
    }

    private function addOrganizationMember(Organization $organization, User $user, OrganizationRole $role)
    {
        return $organization->memberships()->create(['user_id' => $user->id, 'role' => $role->value, 'status' => 'active', 'joined_at' => now()]);
    }

    private function addArtistMember(Artist $artist, User $user, ArtistRole $role)
    {
        return $artist->memberships()->create(['user_id' => $user->id, 'role' => $role->value, 'status' => 'active', 'joined_at' => now()]);
    }
}
