<?php

namespace App\Http\Requests\Api\V1\Releases;

use App\Http\Requests\Api\V1\StrictFormRequest;

final class ScheduleReleaseRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['publish_at' => ['required', 'date', 'after:now']];
    }
}
