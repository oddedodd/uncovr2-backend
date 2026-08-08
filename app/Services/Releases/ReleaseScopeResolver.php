<?php

namespace App\Services\Releases;

use App\Models\Artist;
use App\Models\Organization;
use App\Models\Release;
use App\Models\User;
use App\Services\Authorization\ScopeAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class ReleaseScopeResolver
{
    public function __construct(private readonly ScopeAccess $access) {}

    public function resolveOwner(string $type, string $publicId, User $user): Organization|Artist
    {
        $owner = match ($type) {
            'organization' => Organization::query()->where('public_id', $publicId)->first(),
            'artist' => Artist::query()->where('public_id', $publicId)->first(),
            default => null,
        };
        if (! $owner) {
            throw ValidationException::withMessages(['owner_id' => ['The selected owner is invalid.']]);
        }

        $allowed = $user->is_superadmin || ($owner instanceof Organization
            ? $this->access->canViewOrganization($user, $owner)
            : $this->access->canViewArtist($user, $owner));
        if (! $allowed) {
            throw new AuthorizationException;
        }

        return $owner;
    }

    public function assertSameOwner(Release $release, Model $resource, string $field): void
    {
        $matches = $release->organization_id
            ? $resource->getAttribute('organization_id') === $release->organization_id
            : $resource->getAttribute('artist_id') === $release->artist_id;
        if (! $matches) {
            throw ValidationException::withMessages([$field => ['The resource must belong to the same owner scope as the release.']]);
        }
    }
}
