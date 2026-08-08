<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

final class UpdateScopeStatusRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['status' => ['required', Rule::in(['active', 'suspended'])]];
    }
}
