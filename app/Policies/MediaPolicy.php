<?php

namespace App\Policies;

use App\Models\Media;
use App\Models\User;
use App\Services\Authorization\ScopeAccess;

final class MediaPolicy
{
    public function __construct(private readonly ScopeAccess $access) {}

    public function view(User $user, Media $media): bool
    {
        return $media->organization_id ? $this->access->canViewOrganization($user, $media->organization) : $this->access->canViewArtist($user, $media->artist);
    }

    public function update(User $user, Media $media): bool
    {
        return $this->view($user, $media) && ($media->created_by_user_id === $user->getKey() || ($media->organization_id ? $this->access->canManageOrganization($user, $media->organization) : $this->access->canManageArtist($user, $media->artist)));
    }

    public function delete(User $user, Media $media): bool
    {
        return $this->update($user, $media);
    }
}
