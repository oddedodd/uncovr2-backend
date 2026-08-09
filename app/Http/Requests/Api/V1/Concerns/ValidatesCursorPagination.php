<?php

namespace App\Http\Requests\Api\V1\Concerns;

use Illuminate\Validation\Validator;

trait ValidatesCursorPagination
{
    /** @return array<string, array<int, string>> */
    protected function paginationRules(): array
    {
        return [
            'page' => ['sometimes', 'array:size,after,before'],
            'page.size' => ['sometimes', 'integer', 'between:1,100'],
            'page.after' => ['sometimes', 'string'],
            'page.before' => ['sometimes', 'string'],
        ];
    }

    protected function validateCursorDirection(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->has('page.after') && $this->has('page.before')) {
                $validator->errors()->add('page', 'The after and before cursors are mutually exclusive.');
            }
        });
    }
}
