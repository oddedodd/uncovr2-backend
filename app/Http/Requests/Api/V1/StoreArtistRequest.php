<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ArtistRole;
use Illuminate\Validation\Rule;

final class StoreArtistRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:150'],
            'biography' => ['nullable', 'string', 'max:10000'],
            'website_url' => ['nullable', 'url:http,https', 'max:2048'],
            'creator_role' => ['sometimes', 'nullable', Rule::enum(ArtistRole::class)],
        ];
    }
}
