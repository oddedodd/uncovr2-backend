<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ArtistRole;
use Illuminate\Validation\Rule;

final class StoreArtistMembershipRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:254', 'exists:users,email'],
            'role' => ['required', Rule::enum(ArtistRole::class)],
        ];
    }
}
