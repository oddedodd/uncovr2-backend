<?php

namespace Tests\Feature\Domain;

use App\Enums\OrganizationRole;
use Tests\Feature\Domain\Concerns\BuildsReleaseDomain;
use Tests\TestCase;

class ReleasePageBuilderTest extends TestCase
{
    use BuildsReleaseDomain;

    public function test_release_resource_is_page_and_block_based_and_omits_legacy_tracks(): void
    {
        [$admin, $release] = $this->releaseContext();
        $pageId = $this->postApi("/releases/{$release->public_id}/pages", [
            'position' => 1,
            'title' => 'Front cover',
        ])->assertCreated()
            ->assertJsonPath('data.parent.type', 'release')
            ->assertJsonPath('data.blocks', [])
            ->json('data.id');

        $blockId = $this->postApi("/pages/{$pageId}/blocks", [
            'position' => 1,
            'type' => 'heading',
            'payload' => ['text' => 'The story', 'level' => 1],
        ])->assertCreated()
            ->assertJsonPath('data.page_id', $pageId)
            ->json('data.id');

        $this->getApi("/releases/{$release->public_id}")
            ->assertOk()
            ->assertJsonMissingPath('data.tracks')
            ->assertJsonPath('data.pages.0.id', $pageId)
            ->assertJsonPath('data.pages.0.parent.type', 'release')
            ->assertJsonPath('data.pages.0.blocks.0.id', $blockId)
            ->assertJsonPath('data.pages.0.blocks.0.payload.text', 'The story');
    }

    public function test_release_policy_is_the_authorization_boundary_for_pages_blocks_and_versions(): void
    {
        [$admin, $release, $organization] = $this->releaseContext(withOrganization: true);
        $viewer = $this->domainUser('viewer@example.com');
        $editor = $this->domainUser('editor@example.com');
        $outsider = $this->domainUser('outsider@example.com');
        $this->addLabelMember($organization, $viewer, OrganizationRole::User);
        $this->addLabelMember($organization, $editor, OrganizationRole::User);

        $pageId = $this->postApi("/releases/{$release->public_id}/pages", ['position' => 1, 'title' => 'Page'])->assertCreated()->json('data.id');
        $blockId = $this->postApi("/pages/{$pageId}/blocks", [
            'position' => 1,
            'type' => 'text',
            'payload' => ['body' => 'Version one'],
        ])->assertCreated()->json('data.id');

        $this->actAsDomain($viewer);
        $this->getApi("/releases/{$release->public_id}")->assertOk();
        $this->getApi("/pages/{$pageId}/blocks/{$blockId}/versions")->assertOk();
        $this->postApi("/releases/{$release->public_id}/pages", ['position' => 2, 'title' => 'Forbidden'])->assertForbidden();
        $this->patchApi("/pages/{$pageId}", ['title' => 'Forbidden'])->assertForbidden();
        $this->postApi("/pages/{$pageId}/blocks", ['position' => 2, 'type' => 'text', 'payload' => ['body' => 'Forbidden']])->assertForbidden();
        $this->patchApi("/pages/{$pageId}/blocks/{$blockId}", ['payload' => ['body' => 'Forbidden']])->assertForbidden();
        $this->deleteApi("/pages/{$pageId}/blocks/{$blockId}")->assertForbidden();

        $this->actAsDomain($admin);
        $this->postApi("/releases/{$release->public_id}/editors", ['user_id' => $editor->public_id])->assertCreated();

        $this->actAsDomain($editor);
        $this->patchApi("/pages/{$pageId}", ['title' => 'Booklet'])->assertOk();
        $this->patchApi("/pages/{$pageId}/blocks/{$blockId}", ['payload' => ['body' => 'Version two']])
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->actAsDomain($outsider);
        $this->getApi("/releases/{$release->public_id}")->assertForbidden();
        $this->getApi("/pages/{$pageId}/blocks/{$blockId}/versions")->assertForbidden();
    }

    public function test_nested_block_route_rejects_a_block_from_another_page(): void
    {
        [, $release] = $this->releaseContext();
        $firstPage = $this->postApi("/releases/{$release->public_id}/pages", ['position' => 1, 'title' => 'First'])->assertCreated()->json('data.id');
        $secondPage = $this->postApi("/releases/{$release->public_id}/pages", ['position' => 2, 'title' => 'Second'])->assertCreated()->json('data.id');
        $blockId = $this->postApi("/pages/{$firstPage}/blocks", [
            'position' => 1,
            'type' => 'text',
            'payload' => ['body' => 'First page only'],
        ])->assertCreated()->json('data.id');

        $this->patchApi("/pages/{$secondPage}/blocks/{$blockId}", ['payload' => ['body' => 'Moved']])->assertNotFound();
        $this->deleteApi("/pages/{$secondPage}/blocks/{$blockId}")->assertNotFound();
        $this->getApi("/pages/{$secondPage}/blocks/{$blockId}/versions")->assertNotFound();
    }

    private function releaseContext(bool $withOrganization = false): array
    {
        $admin = $this->domainUser('admin@example.com');
        $organization = $this->domainOrganization($admin);
        $artist = $this->domainArtist($admin);
        $this->linkArtist($organization, $artist, $admin);
        $release = $this->createOrganizationRelease($admin, $organization, $artist);

        return $withOrganization ? [$admin, $release, $organization] : [$admin, $release];
    }
}
