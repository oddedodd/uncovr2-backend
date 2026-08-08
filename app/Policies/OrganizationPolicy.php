<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Services\Authorization\ScopeAccess;

final class OrganizationPolicy
{
    public function __construct(private readonly ScopeAccess $access) {}

    public function view(User $user, Organization $organization): bool
    {
        return $this->access->canViewOrganization($user, $organization);
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->access->canManageOrganization($user, $organization);
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $this->access->canManageOrganization($user, $organization);
    }

    public function manageArtists(User $user, Organization $organization): bool
    {
        return $this->access->canManageOrganization($user, $organization);
    }

    public function suspend(User $user, Organization $organization): bool
    {
        return false;
    }
}
