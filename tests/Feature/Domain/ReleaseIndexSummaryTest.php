<?php

namespace Tests\Feature\Domain;

use App\Enums\ArtistRole;
use App\Enums\OrganizationRole;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Domain\Concerns\BuildsReleaseDomain;
use Tests\TestCase;

class ReleaseIndexSummaryTest extends TestCase
{
    use BuildsReleaseDomain;

    public function test_artist_filter_returns_artist_owned_and_linked_label_releases_only(): void
    {
        $labelAdmin = $this->domainUser('label-admin@example.com');
        $artistUser = $this->domainUser('artist-user@example.com');
        $otherAdmin = $this->domainUser('other-admin@example.com');

        $organization = $this->domainOrganization($labelAdmin, 'Fast Label');
        $artist = $this->domainArtist($labelAdmin, 'Visible Artist');
        $this->addArtistMember($artist, $artistUser, ArtistRole::User);
        $this->linkArtist($organization, $artist, $labelAdmin);

        $labelRelease = $this->createOrganizationRelease($labelAdmin, $organization, $artist, [
            'title' => 'Label Linked',
        ]);

        $this->actAsDomain($artistUser);
        $artistReleaseId = $this->postApi('/releases', [
            'owner_type' => 'artist',
            'owner_id' => $artist->public_id,
            'primary_artist_id' => $artist->public_id,
            'type' => 'single',
            'title' => 'Artist Owned',
            'subtitle' => null,
            'description' => null,
            'release_date' => null,
            'upc' => null,
            'cover_media_id' => null,
        ])->assertCreated()->json('data.id');

        $otherOrganization = $this->domainOrganization($otherAdmin, 'Other Label');
        $otherArtist = $this->domainArtist($otherAdmin, 'Unrelated Artist');
        $this->linkArtist($otherOrganization, $otherArtist, $otherAdmin);
        $unrelatedRelease = $this->createOrganizationRelease($otherAdmin, $otherOrganization, $otherArtist, [
            'title' => 'Unrelated',
        ]);

        $this->actAsDomain($artistUser);
        $response = $this->getApi("/releases?filter[artist_id]={$artist->public_id}&page[size]=25")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.pages')
            ->assertJsonMissingPath('data.0.streaming_links')
            ->assertJsonMissingPath('data.0.credits')
            ->assertJsonPath('data.0.artists.0.artist_id', $artist->public_id);

        $ids = array_column($response->json('data'), 'id');

        $this->assertContains($labelRelease->public_id, $ids);
        $this->assertContains($artistReleaseId, $ids);
        $this->assertNotContains($unrelatedRelease->public_id, $ids);

        $this->getApi("/releases/{$labelRelease->public_id}")
            ->assertOk()
            ->assertJsonPath('data.id', $labelRelease->public_id);
    }

    public function test_owner_filters_limit_release_listing_to_current_workspace_owner(): void
    {
        $admin = $this->domainUser('admin@example.com');
        $organization = $this->domainOrganization($admin, 'Owner Label');
        $artist = $this->domainArtist($admin, 'Owner Artist');
        $this->linkArtist($organization, $artist, $admin);
        $organizationRelease = $this->createOrganizationRelease($admin, $organization, $artist, [
            'title' => 'Organization Release',
        ]);

        $this->actAsDomain($admin);
        $artistReleaseId = $this->postApi('/releases', [
            'owner_type' => 'artist',
            'owner_id' => $artist->public_id,
            'primary_artist_id' => $artist->public_id,
            'type' => 'single',
            'title' => 'Artist Release',
            'subtitle' => null,
            'description' => null,
            'release_date' => null,
            'upc' => null,
            'cover_media_id' => null,
        ])->assertCreated()->json('data.id');

        $this->getApi("/releases?filter[owner_type]=organization&filter[owner_id]={$organization->public_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $organizationRelease->public_id)
            ->assertJsonPath('data.0.owner.type', 'organization');

        $this->getApi("/releases?filter[owner_type]=artist&filter[owner_id]={$artist->public_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $artistReleaseId)
            ->assertJsonPath('data.0.owner.type', 'artist');
    }

    public function test_index_is_summary_but_show_keeps_full_builder_data(): void
    {
        $admin = $this->domainUser('admin@example.com');
        $editor = $this->domainUser('editor@example.com');
        $organization = $this->domainOrganization($admin, 'Builder Label');
        $this->addLabelMember($organization, $editor, OrganizationRole::User);
        $artist = $this->domainArtist($admin, 'Builder Artist');
        $this->linkArtist($organization, $artist, $admin);
        $release = $this->createOrganizationRelease($editor, $organization, $artist, [
            'title' => 'Builder Release',
        ]);
        $this->actAsDomain($editor);

        $pageId = $this->postApi("/releases/{$release->public_id}/pages", [
            'position' => 1,
            'title' => 'Story',
        ])->assertCreated()->json('data.id');

        $this->postApi("/pages/{$pageId}/blocks", [
            'position' => 1,
            'type' => 'heading',
            'payload' => ['text' => 'Story', 'level' => 1],
        ])->assertCreated();

        $contributorId = $this->postApi('/contributors', [
            'owner_type' => 'organization',
            'owner_id' => $organization->public_id,
            'display_name' => 'Grace Producer',
            'legal_name' => null,
            'email' => null,
            'website_url' => null,
        ])->assertCreated()->json('data.id');

        $this->postApi("/releases/{$release->public_id}/streaming-links", [
            'service' => 'spotify',
            'url' => 'https://open.spotify.com/album/example',
            'position' => 1,
        ])->assertCreated();
        $this->postApi("/releases/{$release->public_id}/credits", [
            'contributor_id' => $contributorId,
            'role' => 'producer',
            'detail' => null,
            'position' => 1,
        ])->assertCreated();

        $this->getApi('/releases?page[size]=25')
            ->assertOk()
            ->assertJsonFragment(['id' => $release->public_id])
            ->assertJsonMissingPath('data.0.pages')
            ->assertJsonMissingPath('data.0.streaming_links')
            ->assertJsonMissingPath('data.0.credits');

        $this->getApi("/releases/{$release->public_id}")
            ->assertOk()
            ->assertJsonPath('data.pages.0.blocks.0.payload.text', 'Story')
            ->assertJsonPath('data.streaming_links.0.service', 'spotify')
            ->assertJsonPath('data.credits.0.contributor.display_name', 'Grace Producer');
    }

    public function test_artist_filtered_release_index_uses_a_small_fixed_query_count(): void
    {
        $admin = $this->domainUser('query-admin@example.com');
        $artistUser = $this->domainUser('query-artist@example.com');
        $organization = $this->domainOrganization($admin, 'Query Label');
        $artist = $this->domainArtist($admin, 'Query Artist');
        $this->addArtistMember($artist, $artistUser, ArtistRole::User);
        $this->linkArtist($organization, $artist, $admin);
        $release = $this->createOrganizationRelease($admin, $organization, $artist, [
            'title' => 'Query Release',
        ]);
        $this->actAsDomain($artistUser);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getApi("/releases?filter[artist_id]={$artist->public_id}&page[size]=25")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $release->public_id)
            ->assertJsonMissingPath('data.0.pages')
            ->assertJsonMissingPath('data.0.streaming_links')
            ->assertJsonMissingPath('data.0.credits');

        $this->assertLessThanOrEqual(
            5,
            count(DB::getQueryLog()),
            'Release index should stay at auth plus one page query, batched artists/editors queries and one batched manage-scope query.',
        );
    }

    public function test_release_detail_query_count_does_not_grow_with_page_count(): void
    {
        $admin = $this->domainUser('detail-admin@example.com');
        $organization = $this->domainOrganization($admin, 'Detail Label');
        $artist = $this->domainArtist($admin, 'Detail Artist');
        $this->linkArtist($organization, $artist, $admin);
        $release = $this->createOrganizationRelease($admin, $organization, $artist, [
            'title' => 'Detail Release',
        ]);
        $this->actAsDomain($admin);

        $addPage = function (int $position) use ($release): void {
            $pageId = $this->postApi("/releases/{$release->public_id}/pages", [
                'position' => $position,
                'title' => "Page {$position}",
            ])->assertCreated()->json('data.id');

            $this->postApi("/pages/{$pageId}/blocks", [
                'position' => 1,
                'type' => 'heading',
                'payload' => ['text' => "Heading {$position}", 'level' => 1],
            ])->assertCreated();
        };

        $measure = function () use ($release): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getApi("/releases/{$release->public_id}")->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $addPage(1);
        $addPage(2);
        $withTwoPages = $measure();

        foreach ([3, 4, 5, 6] as $position) {
            $addPage($position);
        }
        $withSixPages = $measure();

        $this->assertSame(
            $withTwoPages,
            $withSixPages,
            'Release detail must not issue additional queries per page or per content block.',
        );

        $this->assertLessThanOrEqual(
            15,
            $withTwoPages,
            'Release detail should stay at auth plus the eager loaded relation set, including editor profiles for display names.',
        );
    }
}
