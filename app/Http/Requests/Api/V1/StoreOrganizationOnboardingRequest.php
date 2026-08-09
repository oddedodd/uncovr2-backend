<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

final class StoreOrganizationOnboardingRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_superadmin === true;
    }

    public function rules(): array
    {
        return [
            'organization' => ['required', 'array:name,legal_name,description,website_url'],
            'organization.name' => ['required', 'string', 'min:2', 'max:150'],
            'organization.legal_name' => ['nullable', 'string', 'max:200'],
            'organization.description' => ['nullable', 'string', 'max:5000'],
            'organization.website_url' => ['nullable', 'url:http,https', 'max:2048'],
            'administrator' => ['required', 'array:email'],
            'administrator.email' => [
                'required', 'string', 'email:rfc', 'max:254',
                Rule::notIn([$this->user()?->email]),
            ],
            'confirmation' => ['required', 'accepted'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('administrator.email')) {
            $administrator = (array) $this->input('administrator');
            $administrator['email'] = strtolower(trim((string) ($administrator['email'] ?? '')));
            $this->merge(['administrator' => $administrator]);
        }
    }
}
