<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ArtistRole;
use Illuminate\Validation\Rule;

final class InviteArtistMemberRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'role' => ['required', Rule::enum(ArtistRole::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }
}
