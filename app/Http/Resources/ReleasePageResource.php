<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReleasePageResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  string|null  $parentPublicId  Public id of the owning release or track.
     *                                       Supplying it avoids loading the parent
     *                                       relation once per page.
     */
    public function __construct($resource, private readonly ?string $parentPublicId = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('blocks');

        return [
            'id' => $this->public_id,
            'parent' => $this->release_id
                ? ['type' => 'release', 'id' => $this->parentPublicId ?? $this->release->public_id]
                : ['type' => 'track', 'id' => $this->parentPublicId ?? $this->track->public_id],
            'position' => $this->position,
            'title' => $this->title,
            'blocks' => $this->blocks
                ->map(fn ($block): array => (new ContentBlockResource($block, $this->public_id))->resolve($request))
                ->all(),
        ];
    }
}
