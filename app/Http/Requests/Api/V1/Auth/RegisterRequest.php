<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', Password::min(15), 'max:255', 'confirmed'],
            'consents' => ['required', 'array:terms,privacy,marketing_email,marketing_push'],
            'consents.terms' => ['required', 'accepted'],
            'consents.privacy' => ['required', 'accepted'],
            'consents.marketing_email' => ['sometimes', 'boolean'],
            'consents.marketing_push' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        $displayName = $this->input('display_name');

        $this->merge([
            'email' => is_string($email) ? strtolower(trim($email)) : $email,
            'display_name' => is_string($displayName)
                ? preg_replace('/\s+/u', ' ', trim($displayName))
                : $displayName,
        ]);
    }
}
