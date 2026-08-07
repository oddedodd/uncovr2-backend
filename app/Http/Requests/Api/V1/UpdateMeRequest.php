<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateMeRequest extends FormRequest
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
            'email' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $displayName = $this->input('display_name');

        if (is_string($displayName)) {
            $this->merge([
                'display_name' => preg_replace('/\s+/u', ' ', trim($displayName)),
            ]);
        }
    }
}
