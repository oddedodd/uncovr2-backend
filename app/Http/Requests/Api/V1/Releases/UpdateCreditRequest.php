<?php

namespace App\Http\Requests\Api\V1\Releases;

use Illuminate\Validation\Rule;

final class UpdateCreditRequest extends StoreCreditRequest
{
    public function rules(): array
    {
        return ['contributor_id' => ['sometimes', 'ulid', 'exists:contributors,public_id'], 'role' => ['sometimes', Rule::in(['primary_artist', 'featured_artist', 'producer', 'songwriter', 'composer', 'lyricist', 'musician', 'engineer', 'photographer', 'designer', 'other'])], 'detail' => ['sometimes', 'nullable', 'string', 'max:200'], 'position' => ['sometimes', 'integer', 'min:1']];
    }
}
