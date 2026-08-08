<?php

namespace App\Policies;

use App\Models\ArtistMembership;
use App\Models\User;

final class ArtistMembershipPolicy
{
    public function update(User $user, ArtistMembership $membership): bool
    {
        return $user->can('manageMembers', $membership->artist);
    }

    public function delete(User $user, ArtistMembership $membership): bool
    {
        return $user->can('manageMembers', $membership->artist);
    }
}
