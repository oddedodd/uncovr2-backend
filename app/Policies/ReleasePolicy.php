<?php

namespace App\Policies;

use App\Enums\ReleaseStatus;
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
        if (! in_array($release->status, [ReleaseStatus::Draft->value, ReleaseStatus::Unpublished->value], true)) {
            return false;
        }
        if (! $this->canViewOwner($user, $release)) {
            return false;
        }

        return $this->canManageOwner($user, $release)
            || $release->editorAssignments()->where('user_id', $user->getKey())->exists();
    }

    public function delete(User $user, Release $release): bool
    {
        return $this->update($user, $release) && $release->status === ReleaseStatus::Draft->value;
    }

    public function submit(User $user, Release $release): bool
    {
        return $this->update($user, $release);
    }

    public function approve(User $user, Release $release): bool
    {
        return $this->canManageOwner($user, $release);
    }

    public function publish(User $user, Release $release): bool
    {
        return $this->canManageOwner($user, $release);
    }

    public function unpublish(User $user, Release $release): bool
    {
        return $this->canManageOwner($user, $release);
    }

    public function archive(User $user, Release $release): bool
    {
        return $this->canManageOwner($user, $release);
    }

    public function manageEditors(User $user, Release $release): bool
    {
        return $this->canManageOwner($user, $release);
    }

    public function feature(User $user, Release $release): bool
    {
        return $user->is_superadmin;
    }

    private function canViewOwner(User $user, Release $release): bool
    {
        $canViewOwner = $release->organization_id
            ? $this->access->canViewOrganization($user, $release->organization)
            : $this->access->canViewArtist($user, $release->ownerArtist);

        return $canViewOwner || $release->artistLinks->contains(
            fn ($link): bool => $this->access->canViewArtist($user, $link->artist),
        );
    }

    private function canManageOwner(User $user, Release $release): bool
    {
        return $release->organization_id
            ? $this->access->canManageOrganization($user, $release->organization)
            : $this->access->canManageArtist($user, $release->ownerArtist);
    }
}
