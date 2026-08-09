<?php

namespace App\Http\Requests\Api\V1\Listeners;

use App\Http\Requests\Api\V1\StrictFormRequest;

final class UpdateCollectionRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['name' => ['sometimes', 'string', 'min:1', 'max:100'], 'description' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }
}
