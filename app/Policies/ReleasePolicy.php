<?php

namespace App\Policies;

use App\Models\Release;
use App\Models\User;
use App\Services\Authorization\ScopeAccess;

final class ReleasePolicy
{
    public function __construct(private readonly ScopeAccess $access) {}

    public function view(User $user, Release $release): bool
    {
        return $this->canViewOwner($user, $release);
    }

    public function update(User $user, Release $release): bool
    {
        if (! $this->canViewOwner($user, $release)) {
            return false;
        }

        return $this->canManageOwner($user, $release)
            || $release->editorAssignments()->where('user_id', $user->getKey())->exists();
    }

    public function delete(User $user, Release $release): bool
    {
        return $this->update($user, $release) && $release->status === 'draft';
    }

    public function manageEditors(User $user, Release $release): bool
    {
        return $this->canManageOwner($user, $release);
    }

    private function canViewOwner(User $user, Release $release): bool
    {
        return $release->organization_id
            ? $this->access->canViewOrganization($user, $release->organization)
            : $this->access->canViewArtist($user, $release->ownerArtist);
    }

    private function canManageOwner(User $user, Release $release): bool
    {
        return $release->organization_id
            ? $this->access->canManageOrganization($user, $release->organization)
            : $this->access->canManageArtist($user, $release->ownerArtist);
    }
}
