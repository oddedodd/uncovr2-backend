<?php

namespace App\Http\Requests\Api\V1;

final class StoreArtistRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:150'],
            'biography' => ['nullable', 'string', 'max:10000'],
            'website_url' => ['nullable', 'url:http,https', 'max:2048'],
        ];
    }
}
