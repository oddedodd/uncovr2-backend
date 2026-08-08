<?php

namespace Tests\Feature\Domain;

use App\Models\Contributor;
use App\Models\Credit;
use App\Models\Media;
use App\Models\Page;
use App\Models\StreamingLink;
use App\Models\Track;
use Tests\Feature\Domain\Concerns\BuildsReleaseDomain;
use Tests\TestCase;

class ReleaseCrudTest extends TestCase
{
    use BuildsReleaseDomain;

    public function test_nested_draft_resources_support_create_read_update_and_soft_delete(): void
    {
        $admin = $this->domainUser('admin@example.com');
        $organization = $this->domainOrganization($admin);
        $artist = $this->domainArtist($admin);
        $secondArtist = $this->domainArtist($admin, 'Featured Artist');
        $this->linkArtist($organization, $artist, $admin);
        $this->linkArtist($organization, $secondArtist, $admin);
        $release = $this->createOrganizationRelease($admin, $organization, $artist);

        $this->patchApi("/releases/{$release->public_id}", ['subtitle' => 'Updated'])->assertOk()->assertJsonPath('data.subtitle', 'Updated');
        $this->postApi("/releases/{$release->public_id}/artists", ['artist_id' => $secondArtist->public_id, 'is_primary' => true, 'position' => 2])->assertCreated()->assertJsonPath('data.is_primary', true);
        $this->deleteApi("/releases/{$release->public_id}/artists/{$artist->public_id}")->assertOk();
        $this->assertApiError($this->deleteApi("/releases/{$release->public_id}/artists/{$secondArtist->public_id}"), 422, 'validation_failed');

        $trackId = $this->postApi("/releases/{$release->public_id}/tracks", ['position' => 1, 'title' => 'Old title', 'duration_ms' => 1000, 'isrc' => null, 'is_explicit' => false])->assertCreated()->json('data.id');
        $this->patchApi("/releases/{$release->public_id}/tracks/{$trackId}", ['title' => 'New title', 'duration_ms' => null])->assertOk()->assertJsonPath('data.title', 'New title')->assertJsonPath('data.duration_ms', null);

        $pageId = $this->postApi("/tracks/{$trackId}/pages", ['position' => 1, 'title' => 'Old page'])->assertCreated()->json('data.id');
        $this->patchApi("/pages/{$pageId}", ['title' => 'New page'])->assertOk()->assertJsonPath('data.title', 'New page');

        $linkId = $this->postApi("/tracks/{$trackId}/streaming-links", ['service' => 'spotify', 'url' => 'https://open.spotify.com/track/old', 'position' => 1])->assertCreated()->json('data.id');
        $this->patchApi("/streaming-links/{$linkId}", ['service' => 'tidal', 'url' => 'https://tidal.com/track/new'])->assertOk()->assertJsonPath('data.service', 'tidal');
        $this->deleteApi("/streaming-links/{$linkId}")->assertOk();

        $contributorId = $this->postApi('/contributors', ['owner_type' => 'organization', 'owner_id' => $organization->public_id, 'display_name' => 'Old Name', 'legal_name' => null, 'email' => null, 'website_url' => null])->assertCreated()->json('data.id');
        $this->patchApi("/contributors/{$contributorId}", ['display_name' => 'New Name'])->assertOk()->assertJsonPath('data.display_name', 'New Name');
        $creditId = $this->postApi("/tracks/{$trackId}/credits", ['contributor_id' => $contributorId, 'role' => 'producer', 'detail' => null, 'position' => 1])->assertCreated()->json('data.id');
        $this->patchApi("/credits/{$creditId}", ['role' => 'engineer', 'detail' => 'Mix'])->assertOk()->assertJsonPath('data.role', 'engineer');
        $this->deleteApi("/credits/{$creditId}")->assertOk();
        $this->deleteApi("/contributors/{$contributorId}")->assertOk();

        $mediaId = $this->postApi('/media', ['owner_type' => 'organization', 'owner_id' => $organization->public_id, 'kind' => 'image', 'original_filename' => 'old.jpg', 'mime_type' => 'image/jpeg', 'byte_size' => null, 'width' => null, 'height' => null, 'metadata' => null])->assertCreated()->json('data.id');
        $this->patchApi("/media/{$mediaId}", ['original_filename' => 'new.jpg'])->assertOk()->assertJsonPath('data.original_filename', 'new.jpg');
        $this->deleteApi("/media/{$mediaId}")->assertOk();

        $this->deleteApi("/pages/{$pageId}")->assertOk();
        $this->deleteApi("/releases/{$release->public_id}/tracks/{$trackId}")->assertOk();

        $this->assertNotNull(Track::withTrashed()->where('public_id', $trackId)->sole()->deleted_at);
        $this->assertNotNull(Page::withTrashed()->where('public_id', $pageId)->sole()->deleted_at);
        $this->assertNotNull(StreamingLink::withTrashed()->where('public_id', $linkId)->sole()->deleted_at);
        $this->assertNotNull(Credit::withTrashed()->where('public_id', $creditId)->sole()->deleted_at);
        $this->assertNotNull(Contributor::withTrashed()->where('public_id', $contributorId)->sole()->deleted_at);
        $this->assertNotNull(Media::withTrashed()->where('public_id', $mediaId)->sole()->deleted_at);
        $this->assertGreaterThanOrEqual(15, $release->activityEvents()->count());
    }
}
