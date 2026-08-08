<?php

namespace Tests\Feature\Domain;

use App\Enums\OrganizationRole;
use App\Models\ContentBlock;
use App\Models\Release;
use Tests\Feature\Domain\Concerns\BuildsReleaseDomain;
use Tests\TestCase;

class CompleteDraftReleaseTest extends TestCase
{
    use BuildsReleaseDomain;

    public function test_label_user_can_build_a_complete_ordered_draft_release(): void
    {
        $admin = $this->domainUser('admin@example.com');
        $editor = $this->domainUser('editor@example.com');
        $organization = $this->domainOrganization($admin, 'North Label');
        $this->addLabelMember($organization, $editor, OrganizationRole::User);
        $artist = $this->domainArtist($admin, 'Ada Artist');
        $this->linkArtist($organization, $artist, $admin);
        $this->actAsDomain($editor);

        $coverId = $this->createMedia($organization->public_id, 'cover.jpg');
        $galleryId = $this->createMedia($organization->public_id, 'gallery.jpg');
        $videoId = $this->createMedia($organization->public_id, 'clip.mp4', 'video', 'video/mp4');
        $contributorId = $this->postApi('/contributors', [
            'owner_type' => 'organization', 'owner_id' => $organization->public_id,
            'display_name' => 'Grace Producer', 'legal_name' => null,
            'email' => null, 'website_url' => null,
        ])->assertCreated()->json('data.id');

        $releaseResponse = $this->postApi('/releases', [
            'owner_type' => 'organization', 'owner_id' => $organization->public_id,
            'primary_artist_id' => $artist->public_id, 'type' => 'album',
            'title' => 'Signals', 'subtitle' => 'The first edition',
            'description' => 'A complete test draft.', 'release_date' => '2026-11-06',
            'upc' => '123456789012', 'cover_media_id' => $coverId,
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.artists.0.is_primary', true);
        $releaseId = $releaseResponse->json('data.id');

        $trackOneId = $this->postApi("/releases/{$releaseId}/tracks", ['position' => 1, 'title' => 'Arrival', 'duration_ms' => 183000, 'isrc' => 'NOABC2600001', 'is_explicit' => false])->assertCreated()->json('data.id');
        $trackTwoId = $this->postApi("/releases/{$releaseId}/tracks", ['position' => 2, 'title' => 'Departure', 'duration_ms' => 201000, 'isrc' => 'NOABC2600002', 'is_explicit' => false])->assertCreated()->json('data.id');
        $releasePageId = $this->postApi("/releases/{$releaseId}/pages", ['position' => 1, 'title' => 'Story'])->assertCreated()->json('data.id');
        $trackPageId = $this->postApi("/tracks/{$trackOneId}/pages", ['position' => 1, 'title' => 'Lyrics and notes'])->assertCreated()->json('data.id');

        $blocks = [
            ['heading', ['text' => 'Signals', 'level' => 1]],
            ['text', ['body' => 'The story behind the record.']],
            ['image', ['media_id' => $coverId, 'alt_text' => 'Album cover', 'caption' => null]],
            ['gallery', ['items' => [['media_id' => $galleryId, 'alt_text' => 'Studio', 'caption' => 'Recording day']]]],
            ['video', ['media_id' => $videoId, 'caption' => 'Behind the scenes']],
            ['quote', ['text' => 'Music reveals the hidden.', 'attribution' => 'Ada']],
            ['lyrics', ['text' => "First line\nSecond line", 'language' => 'en']],
        ];
        foreach ($blocks as $index => [$type, $payload]) {
            $this->postApi("/pages/{$releasePageId}/blocks", ['position' => $index + 1, 'type' => $type, 'payload' => $payload])->assertCreated()->assertJsonPath('data.version', 1);
        }

        $this->postApi("/releases/{$releaseId}/streaming-links", ['service' => 'spotify', 'url' => 'https://open.spotify.com/album/example', 'position' => 1])->assertCreated();
        $this->postApi("/tracks/{$trackOneId}/streaming-links", ['service' => 'apple_music', 'url' => 'https://music.apple.com/no/song/example', 'position' => 1])->assertCreated();
        $this->postApi("/releases/{$releaseId}/credits", ['contributor_id' => $contributorId, 'role' => 'producer', 'detail' => 'Executive producer', 'position' => 1])->assertCreated();
        $this->postApi("/tracks/{$trackOneId}/credits", ['contributor_id' => $contributorId, 'role' => 'songwriter', 'detail' => null, 'position' => 1])->assertCreated();

        $this->getApi("/releases/{$releaseId}")->assertOk()
            ->assertJsonPath('data.owner.id', $organization->public_id)
            ->assertJsonPath('data.cover_media_id', $coverId)
            ->assertJsonPath('data.tracks.0.id', $trackOneId)
            ->assertJsonPath('data.tracks.1.id', $trackTwoId)
            ->assertJsonPath('data.tracks.0.pages.0.id', $trackPageId)
            ->assertJsonCount(7, 'data.pages.0.blocks')
            ->assertJsonPath('data.pages.0.blocks.6.type', 'lyrics')
            ->assertJsonPath('data.streaming_links.0.service', 'spotify')
            ->assertJsonPath('data.credits.0.contributor.display_name', 'Grace Producer');

        $release = Release::query()->where('public_id', $releaseId)->sole();
        $this->assertSame($editor->id, $release->created_by_user_id);
        $this->assertDatabaseHas('release_editors', ['release_id' => $release->id, 'user_id' => $editor->id]);
        $this->assertDatabaseCount('content_blocks', 7);
        $this->assertDatabaseCount('content_block_versions', 7);
        $this->assertGreaterThanOrEqual(16, $release->activityEvents()->count());
        $this->assertSame(7, ContentBlock::query()->count());
    }

    private function createMedia(string $ownerId, string $filename, string $kind = 'image', string $mime = 'image/jpeg'): string
    {
        return $this->postApi('/media', ['owner_type' => 'organization', 'owner_id' => $ownerId, 'kind' => $kind, 'original_filename' => $filename, 'mime_type' => $mime, 'byte_size' => 1024, 'width' => $kind === 'image' ? 1200 : null, 'height' => $kind === 'image' ? 1200 : null, 'metadata' => null])->assertCreated()->json('data.id');
    }
}
