<?php

namespace App\Http\Requests\Api\V1\Listeners;

use App\Http\Requests\Api\V1\StrictFormRequest;
use Illuminate\Validation\Validator;

final class ListenerIndexRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'array:size,after,before'],
            'page.size' => ['sometimes', 'integer', 'between:1,100'],
            'page.after' => ['sometimes', 'string'],
            'page.before' => ['sometimes', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
        $validator->after(function (Validator $validator): void {
            if ($this->has('page.after') && $this->has('page.before')) {
                $validator->errors()->add('page', 'The after and before cursors are mutually exclusive.');
            }
        });
    }
}
