<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class RefreshTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $prefix = preg_quote(config('authentication.refresh_token_prefix'), '/');
        $encodedLength = (int) ceil(config('authentication.refresh_token_bytes') * 8 / 6);

        return [
            'refresh_token' => [
                'required',
                'string',
                "regex:/^{$prefix}[A-Za-z0-9_-]{{$encodedLength}}$/",
            ],
        ];
    }
}
