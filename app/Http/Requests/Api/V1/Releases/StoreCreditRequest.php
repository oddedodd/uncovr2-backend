<?php

namespace App\Http\Requests\Api\V1\Releases;

use App\Http\Requests\Api\V1\StrictFormRequest;
use Illuminate\Validation\Rule;

class StoreCreditRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['contributor_id' => ['required', 'ulid', 'exists:contributors,public_id'], 'role' => ['required', Rule::in(['primary_artist', 'featured_artist', 'producer', 'songwriter', 'composer', 'lyricist', 'musician', 'engineer', 'photographer', 'designer', 'other'])], 'detail' => ['nullable', 'string', 'max:200'], 'position' => ['required', 'integer', 'min:1']];
    }
}
