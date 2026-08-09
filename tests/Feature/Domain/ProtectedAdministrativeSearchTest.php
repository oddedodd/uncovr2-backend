<?php

namespace Tests\Feature\Domain;

use App\Enums\ArtistRole;
use App\Enums\OrganizationRole;
use App\Models\Artist;
use App\Models\Organization;
use App\Models\Release;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProtectedAdministrativeSearchTest extends TestCase
{
    public function test_user_listing_requires_authentication_and_superadmin_access(): void
    {
        $this->getApi('/users')->assertUnauthorized();

        Sanctum::actingAs($this->user('member@example.com', 'Member'), ['portal:access']);
        $this->getApi('/users')->assertForbidden();
    }

    public function test_superadmin_can_search_users_without_sensitive_or_internal_fields(): void
    {
        $superadmin = $this->user('root@example.com', 'Root Admin', true);
        $match = $this->user('artist@example.com', 'Northern Lights');
        $suspended = $this->user('other@example.com', 'Other User');
        $suspended->forceFill([
            'status' => 'suspended',
            'suspended_at' => now(),
            'suspension_reason' => 'Administrative test suspension.',
        ])->save();
        Sanctum::actingAs($superadmin, ['portal:access']);

        $response = $this->getApi('/users?filter[search]=northern');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->public_id)
            ->assertJsonPath('data.0.email', 'artist@example.com')
            ->assertJsonPath('data.0.display_name', 'Northern Lights')
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.remember_token')
            ->assertJsonMissingPath('data.0.public_id')
            ->assertJsonMissingPath('data.0.profile.user_id')
            ->assertJsonPath('meta.pagination.per_page', 25)
            ->assertJsonPath('meta.pagination.has_more', false);

        $this->getApi('/users?filter[status]=suspended')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $suspended->public_id);
    }

    public function test_user_listing_uses_cursor_pagination(): void
    {
        $superadmin = $this->user('root@example.com', 'Root Admin', true);
        $this->user('one@example.com', 'One');
        $this->user('two@example.com', 'Two');
        Sanctum::actingAs($superadmin, ['portal:access']);

        $first = $this->getApi('/users?page[size]=2')->assertOk();
        $cursor = $first->json('meta.pagination.next_cursor');

        $this->assertNotNull($cursor);
        $first->assertJsonCount(2, 'data')->assertJsonPath('meta.pagination.has_more', true);
        $this->getApi('/users?page[size]=2&page[after]='.urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_organization_search_preserves_scope_and_superadmin_status_visibility(): void
    {
        $member = $this->user('member@example.com', 'Member');
        $other = $this->user('other@example.com', 'Other');
        $superadmin = $this->user('root@example.com', 'Root', true);
        $visible = $this->organization($member, 'Northern Records', 'Northern Records AS');
        $this->organization($other, 'Southern Records');
        $suspended = $this->organization($other, 'Northern Archive');
        $suspended->update(['status' => 'suspended', 'suspended_at' => now()]);

        Sanctum::actingAs($member, ['portal:access']);
        $this->getApi('/organizations?filter[search]=northern')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->public_id);

        Sanctum::actingAs($superadmin, ['portal:access']);
        $this->getApi('/organizations?filter[search]=northern&filter[status]=suspended')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $suspended->public_id);
    }

    public function test_artist_search_preserves_scope_and_supports_status_filtering(): void
    {
        $member = $this->user('artist@example.com', 'Artist');
        $other = $this->user('other@example.com', 'Other');
        $superadmin = $this->user('root@example.com', 'Root', true);
        $visible = $this->artist($member, 'Northern Echo');
        $this->artist($other, 'Northern Stranger');
        $suspended = $this->artist($other, 'Northern Vault');
        $suspended->update(['status' => 'suspended', 'suspended_at' => now()]);

        Sanctum::actingAs($member, ['portal:access']);
        $this->getApi('/artists?filter[search]=northern')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->public_id);

        Sanctum::actingAs($superadmin, ['portal:access']);
        $this->getApi('/artists?filter[search]=northern&filter[status]=suspended')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $suspended->public_id);
    }

    public function test_release_search_preserves_scope_and_combines_status_and_type_filters(): void
    {
        $member = $this->user('label@example.com', 'Label');
        $other = $this->user('other@example.com', 'Other');
        $superadmin = $this->user('root@example.com', 'Root', true);
        $organization = $this->organization($member, 'Northern Label');
        $otherOrganization = $this->organization($other, 'Other Label');
        $visible = $this->release($organization, 'Midnight Northern', 'album', 'draft');
        $this->release($otherOrganization, 'Midnight Northern', 'album', 'draft');
        $published = $this->release($otherOrganization, 'Northern Signals', 'single', 'published');

        Sanctum::actingAs($member, ['portal:access']);
        $this->getApi('/releases?filter[search]=northern')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->public_id);

        Sanctum::actingAs($superadmin, ['portal:access']);
        $this->getApi('/releases?filter[search]=signals&filter[status]=published&filter[type]=single')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $published->public_id);
    }

    public function test_search_endpoints_reject_unknown_invalid_and_ambiguous_parameters(): void
    {
        Sanctum::actingAs($this->user('root@example.com', 'Root', true), ['portal:access']);

        $this->getApi('/users?sort=email')->assertUnprocessable();
        $this->getApi('/organizations?filter[status]=deleted')->assertUnprocessable();
        $this->getApi('/artists?filter[search]=x')->assertUnprocessable();
        $this->getApi('/releases?filter[type]=mixtape')->assertUnprocessable();
        $this->getApi('/releases?page[after]=abc&page[before]=def')->assertUnprocessable();
        $this->getApi('/releases?page[after]=not-a-cursor')->assertUnprocessable();
    }

    private function user(string $email, string $displayName, bool $superadmin = false): User
    {
        $user = User::factory()->create(['email' => $email, 'is_superadmin' => $superadmin]);
        $user->profile()->create(['display_name' => $displayName]);

        return $user;
    }

    private function organization(User $creator, string $name, ?string $legalName = null): Organization
    {
        $organization = Organization::query()->create(['created_by_user_id' => $creator->id]);
        $organization->profile()->create(['name' => $name, 'legal_name' => $legalName]);
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

    private function release(Organization $organization, string $title, string $type, string $status): Release
    {
        return Release::query()->create([
            'organization_id' => $organization->id,
            'type' => $type,
            'status' => $status,
            'title' => $title,
            'created_by_user_id' => $organization->created_by_user_id,
            'updated_by_user_id' => $organization->created_by_user_id,
        ]);
    }
}
