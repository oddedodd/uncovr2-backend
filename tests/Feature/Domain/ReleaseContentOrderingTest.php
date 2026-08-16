<?php

namespace Tests\Feature\Domain;

use App\Enums\OrganizationRole;
use App\Models\Release;
use Tests\Feature\Domain\Concerns\BuildsReleaseDomain;
use Tests\TestCase;

class ReleaseContentOrderingTest extends TestCase
{
    use BuildsReleaseDomain;

    public function test_reordering_pages_renumbers_the_release_contiguously(): void
    {
        [, $release] = $this->releaseContext();
        [$first, $second, $third] = $this->pages($release, 'First', 'Second', 'Third');

        $this->putApi("/releases/{$release->public_id}/pages/order", ['page_ids' => [$third, $first, $second]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $third)
            ->assertJsonPath('data.0.position', 1)
            ->assertJsonPath('data.1.id', $first)
            ->assertJsonPath('data.1.position', 2)
            ->assertJsonPath('data.2.id', $second)
            ->assertJsonPath('data.2.position', 3);

        $this->getApi("/releases/{$release->public_id}")
            ->assertOk()
            ->assertJsonPath('data.pages.0.id', $third)
            ->assertJsonPath('data.pages.1.id', $first)
            ->assertJsonPath('data.pages.2.id', $second);
    }

    public function test_page_order_must_be_a_complete_permutation(): void
    {
        [$admin, $release] = $this->releaseContext();
        [$first, $second] = $this->pages($release, 'First', 'Second');
        [, $other] = $this->releaseContext('second@example.com');
        [$foreign] = $this->pages($other, 'Foreign');
        $this->actAsDomain($admin);

        $details = ['fields' => ['page_ids' => ['The order must list every page in the release exactly once.']]];
        $this->assertApiError($this->putApi("/releases/{$release->public_id}/pages/order", ['page_ids' => [$second]]), 422, 'validation_failed', null, $details);
        $this->assertApiError($this->putApi("/releases/{$release->public_id}/pages/order", ['page_ids' => [$first, $second, $foreign]]), 422, 'validation_failed', null, $details);
        $this->assertApiError($this->putApi("/releases/{$release->public_id}/pages/order", ['page_ids' => [$first, $first]]), 422, 'validation_failed');
        $this->assertApiError($this->putApi("/releases/{$release->public_id}/pages/order", ['page_ids' => []]), 422, 'validation_failed');

        $this->getApi("/releases/{$release->public_id}")
            ->assertJsonPath('data.pages.0.id', $first)
            ->assertJsonPath('data.pages.1.id', $second);
    }

    public function test_patching_a_page_position_moves_it_instead_of_colliding(): void
    {
        [, $release] = $this->releaseContext();
        [$first, $second] = $this->pages($release, 'First', 'Second');

        // The portal's "move down": send the neighbour's current position.
        $this->patchApi("/pages/{$first}", ['position' => 2])
            ->assertOk()
            ->assertJsonPath('data.position', 2);

        $this->getApi("/releases/{$release->public_id}")
            ->assertJsonPath('data.pages.0.id', $second)
            ->assertJsonPath('data.pages.0.position', 1)
            ->assertJsonPath('data.pages.1.id', $first)
            ->assertJsonPath('data.pages.1.position', 2);
    }

    public function test_a_page_position_beyond_the_sibling_count_moves_it_last(): void
    {
        [, $release] = $this->releaseContext();
        [$first, $second, $third] = $this->pages($release, 'First', 'Second', 'Third');

        $this->patchApi("/pages/{$first}", ['position' => 99, 'title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.position', 3)
            ->assertJsonPath('data.title', 'Renamed');

        $this->getApi("/releases/{$release->public_id}")
            ->assertJsonPath('data.pages.0.id', $second)
            ->assertJsonPath('data.pages.1.id', $third)
            ->assertJsonPath('data.pages.2.id', $first);
    }

    public function test_reordering_closes_gaps_left_by_earlier_deletions(): void
    {
        [, $release] = $this->releaseContext();
        [$first, $second, $third] = $this->pages($release, 'First', 'Second', 'Third');
        $this->deleteApi("/pages/{$second}")->assertOk();

        // Positions are now 1 and 3. The renumbering has to survive a parent whose
        // positions are not contiguous, which is the normal state after a delete.
        $this->putApi("/releases/{$release->public_id}/pages/order", ['page_ids' => [$third, $first]])
            ->assertOk()
            ->assertJsonPath('data.0.position', 1)
            ->assertJsonPath('data.1.position', 2);

        $this->getApi("/releases/{$release->public_id}")
            ->assertJsonPath('data.pages.0.id', $third)
            ->assertJsonPath('data.pages.1.id', $first);
    }

    public function test_reordering_records_one_activity_event_with_the_resulting_order(): void
    {
        [, $release] = $this->releaseContext();
        [$first, $second] = $this->pages($release, 'First', 'Second');

        $this->putApi("/releases/{$release->public_id}/pages/order", ['page_ids' => [$second, $first]])->assertOk();

        $events = collect($this->getApi("/releases/{$release->public_id}/activity")->assertOk()->json('data'))
            ->where('event_type', 'page.reordered');

        $this->assertCount(1, $events);
        $this->assertSame([$second, $first], $events->first()['changes']['page_ids']);
    }

    public function test_an_unchanged_order_is_accepted_and_returns_the_current_positions(): void
    {
        [, $release] = $this->releaseContext();
        [$first, $second] = $this->pages($release, 'First', 'Second');

        $this->putApi("/releases/{$release->public_id}/pages/order", ['page_ids' => [$first, $second]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $first)
            ->assertJsonPath('data.0.position', 1)
            ->assertJsonPath('data.1.id', $second)
            ->assertJsonPath('data.1.position', 2);
    }

    public function test_reordering_blocks_renumbers_the_page_without_touching_versions(): void
    {
        [, $release] = $this->releaseContext();
        [$page] = $this->pages($release, 'Booklet');
        $first = $this->block($page, 'One');
        $second = $this->block($page, 'Two', 2);

        $this->putApi("/pages/{$page}/blocks/order", ['block_ids' => [$second, $first]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $second)
            ->assertJsonPath('data.0.position', 1)
            ->assertJsonPath('data.0.version', 1)
            ->assertJsonPath('data.1.id', $first)
            ->assertJsonPath('data.1.position', 2)
            ->assertJsonPath('data.1.version', 1);

        $this->getApi("/releases/{$release->public_id}")
            ->assertJsonPath('data.pages.0.blocks.0.id', $second)
            ->assertJsonPath('data.pages.0.blocks.1.id', $first);

        $this->getApi("/pages/{$page}/blocks/{$first}/versions")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_block_order_must_be_a_complete_permutation(): void
    {
        [, $release] = $this->releaseContext();
        [$page, $otherPage] = $this->pages($release, 'Booklet', 'Insert');
        $first = $this->block($page, 'One');
        $second = $this->block($page, 'Two', 2);
        $foreign = $this->block($otherPage, 'Elsewhere');

        $details = ['fields' => ['block_ids' => ['The order must list every block on the page exactly once.']]];
        $this->assertApiError($this->putApi("/pages/{$page}/blocks/order", ['block_ids' => [$second]]), 422, 'validation_failed', null, $details);
        $this->assertApiError($this->putApi("/pages/{$page}/blocks/order", ['block_ids' => [$first, $second, $foreign]]), 422, 'validation_failed', null, $details);
        $this->assertApiError($this->putApi("/pages/{$page}/blocks/order", ['block_ids' => [$first, $first]]), 422, 'validation_failed');
        $this->assertApiError($this->putApi("/pages/{$page}/blocks/order", ['block_ids' => []]), 422, 'validation_failed');

        $this->getApi("/releases/{$release->public_id}")
            ->assertJsonPath('data.pages.0.blocks.0.id', $first)
            ->assertJsonPath('data.pages.0.blocks.1.id', $second);
    }

    public function test_reordering_blocks_closes_gaps_left_by_earlier_deletions(): void
    {
        [, $release] = $this->releaseContext();
        [$page] = $this->pages($release, 'Booklet');
        $first = $this->block($page, 'One');
        $second = $this->block($page, 'Two', 2);
        $third = $this->block($page, 'Three', 3);
        $this->deleteApi("/pages/{$page}/blocks/{$second}")->assertOk();

        $this->putApi("/pages/{$page}/blocks/order", ['block_ids' => [$third, $first]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $third)
            ->assertJsonPath('data.0.position', 1)
            ->assertJsonPath('data.1.id', $first)
            ->assertJsonPath('data.1.position', 2);
    }

    public function test_blocks_on_a_track_page_reorder_through_the_owning_release(): void
    {
        [$admin, $release, $organization] = $this->releaseContext(withOrganization: true);
        $track = $this->postApi("/releases/{$release->public_id}/tracks", ['position' => 1, 'title' => 'Track', 'duration_ms' => 1000, 'is_explicit' => false])
            ->assertCreated()->json('data.id');
        $page = $this->postApi("/tracks/{$track}/pages", ['position' => 1, 'title' => 'Track page'])
            ->assertCreated()->json('data.id');
        $first = $this->block($page, 'One');
        $second = $this->block($page, 'Two', 2);

        $viewer = $this->domainUser('viewer@example.com');
        $this->addLabelMember($organization, $viewer, OrganizationRole::User);
        $this->actAsDomain($viewer);
        $this->putApi("/pages/{$page}/blocks/order", ['block_ids' => [$second, $first]])->assertForbidden();

        $this->actAsDomain($admin);
        $this->putApi("/pages/{$page}/blocks/order", ['block_ids' => [$second, $first]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $second)
            ->assertJsonPath('data.0.position', 1)
            ->assertJsonPath('data.0.version', 1)
            ->assertJsonPath('data.1.id', $first)
            ->assertJsonPath('data.1.position', 2);
    }

    public function test_patching_a_block_position_moves_it_instead_of_colliding(): void
    {
        [, $release] = $this->releaseContext();
        [$page] = $this->pages($release, 'Booklet');
        $first = $this->block($page, 'One');
        $second = $this->block($page, 'Two', 2);

        $this->patchApi("/pages/{$page}/blocks/{$first}", ['position' => 2])
            ->assertOk()
            ->assertJsonPath('data.position', 2);

        $this->getApi("/releases/{$release->public_id}")
            ->assertJsonPath('data.pages.0.blocks.0.id', $second)
            ->assertJsonPath('data.pages.0.blocks.1.id', $first);
    }

    public function test_ordering_routes_use_the_release_policy_as_their_boundary(): void
    {
        [$admin, $release, $organization] = $this->releaseContext(withOrganization: true);
        [$first, $second] = $this->pages($release, 'First', 'Second');
        $page = $first;
        $block = $this->block($page, 'One');

        $viewer = $this->domainUser('viewer@example.com');
        $this->addLabelMember($organization, $viewer, OrganizationRole::User);

        $this->actAsDomain($viewer);
        $this->putApi("/releases/{$release->public_id}/pages/order", ['page_ids' => [$second, $first]])->assertForbidden();
        $this->putApi("/pages/{$page}/blocks/order", ['block_ids' => [$block]])->assertForbidden();

        $this->actAsDomain($admin);
        $this->putApi("/releases/{$release->public_id}/pages/order", ['page_ids' => [$second, $first]])->assertOk();
    }

    /**
     * @return list<string>
     */
    private function pages(Release $release, string ...$titles): array
    {
        $ids = [];
        foreach ($titles as $index => $title) {
            $ids[] = $this->postApi("/releases/{$release->public_id}/pages", ['position' => $index + 1, 'title' => $title])
                ->assertCreated()
                ->json('data.id');
        }

        return $ids;
    }

    private function block(string $pageId, string $body, int $position = 1): string
    {
        return $this->postApi("/pages/{$pageId}/blocks", [
            'position' => $position,
            'type' => 'text',
            'payload' => ['body' => $body],
        ])->assertCreated()->json('data.id');
    }

    private function releaseContext(string $email = 'admin@example.com', bool $withOrganization = false): array
    {
        $admin = $this->domainUser($email);
        $organization = $this->domainOrganization($admin);
        $artist = $this->domainArtist($admin);
        $this->linkArtist($organization, $artist, $admin);
        $release = $this->createOrganizationRelease($admin, $organization, $artist);

        return $withOrganization ? [$admin, $release, $organization] : [$admin, $release];
    }
}
