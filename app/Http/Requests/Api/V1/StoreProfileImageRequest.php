<?php

namespace App\Http\Requests\Api\V1;

class StoreProfileImageRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'image' => ['required', 'file'],
        ];
    }
}
