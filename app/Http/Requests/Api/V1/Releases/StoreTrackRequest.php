<?php

namespace App\Http\Requests\Api\V1\Releases;

use App\Http\Requests\Api\V1\StrictFormRequest;

class StoreTrackRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['position' => ['required', 'integer', 'min:1'], 'title' => ['required', 'string', 'min:1', 'max:200'], 'duration_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'], 'isrc' => ['nullable', 'string', 'regex:/^[A-Z]{2}[A-Z0-9]{3}[0-9]{7}$/'], 'is_explicit' => ['sometimes', 'boolean']];
    }
}
