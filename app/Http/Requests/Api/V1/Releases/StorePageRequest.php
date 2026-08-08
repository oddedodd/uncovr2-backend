<?php

namespace App\Http\Requests\Api\V1\Releases;

use App\Http\Requests\Api\V1\StrictFormRequest;

class StorePageRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['position' => ['required', 'integer', 'min:1'], 'title' => ['nullable', 'string', 'max:200']];
    }
}
