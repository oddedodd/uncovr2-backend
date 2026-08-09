<?php

namespace App\Http\Requests\Api\V1\Listeners;

use App\Http\Requests\Api\V1\StrictFormRequest;

final class StoreCollectionRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'min:1', 'max:100'], 'description' => ['nullable', 'string', 'max:2000']];
    }
}
