<?php

namespace App\Policies;

use App\Models\OrganizationArtistRelationship;
use App\Models\User;
use App\Services\Authorization\ScopeAccess;

final class OrganizationArtistRelationshipPolicy
{
    public function __construct(private readonly ScopeAccess $access) {}

    public function delete(User $user, OrganizationArtistRelationship $relationship): bool
    {
        return $this->access->canManageOrganization($user, $relationship->organization)
            || $this->access->canManageArtist($user, $relationship->artist);
    }
}
