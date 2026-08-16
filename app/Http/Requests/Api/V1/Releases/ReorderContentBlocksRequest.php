<?php

namespace App\Http\Requests\Api\V1\Releases;

use App\Http\Requests\Api\V1\StrictFormRequest;

final class ReorderContentBlocksRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['block_ids' => ['required', 'array', 'min:1'], 'block_ids.*' => ['required', 'ulid', 'distinct']];
    }
}
