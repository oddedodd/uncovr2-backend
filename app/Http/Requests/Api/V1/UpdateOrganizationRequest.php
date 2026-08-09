<?php

namespace App\Http\Requests\Api\V1;

final class UpdateOrganizationRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:150'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'website_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'logo_media_id' => ['sometimes', 'nullable', 'ulid'],
        ];
    }
}
