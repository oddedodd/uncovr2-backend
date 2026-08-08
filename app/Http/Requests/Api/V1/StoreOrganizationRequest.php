<?php

namespace App\Http\Requests\Api\V1;

final class StoreOrganizationRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'website_url' => ['nullable', 'url:http,https', 'max:2048'],
        ];
    }
}
