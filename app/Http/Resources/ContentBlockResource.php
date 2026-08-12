<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ContentBlockResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'page_id' => $this->page->public_id,
            'position' => $this->position,
            'type' => $this->type->value,
            'version' => $this->version,
            'payload' => $this->payload,
        ];
    }
}
