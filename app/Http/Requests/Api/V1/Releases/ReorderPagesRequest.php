<?php

namespace App\Http\Requests\Api\V1\Releases;

use App\Http\Requests\Api\V1\StrictFormRequest;

final class ReorderPagesRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['page_ids' => ['required', 'array', 'min:1'], 'page_ids.*' => ['required', 'ulid', 'distinct']];
    }
}
