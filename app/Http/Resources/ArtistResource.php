<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ArtistResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['profile.logoMedia', 'profile.imageMedia']);

        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'profile' => [
                'name' => $this->profile->name,
                'biography' => $this->profile->biography,
                'website_url' => $this->profile->website_url,
                'logo_media_id' => $this->profile->logoMedia?->public_id,
                'image_media_id' => $this->profile->imageMedia?->public_id,
                'logo_media' => $this->profile->logoMedia
                    ? (new MediaReferenceResource($this->profile->logoMedia))->resolve($request)
                    : null,
                'image_media' => $this->profile->imageMedia
                    ? (new MediaReferenceResource($this->profile->imageMedia))->resolve($request)
                    : null,
            ],
            'created_at' => $this->created_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'updated_at' => $this->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
