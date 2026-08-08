<?php

namespace App\Http\Requests\Api\V1;

final class UpdateArtistRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:150'],
            'biography' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'website_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
        ];
    }
}
