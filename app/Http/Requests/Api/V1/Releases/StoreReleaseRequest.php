<?php

namespace App\Http\Requests\Api\V1\Releases;

use App\Enums\ReleaseType;
use App\Http\Requests\Api\V1\StrictFormRequest;
use Illuminate\Validation\Rule;

final class StoreReleaseRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'owner_type' => ['required', Rule::in(['organization', 'artist'])],
            'owner_id' => ['required', 'ulid'],
            'primary_artist_id' => ['required', 'ulid'],
            'type' => ['required', Rule::enum(ReleaseType::class)],
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'subtitle' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'release_date' => ['nullable', 'date_format:Y-m-d'],
            'upc' => ['nullable', 'string', 'regex:/^[0-9]{12,14}$/'],
            'cover_media_id' => ['nullable', 'ulid'],
        ];
    }
}
