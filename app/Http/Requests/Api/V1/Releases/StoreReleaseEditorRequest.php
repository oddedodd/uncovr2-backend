<?php

namespace App\Http\Requests\Api\V1\Releases;

use App\Http\Requests\Api\V1\StrictFormRequest;

final class StoreReleaseEditorRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['user_id' => ['required', 'ulid', 'exists:users,public_id']];
    }
}
