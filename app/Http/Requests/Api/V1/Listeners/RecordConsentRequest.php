<?php

namespace App\Http\Requests\Api\V1\Listeners;

use App\Http\Requests\Api\V1\StrictFormRequest;
use Illuminate\Validation\Rule;

final class RecordConsentRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'purpose' => ['required', Rule::in(['marketing_email', 'marketing_push', 'analytics'])],
            'granted' => ['required', 'boolean'],
        ];
    }
}
