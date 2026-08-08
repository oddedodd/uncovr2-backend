<?php

namespace App\Http\Requests\Api\V1\Releases;

final class UpdatePageRequest extends StorePageRequest
{
    public function rules(): array
    {
        return ['position' => ['sometimes', 'integer', 'min:1'], 'title' => ['sometimes', 'nullable', 'string', 'max:200']];
    }
}
