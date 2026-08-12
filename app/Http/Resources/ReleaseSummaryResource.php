<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReleaseSummaryResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'owner' => ['type' => $this->ownerType(), 'id' => $this->organization?->public_id ?? $this->ownerArtist?->public_id],
            'type' => $this->type->value,
            'status' => $this->status,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'release_date' => $this->release_date?->format('Y-m-d'),
            'cover_media_id' => $this->coverMedia?->public_id,
            'cover_media' => $this->coverMedia
                ? (new MediaReferenceResource($this->coverMedia))->resolve($request)
                : null,
            'artists' => $this->artistLinks
                ->sortBy('position')
                ->values()
                ->map(fn ($link): array => [
                    'artist_id' => $link->artist->public_id,
                    'name' => $link->artist->profile->name,
                    'is_primary' => $link->is_primary,
                    'position' => $link->position,
                ])
                ->all(),
            'editor_user_ids' => $this->editorAssignments
                ->map(fn ($editor): string => $editor->user->public_id)
                ->all(),
            'created_at' => $this->created_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'updated_at' => $this->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
