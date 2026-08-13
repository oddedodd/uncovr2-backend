<?php

namespace Tests\Feature\Domain;

use App\Enums\ArtistRole;
use App\Enums\OrganizationRole;
use App\Models\Release;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Domain\Concerns\BuildsReleaseDomain;
use Tests\TestCase;

class ReleaseAuthorizationTest extends TestCase
{
    use BuildsReleaseDomain;

    /** Capability flag name => the Gate ability it must mirror. */
    private const CAPABILITY_ABILITIES = [
        'can_update' => 'update',
        'can_submit' => 'submit',
        'can_delete' => 'delete',
        'can_approve' => 'approve',
        'can_publish' => 'publish',
        'can_manage_editors' => 'manageEditors',
    ];

    public function test_scope_members_can_read_but_only_admins_and_explicit_editors_can_modify(): void
    {
        $admin = $this->domainUser('admin@example.com');
        $creator = $this->domainUser('creator@example.com');
        $colleague = $this->domainUser('colleague@example.com');
        $outsider = $this->domainUser('outsider@example.com');
        $organization = $this->domainOrganization($admin);
        $this->addLabelMember($organization, $creator, OrganizationRole::User);
        $this->addLabelMember($organization, $colleague, OrganizationRole::User);
        $artist = $this->domainArtist($admin);
        $this->linkArtist($organization, $artist, $admin);
        $release = $this->createOrganizationRelease($creator, $organization, $artist);

        $this->actAsDomain($colleague);
        $this->getApi("/releases/{$release->public_id}")->assertOk();
        $this->patchApi("/releases/{$release->public_id}", ['title' => 'Unauthorized edit'])->assertForbidden();

        $this->actAsDomain($outsider);
        $this->getApi("/releases/{$release->public_id}")->assertForbidden();
        $this->getApi('/releases')->assertOk()->assertJsonMissing(['id' => $release->public_id]);

        $this->actAsDomain($admin);
        $this->postApi("/releases/{$release->public_id}/editors", ['user_id' => $colleague->public_id])->assertCreated();

        $this->actAsDomain($colleague);
        $this->patchApi("/releases/{$release->public_id}", ['title' => 'Authorized edit'])->assertOk()->assertJsonPath('data.title', 'Authorized edit');

        $this->actAsDomain($admin);
        $this->deleteApi("/releases/{$release->public_id}/editors/{$colleague->public_id}")->assertOk();
        $this->actAsDomain($colleague);
        $this->patchApi("/releases/{$release->public_id}", ['title' => 'Revoked edit'])->assertForbidden();
    }

    public function test_membership_removal_immediately_removes_creator_access_despite_creator_attribution(): void
    {
        $admin = $this->domainUser('admin@example.com');
        $creator = $this->domainUser('creator@example.com');
        $organization = $this->domainOrganization($admin);
        $this->addLabelMember($organization, $creator, OrganizationRole::User);
        $artist = $this->domainArtist($admin);
        $this->linkArtist($organization, $artist, $admin);
        $release = $this->createOrganizationRelease($creator, $organization, $artist);

        $organization->memberships()->where('user_id', $creator->id)->delete();
        $this->actAsDomain($creator);
        $this->getApi("/releases/{$release->public_id}")->assertForbidden();
        $this->patchApi("/releases/{$release->public_id}", ['title' => 'No longer allowed'])->assertForbidden();
        $this->assertSame($creator->id, $release->fresh()->created_by_user_id);
    }

    public function test_artist_user_can_create_and_edit_an_artist_owned_release(): void
    {
        $admin = $this->domainUser('artist-admin@example.com');
        $artistUser = $this->domainUser('artist-user@example.com');
        $artist = $this->domainArtist($admin, 'Solo Artist');
        $this->addArtistMember($artist, $artistUser, ArtistRole::User);
        $this->actAsDomain($artistUser);

        $response = $this->postApi('/releases', [
            'owner_type' => 'artist', 'owner_id' => $artist->public_id,
            'primary_artist_id' => $artist->public_id, 'type' => 'single',
            'title' => 'Solo Single', 'subtitle' => null, 'description' => null,
            'release_date' => null, 'upc' => null, 'cover_media_id' => null,
        ])->assertCreated()->assertJsonPath('data.owner.type', 'artist');
        $releaseId = $response->json('data.id');
        $this->patchApi("/releases/{$releaseId}", ['title' => 'Edited Single'])->assertOk();
        $this->assertDatabaseHas('releases', ['public_id' => $releaseId, 'artist_id' => $artist->id, 'type' => 'single']);
    }

    public function test_cross_tenant_primary_artist_cannot_be_claimed(): void
    {
        $admin = $this->domainUser('admin@example.com');
        $otherAdmin = $this->domainUser('other@example.com');
        $organization = $this->domainOrganization($admin);
        $unrelatedArtist = $this->domainArtist($otherAdmin, 'Unrelated');
        $this->actAsDomain($admin);

        $this->assertApiError($this->postApi('/releases', [
            'owner_type' => 'organization', 'owner_id' => $organization->public_id,
            'primary_artist_id' => $unrelatedArtist->public_id, 'type' => 'album',
            'title' => 'Invalid', 'subtitle' => null, 'description' => null,
            'release_date' => null, 'upc' => null, 'cover_media_id' => null,
        ]), 422, 'validation_failed');
        $this->assertDatabaseCount('releases', 0);
    }

    public function test_release_permissions_block_mirrors_the_policy_for_every_ability(): void
    {
        $admin = $this->domainUser('permissions-admin@example.com');
        $editor = $this->domainUser('permissions-editor@example.com');
        $viewer = $this->domainUser('permissions-viewer@example.com');
        $superadmin = $this->domainUser('permissions-super@example.com', true);
        $organization = $this->domainOrganization($admin);
        $this->addLabelMember($organization, $editor, OrganizationRole::User);
        $this->addLabelMember($organization, $viewer, OrganizationRole::User);
        $artist = $this->domainArtist($admin);
        $this->linkArtist($organization, $artist, $admin);
        $release = $this->createOrganizationRelease($admin, $organization, $artist);

        $this->actAsDomain($admin);
        $this->postApi("/releases/{$release->public_id}/editors", ['user_id' => $editor->public_id])->assertCreated();

        // The status gate is half of ReleasePolicy::update, so every actor is
        // checked in an editable and a non-editable status.
        foreach (['draft', 'unpublished', 'review', 'published', 'archived'] as $status) {
            $release->forceFill(['status' => $status])->save();

            foreach ([$admin, $editor, $viewer, $superadmin] as $user) {
                $this->assertPermissionsMirrorPolicy($user, $release, $status);
            }
        }
    }

    private function assertPermissionsMirrorPolicy(User $user, Release $release, string $status): void
    {
        $this->actAsDomain($user);
        $expected = [];
        foreach (self::CAPABILITY_ABILITIES as $flag => $ability) {
            $expected[$flag] = Gate::forUser($user)->allows($ability, $release->fresh());
        }

        $detail = $this->getApi("/releases/{$release->public_id}")->assertOk();
        $summary = collect($this->getApi('/releases')->assertOk()->json('data'))
            ->firstWhere('id', $release->public_id);
        $this->assertNotNull($summary, "Release should be listed for {$user->email} in status {$status}.");

        foreach ($expected as $flag => $allowed) {
            $context = "{$flag} for {$user->email} in status {$status}";
            $this->assertSame($allowed, $detail->json("data.permissions.{$flag}"), "Detail {$context}");
            $this->assertSame($allowed, $summary['permissions'][$flag], "Summary {$context}");
        }
    }

    public function test_superadmin_can_read_and_modify_every_release(): void
    {
        $admin = $this->domainUser('admin@example.com');
        $superadmin = $this->domainUser('super@example.com', true);
        $organization = $this->domainOrganization($admin);
        $artist = $this->domainArtist($admin);
        $this->linkArtist($organization, $artist, $admin);
        $release = $this->createOrganizationRelease($admin, $organization, $artist);
        $this->actAsDomain($superadmin);

        $this->getApi("/releases/{$release->public_id}")->assertOk();
        $this->patchApi("/releases/{$release->public_id}", ['title' => 'Platform edit'])->assertOk();
        $this->getApi('/releases')->assertOk()->assertJsonFragment(['id' => $release->public_id]);
    }
}
