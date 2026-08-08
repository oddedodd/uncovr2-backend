<?php

namespace App\Http\Requests\Api\V1\Releases;

use App\Http\Requests\Api\V1\StrictFormRequest;

final class StoreReleaseArtistRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['artist_id' => ['required', 'ulid', 'exists:artists,public_id'], 'is_primary' => ['sometimes', 'boolean'], 'position' => ['required', 'integer', 'min:1']];
    }
}
