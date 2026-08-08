<?php

namespace App\Policies;

use App\Models\OrganizationMembership;
use App\Models\User;

final class OrganizationMembershipPolicy
{
    public function update(User $user, OrganizationMembership $membership): bool
    {
        return $user->can('manageMembers', $membership->organization);
    }

    public function delete(User $user, OrganizationMembership $membership): bool
    {
        return $user->can('manageMembers', $membership->organization);
    }
}
