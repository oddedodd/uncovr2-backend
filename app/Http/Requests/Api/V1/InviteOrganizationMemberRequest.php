<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\OrganizationRole;
use Illuminate\Validation\Rule;

final class InviteOrganizationMemberRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ];
    }
}
