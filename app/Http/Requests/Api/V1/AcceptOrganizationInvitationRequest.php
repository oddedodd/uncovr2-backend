<?php

namespace App\Http\Requests\Api\V1;

final class AcceptOrganizationInvitationRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'size:64']];
    }
}
