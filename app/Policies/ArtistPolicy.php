<?php

namespace App\Policies;

use App\Models\Artist;
use App\Models\User;
use App\Services\Authorization\ScopeAccess;

final class ArtistPolicy
{
    public function __construct(private readonly ScopeAccess $access) {}

    public function view(User $user, Artist $artist): bool
    {
        return $this->access->canViewArtist($user, $artist);
    }

    public function update(User $user, Artist $artist): bool
    {
        return $this->access->canEditArtist($user, $artist);
    }

    public function manageMembers(User $user, Artist $artist): bool
    {
        return $this->access->canManageArtist($user, $artist);
    }

    public function manageMedia(User $user, Artist $artist): bool
    {
        return $this->access->canManageArtist($user, $artist);
    }

    public function suspend(User $user, Artist $artist): bool
    {
        return false;
    }
}
