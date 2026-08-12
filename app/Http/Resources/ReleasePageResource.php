<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReleasePageResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('blocks.page');

        return [
            'id' => $this->public_id,
            'parent' => $this->release_id
                ? ['type' => 'release', 'id' => $this->release->public_id]
                : ['type' => 'track', 'id' => $this->track->public_id],
            'position' => $this->position,
            'title' => $this->title,
            'blocks' => $this->blocks
                ->map(fn ($block): array => (new ContentBlockResource($block))->resolve($request))
                ->all(),
        ];
    }
}
