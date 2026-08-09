<?php

namespace App\Http\Requests\Api\V1\Listeners;

use App\Http\Requests\Api\V1\StrictFormRequest;

final class RequestAccountDeletionRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['password' => ['required', 'string'], 'confirmation' => ['required', 'in:DELETE']];
    }
}
