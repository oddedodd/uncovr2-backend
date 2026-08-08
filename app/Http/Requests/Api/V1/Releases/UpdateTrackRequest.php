<?php

namespace App\Http\Requests\Api\V1\Releases;

final class UpdateTrackRequest extends StoreTrackRequest
{
    public function rules(): array
    {
        return [
            'position' => ['sometimes', 'integer', 'min:1'],
            'title' => ['sometimes', 'string', 'min:1', 'max:200'],
            'duration_ms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:86400000'],
            'isrc' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}[A-Z0-9]{3}[0-9]{7}$/'],
            'is_explicit' => ['sometimes', 'boolean'],
        ];
    }
}
