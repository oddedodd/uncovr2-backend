<?php

namespace App\Policies;

use App\Models\Contributor;
use App\Models\User;
use App\Services\Authorization\ScopeAccess;

final class ContributorPolicy
{
    public function __construct(private readonly ScopeAccess $access) {}

    public function view(User $user, Contributor $contributor): bool
    {
        return $contributor->organization_id ? $this->access->canViewOrganization($user, $contributor->organization) : $this->access->canViewArtist($user, $contributor->artist);
    }

    public function update(User $user, Contributor $contributor): bool
    {
        return $this->view($user, $contributor) && ($contributor->created_by_user_id === $user->getKey() || ($contributor->organization_id ? $this->access->canManageOrganization($user, $contributor->organization) : $this->access->canManageArtist($user, $contributor->artist)));
    }

    public function delete(User $user, Contributor $contributor): bool
    {
        return $this->update($user, $contributor) && ! $contributor->credits()->exists();
    }
}
