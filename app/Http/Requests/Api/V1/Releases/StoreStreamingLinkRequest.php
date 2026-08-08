<?php

namespace App\Http\Requests\Api\V1\Releases;

use App\Http\Requests\Api\V1\StrictFormRequest;
use Illuminate\Validation\Rule;

class StoreStreamingLinkRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['service' => ['required', Rule::in(['spotify', 'apple_music', 'tidal', 'youtube_music', 'soundcloud', 'bandcamp', 'other'])], 'url' => ['required', 'url:https', 'max:2048'], 'position' => ['required', 'integer', 'min:1']];
    }
}
