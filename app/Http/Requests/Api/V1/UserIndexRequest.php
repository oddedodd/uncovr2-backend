<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserStatus;
use App\Http\Requests\Api\V1\Concerns\ValidatesCursorPagination;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UserIndexRequest extends StrictFormRequest
{
    use ValidatesCursorPagination;

    public function authorize(): bool
    {
        return $this->user()?->is_superadmin === true;
    }

    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:search,status'],
            'filter.search' => ['sometimes', 'string', 'min:2', 'max:100'],
            'filter.status' => ['sometimes', 'string', Rule::enum(UserStatus::class)],
            ...$this->paginationRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
        $this->validateCursorDirection($validator);
    }
}
