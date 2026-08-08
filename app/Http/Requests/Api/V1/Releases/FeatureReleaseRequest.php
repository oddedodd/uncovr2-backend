<?php

namespace App\Http\Requests\Api\V1\Releases;

use App\Http\Requests\Api\V1\StrictFormRequest;

final class FeatureReleaseRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['featured' => ['required', 'boolean']];
    }
}
