<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:255'],
            'client_type' => ['required', 'string', Rule::in(['portal', 'mobile'])],
            'device' => ['required', 'array:name,platform,app_version'],
            'device.name' => ['required', 'string', 'min:1', 'max:100'],
            'device.platform' => ['nullable', 'string', 'max:50'],
            'device.app_version' => ['nullable', 'string', 'max:50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        $deviceName = $this->input('device.name');

        $this->merge([
            'email' => is_string($email) ? strtolower(trim($email)) : $email,
        ]);

        if (is_string($deviceName)) {
            $this->merge([
                'device' => array_merge((array) $this->input('device'), [
                    'name' => preg_replace('/\s+/u', ' ', trim($deviceName)),
                ]),
            ]);
        }
    }
}
