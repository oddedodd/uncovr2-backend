<?php

namespace App\Services\Releases;

use App\Http\Resources\ReleaseResource;
use App\Models\Media;
use App\Models\Release;
use Illuminate\Support\Arr;

final class ReleaseSnapshotBuilder
{
    public function build(Release $release): array
    {
        $release->loadMissing([
            'organization', 'ownerArtist', 'coverMedia', 'artistLinks.artist.profile',
            'editorAssignments.user', 'pages.blocks', 'streamingLinks', 'credits.contributor',
        ]);
        $snapshot = (new ReleaseResource($release))->resolve();

        return Arr::except($snapshot, ['editor_user_ids', 'created_at', 'updated_at']);
    }

    /**
     * Preserve published-track compatibility while the listener track domain is
     * migrated. Tracks are intentionally absent from the portal builder resource.
     */
    public function buildForPublication(Release $release): array
    {
        $snapshot = $this->build($release);
        $release->loadMissing(['tracks.pages.blocks', 'tracks.streamingLinks', 'tracks.credits.contributor']);
        $snapshot['tracks'] = $release->tracks->map(fn ($track): array => [
            'id' => $track->public_id,
            'position' => $track->position,
            'title' => $track->title,
            'duration_ms' => $track->duration_ms,
            'isrc' => $track->isrc,
            'is_explicit' => $track->is_explicit,
            'pages' => $track->pages->map(fn ($page): array => [
                'id' => $page->public_id,
                'position' => $page->position,
                'title' => $page->title,
                'blocks' => $page->blocks->map(fn ($block): array => [
                    'id' => $block->public_id,
                    'position' => $block->position,
                    'type' => $block->type->value,
                    'version' => $block->version,
                    'payload' => $block->payload,
                ])->all(),
            ])->all(),
            'streaming_links' => $track->streamingLinks->map(fn ($link): array => [
                'id' => $link->public_id,
                'service' => $link->service,
                'url' => $link->url,
                'position' => $link->position,
            ])->all(),
            'credits' => $track->credits->map(fn ($credit): array => [
                'id' => $credit->public_id,
                'contributor' => [
                    'id' => $credit->contributor->public_id,
                    'display_name' => $credit->contributor->display_name,
                ],
                'role' => $credit->role,
                'detail' => $credit->detail,
                'position' => $credit->position,
            ])->all(),
        ])->all();

        return $snapshot;
    }

    public function fingerprint(Release $release): string
    {
        $content = Arr::except($this->build($release), ['status', 'lifecycle']);

        return hash('sha256', json_encode($this->canonicalize($content), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<int, Media> */
    public function referencedMedia(Release $release): array
    {
        $release->loadMissing(['coverMedia', 'pages.blocks', 'tracks.pages.blocks']);

        $ids = [];
        if ($release->coverMedia) {
            $ids[$release->coverMedia->public_id] = $release->coverMedia;
        }
        // Track-page media remains publishable only for the temporary legacy
        // listener snapshot. The portal builder itself exposes release pages only.
        $candidates = [];
        foreach ($release->pages->concat($release->tracks->flatMap->pages)->flatMap->blocks as $block) {
            $this->collectUlids($block->payload, $candidates);
        }

        $candidates = array_diff(array_keys($candidates), array_keys($ids));
        if ($candidates !== []) {
            foreach (Media::query()->whereIn('public_id', $candidates)->get() as $media) {
                if ($media->organization_id === $release->organization_id && $media->artist_id === $release->artist_id) {
                    $ids[$media->public_id] = $media;
                }
            }
        }

        return array_values($ids);
    }

    /**
     * Collects every ULID-shaped string in a block payload. Resolution happens in
     * one batched query afterwards rather than one query per candidate.
     *
     * @param  array<string, true>  $candidates
     */
    private function collectUlids(mixed $value, array &$candidates): void
    {
        if (is_array($value)) {
            foreach ($value as $nested) {
                $this->collectUlids($nested, $candidates);
            }

            return;
        }
        if (is_string($value) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $value)) {
            $candidates[$value] = true;
        }
    }

    private function canonicalize(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonicalize($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
