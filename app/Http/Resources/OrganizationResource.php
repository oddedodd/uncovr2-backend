<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OrganizationResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('profile.logoMedia');

        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'profile' => [
                'name' => $this->profile->name,
                'legal_name' => $this->profile->legal_name,
                'description' => $this->profile->description,
                'website_url' => $this->profile->website_url,
                'logo_media_id' => $this->profile->logoMedia?->public_id,
                'logo_media' => $this->profile->logoMedia
                    ? (new MediaReferenceResource($this->profile->logoMedia))->resolve($request)
                    : null,
            ],
            'created_at' => $this->created_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'updated_at' => $this->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
