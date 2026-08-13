<?php

namespace Tests\Feature\Domain\Concerns;

use App\Enums\ArtistRole;
use App\Enums\OrganizationRole;
use App\Models\Artist;
use App\Models\Organization;
use App\Models\Release;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

trait BuildsReleaseDomain
{
    protected function domainUser(string $email, bool $superadmin = false): User
    {
        $user = User::factory()->create(['email' => $email, 'is_superadmin' => $superadmin]);
        $user->profile()->create(['display_name' => str($email)->before('@')->headline()]);

        return $user;
    }

    protected function domainOrganization(User $admin, string $name = 'Test Label'): Organization
    {
        $organization = Organization::query()->create(['created_by_user_id' => $admin->id]);
        $organization->profile()->create(['name' => $name]);
        $this->addLabelMember($organization, $admin, OrganizationRole::Admin);

        return $organization;
    }

    protected function domainArtist(User $admin, string $name = 'Test Artist'): Artist
    {
        $artist = Artist::query()->create(['created_by_user_id' => $admin->id]);
        $artist->profile()->create(['name' => $name]);
        $this->addArtistMember($artist, $admin, ArtistRole::Admin);

        return $artist;
    }

    protected function addLabelMember(Organization $organization, User $user, OrganizationRole $role = OrganizationRole::User): void
    {
        $organization->memberships()->create(['user_id' => $user->id, 'role' => $role->value, 'status' => 'active', 'joined_at' => now()]);
    }

    protected function addArtistMember(Artist $artist, User $user, ArtistRole $role = ArtistRole::User): void
    {
        $artist->memberships()->create(['user_id' => $user->id, 'role' => $role->value, 'status' => 'active', 'joined_at' => now()]);
    }

    protected function linkArtist(Organization $organization, Artist $artist, User $actor): void
    {
        $organization->artistRelationships()->create(['artist_id' => $artist->id, 'relationship_type' => 'managing_label', 'created_by_user_id' => $actor->id, 'started_at' => now()]);
    }

    protected function actAsDomain(User $user): void
    {
        Sanctum::actingAs($user, ['portal:access']);
    }

    protected function createOrganizationRelease(User $actor, Organization $organization, Artist $artist, array $overrides = []): Release
    {
        $this->actAsDomain($actor);
        $id = $this->postApi('/releases', [...[
            'owner_type' => 'organization', 'owner_id' => $organization->public_id,
            'primary_artist_id' => $artist->public_id, 'type' => 'album',
            'title' => 'Test Release', 'subtitle' => null, 'description' => null,
            'release_date' => null, 'upc' => null, 'cover_media_id' => null,
        ], ...$overrides])->assertCreated()->json('data.id');

        return Release::query()->where('public_id', $id)->sole();
    }

    protected function createArtistRelease(User $actor, Artist $artist, array $overrides = []): Release
    {
        $this->actAsDomain($actor);
        $id = $this->postApi('/releases', [...[
            'owner_type' => 'artist', 'owner_id' => $artist->public_id,
            'primary_artist_id' => $artist->public_id, 'type' => 'album',
            'title' => 'Test Artist Release', 'subtitle' => null, 'description' => null,
            'release_date' => null, 'upc' => null, 'cover_media_id' => null,
        ], ...$overrides])->assertCreated()->json('data.id');

        return Release::query()->where('public_id', $id)->sole();
    }
}
