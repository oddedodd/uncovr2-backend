<?php

namespace App\Http\Requests\Api\V1\Listeners;

use App\Http\Requests\Api\V1\StrictFormRequest;
use Illuminate\Validation\Rule;

final class ReplaceCollectionItemsRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'max:500'],
            'items.*' => ['required', 'array:type,id'],
            'items.*.type' => ['required', Rule::in(['release', 'track'])],
            'items.*.id' => ['required', 'string', 'size:26', 'distinct'],
        ];
    }
}
