<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ContentBlockResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  string|null  $pageId  Public id of the owning page. Supplying it avoids
     *                               loading the `page` relation once per block.
     */
    public function __construct($resource, private readonly ?string $pageId = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'page_id' => $this->pageId ?? $this->page->public_id,
            'position' => $this->position,
            'type' => $this->type->value,
            'version' => $this->version,
            'payload' => $this->payload,
        ];
    }
}
