<?php

namespace App\Http\Requests\Api\V1\Releases;

use App\Http\Requests\Api\V1\StrictFormRequest;

final class ReleaseDecisionRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:2000']];
    }
}
