<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ReleaseStatus;
use App\Enums\ReleaseType;
use App\Http\Requests\Api\V1\Concerns\ValidatesCursorPagination;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ReleaseIndexRequest extends StrictFormRequest
{
    use ValidatesCursorPagination;

    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:search,status,type'],
            'filter.search' => ['sometimes', 'string', 'min:2', 'max:100'],
            'filter.status' => ['sometimes', 'string', Rule::enum(ReleaseStatus::class)],
            'filter.type' => ['sometimes', 'string', Rule::enum(ReleaseType::class)],
            ...$this->paginationRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
        $this->validateCursorDirection($validator);
    }
}
