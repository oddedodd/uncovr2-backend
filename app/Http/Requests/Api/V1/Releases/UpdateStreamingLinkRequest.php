<?php

namespace App\Http\Requests\Api\V1\Releases;

use Illuminate\Validation\Rule;

final class UpdateStreamingLinkRequest extends StoreStreamingLinkRequest
{
    public function rules(): array
    {
        return ['service' => ['sometimes', Rule::in(['spotify', 'apple_music', 'tidal', 'youtube_music', 'soundcloud', 'bandcamp', 'other'])], 'url' => ['sometimes', 'url:https', 'max:2048'], 'position' => ['sometimes', 'integer', 'min:1']];
    }
}
