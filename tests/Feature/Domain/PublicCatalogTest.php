<?php

namespace Tests\Feature\Domain;

use App\Contracts\MediaStorage;
use App\Models\Media;
use Illuminate\Support\Facades\Auth;
use Tests\Fakes\FakeMediaStorage;
use Tests\Feature\Domain\Concerns\BuildsReleaseDomain;
use Tests\TestCase;

class PublicCatalogTest extends TestCase
{
    use BuildsReleaseDomain;

    private FakeMediaStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new FakeMediaStorage;
        $this->app->instance(MediaStorage::class, $this->storage);
    }

    public function test_public_contract_uses_the_snapshot_and_never_exposes_internal_workflow_data(): void
    {
        [$release, $admin, $organization, $artist, $trackId] = $this->publishRelease('Midnight Signals');
        Auth::forgetGuards();

        $this->getApi('/public/labels')->assertOk()->assertJsonPath('data.0.id', $organization->public_id);
        $this->getApi('/public/artists')->assertOk()->assertJsonPath('data.0.id', $artist->public_id);
        $response = $this->getApi("/public/releases/{$release->public_id}")
            ->assertOk()
            ->assertHeader('Cache-Control')
            ->assertHeader('ETag')
            ->assertJsonPath('data.title', 'Midnight Signals')
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.tracks.0.id', $trackId)
            ->assertJsonMissingPath('data.lifecycle')
            ->assertJsonMissingPath('data.editor_user_ids')
            ->assertJsonMissingPath('data.created_at')
            ->assertJsonMissingPath('data.updated_at');
        $this->getApi("/public/tracks/{$trackId}")
            ->assertOk()->assertJsonPath('data.release.id', $release->public_id)
            ->assertJsonMissingPath('data.release.snapshot');

        $etag = $response->headers->get('ETag');
        $this->getApi("/public/releases/{$release->public_id}", ['If-None-Match' => $etag])->assertStatus(304);

        $superadmin = $this->domainUser('visibility-superadmin@example.com', true);
        $this->actAsDomain($superadmin);
        $this->patchApi("/artists/{$artist->public_id}/status", ['status' => 'suspended'])->assertOk();
        $this->getApi("/public/releases/{$release->public_id}")->assertNotFound();
        $this->getApi('/public/labels')->assertJsonCount(0, 'data');
        $this->patchApi("/artists/{$artist->public_id}/status", ['status' => 'active'])->assertOk();
        $this->getApi("/public/releases/{$release->public_id}")->assertOk();

        $this->actAsDomain($admin);
        $this->postApi("/releases/{$release->public_id}/unpublish")->assertOk();
        $this->getApi("/public/releases/{$release->public_id}")->assertNotFound();
        $this->getApi("/public/tracks/{$trackId}")->assertNotFound();
        $this->getApi('/public/labels')->assertJsonCount(0, 'data');
    }

    public function test_drafts_are_invisible_and_public_search_and_cursor_pagination_work(): void
    {
        [$first] = $this->publishRelease('Aurora Atlas');
        [$second, $admin, $organization, $artist] = $this->publishRelease('Boreal Echoes');
        $draft = $this->createOrganizationRelease($admin, $organization, $artist, ['title' => 'Secret Draft']);

        $this->getApi('/public/releases?filter[search]=Secret')->assertOk()->assertJsonCount(0, 'data');
        $this->getApi('/public/releases?filter[search]=Aurora')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->public_id);

        $page = $this->getApi('/public/releases?page[size]=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.pagination.has_more', true);
        $cursor = $page->json('meta.pagination.next_cursor');
        $this->getApi('/public/releases?page[size]=1&page[after]='.urlencode($cursor))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.has_more', false);
        $this->getApi('/public/releases?page[after]=invalid')->assertUnprocessable();
        $this->getApi('/public/releases?unknown=value')->assertUnprocessable();
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('published', $second->fresh()->status);
    }

    public function test_only_superadmins_can_manage_featured_releases(): void
    {
        [$release, $admin] = $this->publishRelease('Featured Record');
        $this->actAsDomain($admin);
        $this->patchApi("/releases/{$release->public_id}/featured", ['featured' => true])->assertForbidden();

        $superadmin = $this->domainUser('catalog-superadmin@example.com', true);
        $this->actAsDomain($superadmin);
        $this->patchApi("/releases/{$release->public_id}/featured", ['featured' => true])
            ->assertOk()->assertJsonPath('data.id', $release->public_id);
        $this->getApi('/public/releases/featured')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $release->public_id);
    }

    private function publishRelease(string $title): array
    {
        $suffix = str($title)->slug();
        $admin = $this->domainUser("{$suffix}@example.com");
        $organization = $this->domainOrganization($admin, "{$title} Label");
        $artist = $this->domainArtist($admin, "{$title} Artist");
        $this->linkArtist($organization, $artist, $admin);
        $cover = Media::query()->create([
            'organization_id' => $organization->id, 'kind' => 'image', 'status' => 'ready',
            'original_filename' => 'cover.png', 'mime_type' => 'image/png', 'byte_size' => 100,
            'width' => 1200, 'height' => 1200, 'storage_disk' => 'uncovr-private-media',
            'storage_key' => "covers/{$suffix}.png", 'verified_at' => now(),
            'created_by_user_id' => $admin->id, 'updated_by_user_id' => $admin->id,
        ]);
        $release = $this->createOrganizationRelease($admin, $organization, $artist, [
            'title' => $title, 'description' => "Public description for {$title}",
            'release_date' => '2026-08-09', 'cover_media_id' => $cover->public_id,
        ]);
        $trackId = $this->postApi("/releases/{$release->public_id}/tracks", [
            'position' => 1, 'title' => "{$title} Track", 'duration_ms' => 180000, 'is_explicit' => false,
        ])->assertCreated()->json('data.id');
        $this->postApi("/releases/{$release->public_id}/submit", [])->assertCreated();
        $this->postApi("/releases/{$release->public_id}/approve", [])->assertOk();
        $this->postApi("/releases/{$release->public_id}/publish")->assertOk();

        return [$release->fresh(), $admin, $organization, $artist, $trackId];
    }
}
