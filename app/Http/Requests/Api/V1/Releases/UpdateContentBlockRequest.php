<?php

namespace App\Http\Requests\Api\V1\Releases;

use App\Enums\ContentBlockType;
use App\Http\Requests\Api\V1\StrictFormRequest;
use Illuminate\Validation\Rule;

final class UpdateContentBlockRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['position' => ['sometimes', 'integer', 'min:1'], 'type' => ['sometimes', Rule::enum(ContentBlockType::class)], 'payload' => ['sometimes', 'array']];
    }
}
