<?php

namespace App\Http\Requests\Api\V1\Releases;

use App\Http\Requests\Api\V1\StrictFormRequest;

final class UpdateContributorRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['display_name' => ['sometimes', 'string', 'min:1', 'max:200'], 'legal_name' => ['sometimes', 'nullable', 'string', 'max:200'], 'email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'], 'website_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'], 'owner_type' => ['prohibited'], 'owner_id' => ['prohibited']];
    }
}
