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
            'filter' => ['sometimes', 'array:search,status,type,artist_id,owner_type,owner_id'],
            'filter.search' => ['sometimes', 'string', 'min:2', 'max:100'],
            'filter.status' => ['sometimes', 'string', Rule::enum(ReleaseStatus::class)],
            'filter.type' => ['sometimes', 'string', Rule::enum(ReleaseType::class)],
            'filter.artist_id' => ['sometimes', 'string', 'size:26', 'exists:artists,public_id'],
            'filter.owner_type' => ['required_with:filter.owner_id', 'string', Rule::in(['organization', 'artist'])],
            'filter.owner_id' => ['required_with:filter.owner_type', 'string', 'size:26'],
            ...$this->paginationRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
        $this->validateCursorDirection($validator);
        $validator->sometimes('filter.owner_id', ['exists:organizations,public_id'], fn (): bool => $this->input('filter.owner_type') === 'organization');
        $validator->sometimes('filter.owner_id', ['exists:artists,public_id'], fn (): bool => $this->input('filter.owner_type') === 'artist');
    }
}
