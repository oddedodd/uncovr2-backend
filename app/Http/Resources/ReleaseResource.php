<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReleaseResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $this->resource->loadMissing([
            'artistLinks.artist.profile', 'editorAssignments.user.profile',
            'tracks.pages.blocks', 'tracks.streamingLinks', 'tracks.credits.contributor',
            'pages.blocks', 'streamingLinks', 'credits.contributor', 'coverMedia',
        ]);

        return [
            'id' => $this->public_id,
            'owner' => ['type' => $this->ownerType(), 'id' => $this->organization?->public_id ?? $this->ownerArtist?->public_id],
            'type' => $this->type->value, 'status' => $this->status, 'title' => $this->title,
            'subtitle' => $this->subtitle, 'description' => $this->description,
            'release_date' => $this->release_date?->format('Y-m-d'), 'upc' => $this->upc,
            'cover_media_id' => $this->coverMedia?->public_id,
            'cover_media' => $this->coverMedia
                ? (new MediaReferenceResource($this->coverMedia))->resolve($request)
                : null,
            'lifecycle' => [
                'submitted_at' => $this->submitted_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'approved_at' => $this->approved_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'scheduled_for' => $this->scheduled_for?->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'published_at' => $this->published_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'unpublished_at' => $this->unpublished_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'archived_at' => $this->archived_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'publication_version' => $this->publication_version,
            ],
            'artists' => $this->artistLinks->sortBy('position')->values()->map(fn ($link) => ['artist_id' => $link->artist->public_id, 'name' => $link->artist->profile->name, 'is_primary' => $link->is_primary, 'position' => $link->position])->all(),
            'editor_user_ids' => $this->editorAssignments->map(fn ($editor) => $editor->user->public_id)->all(),
            'tracks' => $this->tracks->map(fn ($track) => $this->track($track))->all(),
            'pages' => $this->pages->map(fn ($page) => $this->page($page))->all(),
            'streaming_links' => $this->streamingLinks->map(fn ($link) => $this->link($link))->all(),
            'credits' => $this->credits->map(fn ($credit) => $this->credit($credit))->all(),
            'created_at' => $this->created_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'updated_at' => $this->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    private function track($track): array
    {
        return ['id' => $track->public_id, 'position' => $track->position, 'title' => $track->title, 'duration_ms' => $track->duration_ms, 'isrc' => $track->isrc, 'is_explicit' => $track->is_explicit, 'pages' => $track->pages->map(fn ($page) => $this->page($page))->all(), 'streaming_links' => $track->streamingLinks->map(fn ($link) => $this->link($link))->all(), 'credits' => $track->credits->map(fn ($credit) => $this->credit($credit))->all()];
    }

    private function page($page): array
    {
        return ['id' => $page->public_id, 'position' => $page->position, 'title' => $page->title, 'blocks' => $page->blocks->map(fn ($block) => ['id' => $block->public_id, 'position' => $block->position, 'type' => $block->type->value, 'version' => $block->version, 'payload' => $block->payload])->all()];
    }

    private function link($link): array
    {
        return ['id' => $link->public_id, 'service' => $link->service, 'url' => $link->url, 'position' => $link->position];
    }

    private function credit($credit): array
    {
        return ['id' => $credit->public_id, 'contributor' => ['id' => $credit->contributor->public_id, 'display_name' => $credit->contributor->display_name], 'role' => $credit->role, 'detail' => $credit->detail, 'position' => $credit->position];
    }
}
