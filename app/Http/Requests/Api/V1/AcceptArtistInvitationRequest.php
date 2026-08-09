<?php

namespace App\Http\Requests\Api\V1;

final class AcceptArtistInvitationRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'size:64']];
    }
}
