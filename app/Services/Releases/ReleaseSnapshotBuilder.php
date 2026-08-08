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
            'editorAssignments.user.profile', 'tracks.pages.blocks', 'tracks.streamingLinks',
            'tracks.credits.contributor', 'pages.blocks', 'streamingLinks', 'credits.contributor',
        ]);
        $snapshot = (new ReleaseResource($release))->resolve();

        return Arr::except($snapshot, ['editor_user_ids', 'created_at', 'updated_at']);
    }

    public function fingerprint(Release $release): string
    {
        $content = Arr::except($this->build($release), ['status', 'lifecycle']);

        return hash('sha256', json_encode($this->canonicalize($content), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<int, Media> */
    public function referencedMedia(Release $release): array
    {
        $ids = [];
        if ($release->coverMedia) {
            $ids[$release->coverMedia->public_id] = $release->coverMedia;
        }
        foreach ($release->pages->concat($release->tracks->flatMap->pages)->flatMap->blocks as $block) {
            $this->collectUlids($block->payload, $ids, $release);
        }

        return array_values($ids);
    }

    private function collectUlids(mixed $value, array &$ids, Release $release): void
    {
        if (is_array($value)) {
            foreach ($value as $nested) {
                $this->collectUlids($nested, $ids, $release);
            }

            return;
        }
        if (! is_string($value) || ! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $value) || isset($ids[$value])) {
            return;
        }
        $media = Media::query()->where('public_id', $value)->first();
        if ($media && $media->organization_id === $release->organization_id && $media->artist_id === $release->artist_id) {
            $ids[$value] = $media;
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
