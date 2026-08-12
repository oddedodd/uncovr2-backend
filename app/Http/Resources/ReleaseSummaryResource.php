<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReleaseSummaryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  array<int, array{artist_id: string, name: string, is_primary: bool, position: int}>  $artists
     * @param  array<int, string>  $editorUserIds
     */
    public function __construct(
        mixed $resource,
        private readonly array $artists = [],
        private readonly array $editorUserIds = [],
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $coverMediaId = $this->resource->getAttribute('cover_media_public_id');

        return [
            'id' => $this->public_id,
            'owner' => [
                'type' => $this->ownerType(),
                'id' => $this->resource->getAttribute('owner_organization_public_id')
                    ?? $this->resource->getAttribute('owner_artist_public_id'),
            ],
            'type' => $this->type->value,
            'status' => $this->status,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'release_date' => $this->release_date?->format('Y-m-d'),
            'cover_media_id' => $coverMediaId,
            'cover_media' => $coverMediaId !== null
                ? [
                    'id' => $coverMediaId,
                    'status' => $this->resource->getAttribute('cover_media_status'),
                    'mime_type' => $this->resource->getAttribute('cover_media_mime_type'),
                    'width' => $this->resource->getAttribute('cover_media_width'),
                    'height' => $this->resource->getAttribute('cover_media_height'),
                ]
                : null,
            'artists' => $this->artists,
            'editor_user_ids' => $this->editorUserIds,
            'created_at' => $this->created_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'updated_at' => $this->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
