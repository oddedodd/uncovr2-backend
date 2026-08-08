<?php

namespace Tests\Feature\Domain;

use App\Models\ContentBlock;
use App\Models\Media;
use App\Models\Release;
use Tests\Feature\Domain\Concerns\BuildsReleaseDomain;
use Tests\TestCase;

class ContentBlockValidationTest extends TestCase
{
    use BuildsReleaseDomain;

    public function test_block_payloads_reject_unknown_invalid_and_cross_scope_data(): void
    {
        [$admin, $organization, $release, $pageId] = $this->releasePage();

        $this->assertApiError($this->postApi("/pages/{$pageId}/blocks", ['position' => 1, 'type' => 'heading', 'payload' => ['text' => 'Title', 'level' => 7]]), 422, 'validation_failed');
        $this->assertApiError($this->postApi("/pages/{$pageId}/blocks", ['position' => 1, 'type' => 'text', 'payload' => ['body' => 'Body', 'unexpected' => true]]), 422, 'validation_failed');
        $this->assertApiError($this->postApi("/pages/{$pageId}/blocks", ['position' => 1, 'type' => 'video', 'payload' => ['url' => 'https://video.example/test', 'media_id' => str()->ulid()->toString(), 'caption' => null]]), 422, 'validation_failed');

        $otherAdmin = $this->domainUser('other@example.com');
        $otherOrganization = $this->domainOrganization($otherAdmin, 'Other Label');
        $media = Media::query()->create(['organization_id' => $otherOrganization->id, 'kind' => 'image', 'original_filename' => 'secret.jpg', 'mime_type' => 'image/jpeg', 'created_by_user_id' => $otherAdmin->id, 'updated_by_user_id' => $otherAdmin->id]);
        $this->assertApiError($this->postApi("/pages/{$pageId}/blocks", ['position' => 1, 'type' => 'image', 'payload' => ['media_id' => $media->public_id, 'alt_text' => 'Secret', 'caption' => null]]), 422, 'validation_failed');
        $this->assertDatabaseCount('content_blocks', 0);
    }

    public function test_each_update_creates_an_immutable_version_and_soft_delete_preserves_history(): void
    {
        [, , $release, $pageId] = $this->releasePage();
        $blockId = $this->postApi("/pages/{$pageId}/blocks", ['position' => 1, 'type' => 'text', 'payload' => ['body' => 'Version one']])->assertCreated()->json('data.id');
        $this->patchApi("/pages/{$pageId}/blocks/{$blockId}", ['payload' => ['body' => 'Version two']])->assertOk()->assertJsonPath('data.version', 2);

        $this->getApi("/pages/{$pageId}/blocks/{$blockId}/versions")->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.payload.body', 'Version one')->assertJsonPath('data.1.payload.body', 'Version two');
        $this->deleteApi("/pages/{$pageId}/blocks/{$blockId}")->assertOk();

        $block = ContentBlock::withTrashed()->where('public_id', $blockId)->sole();
        $this->assertNotNull($block->deleted_at);
        $this->assertSame(2, $block->versions()->count());
        $this->assertDatabaseHas('release_activity_events', ['release_id' => $release->id, 'event_type' => 'content_block.deleted']);
    }

    public function test_deleting_a_release_hides_its_tree_but_preserves_versions_and_activity(): void
    {
        [, , $release, $pageId] = $this->releasePage();
        $blockId = $this->postApi("/pages/{$pageId}/blocks", ['position' => 1, 'type' => 'text', 'payload' => ['body' => 'Preserved']])->assertCreated()->json('data.id');
        $this->deleteApi("/releases/{$release->public_id}")->assertOk();

        $this->getApi("/releases/{$release->public_id}")->assertNotFound();
        $this->getApi("/pages/{$pageId}/blocks/{$blockId}/versions")->assertNotFound();
        $this->assertNotNull(Release::withTrashed()->findOrFail($release->id)->deleted_at);
        $this->assertNotNull(ContentBlock::withTrashed()->where('public_id', $blockId)->sole()->deleted_at);
        $this->assertDatabaseHas('content_block_versions', ['content_block_id' => ContentBlock::withTrashed()->where('public_id', $blockId)->sole()->id, 'version' => 1]);
        $this->assertDatabaseHas('release_activity_events', ['release_id' => $release->id, 'event_type' => 'release.deleted']);
    }

    private function releasePage(): array
    {
        $admin = $this->domainUser('admin@example.com');
        $organization = $this->domainOrganization($admin);
        $artist = $this->domainArtist($admin);
        $this->linkArtist($organization, $artist, $admin);
        $release = $this->createOrganizationRelease($admin, $organization, $artist);
        $pageId = $this->postApi("/releases/{$release->public_id}/pages", ['position' => 1, 'title' => 'Page'])->assertCreated()->json('data.id');

        return [$admin, $organization, $release, $pageId];
    }
}
