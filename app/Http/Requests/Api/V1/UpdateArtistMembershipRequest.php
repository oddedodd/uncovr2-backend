<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ArtistRole;
use App\Enums\MembershipStatus;
use Illuminate\Validation\Rule;

final class UpdateArtistMembershipRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'role' => ['sometimes', Rule::enum(ArtistRole::class)],
            'status' => ['sometimes', Rule::enum(MembershipStatus::class)],
        ];
    }
}
