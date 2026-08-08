<?php

namespace App\Http\Requests\Api\V1\Releases;

use App\Enums\ContentBlockType;
use App\Http\Requests\Api\V1\StrictFormRequest;
use Illuminate\Validation\Rule;

class StoreContentBlockRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['position' => ['required', 'integer', 'min:1'], 'type' => ['required', Rule::enum(ContentBlockType::class)], 'payload' => ['required', 'array']];
    }
}
