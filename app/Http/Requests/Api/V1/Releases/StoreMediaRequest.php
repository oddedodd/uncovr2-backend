<?php

namespace App\Http\Requests\Api\V1\Releases;

use App\Http\Requests\Api\V1\StrictFormRequest;
use Illuminate\Validation\Rule;

class StoreMediaRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['owner_type' => ['required', Rule::in(['organization', 'artist'])], 'owner_id' => ['required', 'ulid'], 'kind' => ['required', Rule::in(['image', 'audio', 'video', 'document'])], 'original_filename' => ['required', 'string', 'max:255'], 'mime_type' => ['required', 'string', 'max:100'], 'byte_size' => ['nullable', 'integer', 'min:0'], 'width' => ['nullable', 'integer', 'min:1'], 'height' => ['nullable', 'integer', 'min:1'], 'metadata' => ['nullable', 'array']];
    }
}
