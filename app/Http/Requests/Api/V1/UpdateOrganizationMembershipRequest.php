<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use Illuminate\Validation\Rule;

final class UpdateOrganizationMembershipRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'role' => ['sometimes', Rule::enum(OrganizationRole::class)],
            'status' => ['sometimes', Rule::enum(MembershipStatus::class)],
        ];
    }
}
