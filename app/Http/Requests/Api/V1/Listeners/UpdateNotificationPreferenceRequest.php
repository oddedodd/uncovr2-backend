<?php

namespace App\Http\Requests\Api\V1\Listeners;

use App\Http\Requests\Api\V1\StrictFormRequest;

final class UpdateNotificationPreferenceRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['email_enabled' => ['required', 'boolean'], 'push_enabled' => ['required', 'boolean'], 'in_app_enabled' => ['required', 'boolean']];
    }
}
