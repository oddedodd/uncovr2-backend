<?php

namespace App\Http\Requests\Api\V1\Listeners;

use App\Http\Requests\Api\V1\StrictFormRequest;
use Illuminate\Validation\Rule;

final class UpsertPushDeviceRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['platform' => ['required', Rule::in(['ios', 'android'])], 'push_token' => ['required', 'string', 'min:20', 'max:4096']];
    }
}
