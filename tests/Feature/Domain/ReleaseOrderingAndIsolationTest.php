<?php

namespace Tests\Feature\Domain;

use App\Models\Contributor;
use App\Models\Media;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Domain\Concerns\BuildsReleaseDomain;
use Tests\TestCase;

class ReleaseOrderingAndIsolationTest extends TestCase
{
    use BuildsReleaseDomain;

    public function test_ordered_children_are_unique_per_parent_and_returned_in_position_order(): void
    {
        [$release, $organization] = $this->releaseContext();
        $secondPage = $this->postApi("/releases/{$release->public_id}/pages", ['position' => 2, 'title' => 'Second'])->assertCreated()->assertJsonPath('data.blocks', [])->json('data.id');
        $firstPage = $this->postApi("/releases/{$release->public_id}/pages", ['position' => 1, 'title' => 'First'])->assertCreated()->assertJsonPath('data.parent.type', 'release')->json('data.id');
        $this->assertApiError($this->postApi("/releases/{$release->public_id}/pages", ['position' => 1, 'title' => 'Duplicate']), 422, 'validation_failed');

        $this->postApi("/pages/{$firstPage}/blocks", ['position' => 1, 'type' => 'text', 'payload' => ['body' => 'One']])->assertCreated();
        $this->assertApiError($this->postApi("/pages/{$firstPage}/blocks", ['position' => 1, 'type' => 'text', 'payload' => ['body' => 'Duplicate']]), 422, 'validation_failed');

        $this->postApi("/releases/{$release->public_id}/streaming-links", ['service' => 'spotify', 'url' => 'https://open.spotify.com/album/one', 'position' => 1])->assertCreated();
        $this->assertApiError($this->postApi("/releases/{$release->public_id}/streaming-links", ['service' => 'spotify', 'url' => 'https://open.spotify.com/album/two', 'position' => 2]), 422, 'validation_failed');
        $this->assertApiError($this->postApi("/releases/{$release->public_id}/streaming-links", ['service' => 'tidal', 'url' => 'https://tidal.com/album/two', 'position' => 1]), 422, 'validation_failed');
        $this->getApi("/releases/{$release->public_id}")->assertOk()
            ->assertJsonMissingPath('data.tracks')
            ->assertJsonPath('data.pages.0.id', $firstPage)
            ->assertJsonPath('data.pages.0.blocks.0.payload.body', 'One')
            ->assertJsonPath('data.pages.1.id', $secondPage);
    }

    public function test_media_and_contributors_are_storage_independent_and_tenant_isolated(): void
    {
        [$release, $organization, $admin] = $this->releaseContext(withAdmin: true);
        $mediaResponse = $this->postApi('/media', ['owner_type' => 'organization', 'owner_id' => $organization->public_id, 'kind' => 'image', 'original_filename' => 'cover.png', 'mime_type' => 'image/png', 'byte_size' => null, 'width' => null, 'height' => null, 'metadata' => ['purpose' => 'cover']])->assertCreated()->assertJsonPath('data.status', 'pending');
        $media = Media::query()->where('public_id', $mediaResponse->json('data.id'))->sole();
        $this->assertNull($media->storage_disk);
        $this->assertNull($media->storage_key);
        $this->assertApiError($this->patchApi("/media/{$media->public_id}", ['storage_key' => 'unsafe/client/path']), 422, 'validation_failed');

        $otherAdmin = $this->domainUser('other@example.com');
        $otherOrganization = $this->domainOrganization($otherAdmin, 'Other Label');
        $otherMedia = Media::query()->create(['organization_id' => $otherOrganization->id, 'kind' => 'image', 'original_filename' => 'other.png', 'mime_type' => 'image/png', 'created_by_user_id' => $otherAdmin->id, 'updated_by_user_id' => $otherAdmin->id]);
        $otherContributor = Contributor::query()->create(['organization_id' => $otherOrganization->id, 'display_name' => 'Other Person', 'created_by_user_id' => $otherAdmin->id, 'updated_by_user_id' => $otherAdmin->id]);

        $this->getApi("/media/{$otherMedia->public_id}")->assertForbidden();
        $this->getApi("/contributors/{$otherContributor->public_id}")->assertForbidden();
        $this->assertApiError($this->patchApi("/releases/{$release->public_id}", ['cover_media_id' => $otherMedia->public_id]), 422, 'validation_failed');
        $this->assertApiError($this->postApi("/releases/{$release->public_id}/credits", ['contributor_id' => $otherContributor->public_id, 'role' => 'producer', 'detail' => null, 'position' => 1]), 422, 'validation_failed');
    }

    #[DataProvider('releaseTypes')]
    public function test_supported_release_types_are_accepted(string $type): void
    {
        $admin = $this->domainUser("{$type}@example.com");
        $organization = $this->domainOrganization($admin);
        $artist = $this->domainArtist($admin);
        $this->linkArtist($organization, $artist, $admin);
        $release = $this->createOrganizationRelease($admin, $organization, $artist, ['type' => $type, 'title' => strtoupper($type)]);
        $this->assertSame($type, $release->type->value);
    }

    public function test_unknown_release_type_and_write_fields_are_rejected(): void
    {
        $admin = $this->domainUser('admin@example.com');
        $organization = $this->domainOrganization($admin);
        $artist = $this->domainArtist($admin);
        $this->linkArtist($organization, $artist, $admin);
        $this->actAsDomain($admin);
        $base = ['owner_type' => 'organization', 'owner_id' => $organization->public_id, 'primary_artist_id' => $artist->public_id, 'type' => 'mixtape', 'title' => 'Invalid', 'subtitle' => null, 'description' => null, 'release_date' => null, 'upc' => null, 'cover_media_id' => null];
        $this->assertApiError($this->postApi('/releases', $base), 422, 'validation_failed');
        $base['type'] = 'album';
        $base['status'] = 'published';
        $this->assertApiError($this->postApi('/releases', $base), 422, 'validation_failed');
    }

    public static function releaseTypes(): array
    {
        return [['album'], ['ep'], ['single']];
    }

    private function releaseContext(bool $withAdmin = false): array
    {
        $admin = $this->domainUser('admin@example.com');
        $organization = $this->domainOrganization($admin);
        $artist = $this->domainArtist($admin);
        $this->linkArtist($organization, $artist, $admin);
        $release = $this->createOrganizationRelease($admin, $organization, $artist);

        return $withAdmin ? [$release, $organization, $admin] : [$release, $organization];
    }
}
