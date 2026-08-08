<?php

namespace App\Http\Requests\Api\V1\Releases;

use App\Enums\ReleaseType;
use App\Http\Requests\Api\V1\StrictFormRequest;
use Illuminate\Validation\Rule;

final class UpdateReleaseRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::enum(ReleaseType::class)],
            'title' => ['sometimes', 'string', 'min:1', 'max:200'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'release_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'upc' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{12,14}$/'],
            'cover_media_id' => ['sometimes', 'nullable', 'ulid'],
            'owner_type' => ['prohibited'], 'owner_id' => ['prohibited'], 'status' => ['prohibited'],
        ];
    }
}
