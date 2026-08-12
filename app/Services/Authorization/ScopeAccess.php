<?php

namespace App\Services\Authorization;

use App\Enums\ArtistRole;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\Artist;
use App\Models\Organization;
use App\Models\User;

final class ScopeAccess
{
    /** @var array<string, bool> */
    private array $cache = [];

    /**
     * Authorization decisions are memoized for the lifetime of a single request
     * only. The container binding is scoped, but scoped instances are not flushed
     * between requests outside the queue worker, so the request pipeline resets
     * this explicitly.
     */
    public function flush(): void
    {
        $this->cache = [];
    }

    public function canViewOrganization(User $user, Organization $organization): bool
    {
        return $this->hasOrganizationRole($user, $organization);
    }

    public function canManageOrganization(User $user, Organization $organization): bool
    {
        return $this->hasOrganizationRole($user, $organization, OrganizationRole::Admin);
    }

    public function canViewArtist(User $user, Artist $artist): bool
    {
        return $this->hasArtistRole($user, $artist)
            || $this->hasRelatedOrganizationRole($user, $artist);
    }

    /**
     * Existence check across many artists at once. Equivalent to calling
     * canViewArtist() per artist, but as a single query instead of three per artist.
     *
     * @param  array<int, int|string>  $artistKeys
     */
    public function canViewAnyArtist(User $user, array $artistKeys): bool
    {
        $artistKeys = array_values(array_unique($artistKeys));

        if ($artistKeys === []) {
            return false;
        }

        sort($artistKeys);
        $key = implode(':', ['artist-any', $user->getKey(), implode(',', $artistKeys)]);

        return $this->cache[$key] ??= Artist::query()
            ->whereKey($artistKeys)
            ->where('status', 'active')
            ->where(fn ($query) => $query
                ->whereHas('memberships', fn ($memberships) => $memberships
                    ->where('user_id', $user->getKey())
                    ->where('status', MembershipStatus::Active->value))
                ->orWhereHas('organizationRelationships', fn ($relationships) => $relationships
                    ->whereNull('ended_at')
                    ->whereHas('organization', fn ($organization) => $organization
                        ->where('status', 'active')
                        ->whereHas('memberships', fn ($memberships) => $memberships
                            ->where('user_id', $user->getKey())
                            ->where('status', MembershipStatus::Active->value)))))
            ->exists();
    }

    public function canEditArtist(User $user, Artist $artist): bool
    {
        return $this->hasArtistRole($user, $artist)
            || $this->hasRelatedOrganizationRole($user, $artist, OrganizationRole::Admin);
    }

    public function canManageArtist(User $user, Artist $artist): bool
    {
        return $this->hasArtistRole($user, $artist, ArtistRole::Admin)
            || $this->hasRelatedOrganizationRole($user, $artist, OrganizationRole::Admin);
    }

    private function hasOrganizationRole(
        User $user,
        Organization $organization,
        ?OrganizationRole $role = null,
    ): bool {
        $key = implode(':', ['organization', $user->getKey(), $organization->getKey(), $role?->value ?? '*']);

        return $this->cache[$key] ??= $organization->status === 'active'
            && $organization->memberships()
                ->where('user_id', $user->getKey())
                ->where('status', MembershipStatus::Active->value)
                ->when($role, fn ($query) => $query->where('role', $role->value))
                ->exists();
    }

    private function hasArtistRole(User $user, Artist $artist, ?ArtistRole $role = null): bool
    {
        $key = implode(':', ['artist', $user->getKey(), $artist->getKey(), $role?->value ?? '*']);

        return $this->cache[$key] ??= $artist->status === 'active'
            && $artist->memberships()
                ->where('user_id', $user->getKey())
                ->where('status', MembershipStatus::Active->value)
                ->when($role, fn ($query) => $query->where('role', $role->value))
                ->exists();
    }

    private function hasRelatedOrganizationRole(
        User $user,
        Artist $artist,
        ?OrganizationRole $role = null,
    ): bool {
        $key = implode(':', ['artist-related-organization', $user->getKey(), $artist->getKey(), $role?->value ?? '*']);

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        if ($artist->status !== 'active') {
            return $this->cache[$key] = false;
        }

        return $this->cache[$key] = $artist->organizationRelationships()
            ->whereNull('ended_at')
            ->whereHas('organization', fn ($query) => $query
                ->where('status', 'active')
                ->whereHas('memberships', fn ($memberships) => $memberships
                    ->where('user_id', $user->getKey())
                    ->where('status', MembershipStatus::Active->value)
                    ->when($role, fn ($roleQuery) => $roleQuery->where('role', $role->value))))
            ->exists();
    }
}
