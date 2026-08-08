<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ArtistResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'profile' => [
                'name' => $this->profile->name,
                'biography' => $this->profile->biography,
                'website_url' => $this->profile->website_url,
            ],
            'created_at' => $this->created_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'updated_at' => $this->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
