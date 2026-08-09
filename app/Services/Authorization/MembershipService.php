<?php

namespace App\Services\Authorization;

use App\Enums\ArtistRole;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\ArtistMembership;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Auth\SecurityAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class MembershipService
{
    public function __construct(private readonly SecurityAuditLogger $auditLogger) {}

    /** @param array<string, scalar|null> $auditMetadata */
    public function updateOrganization(OrganizationMembership $membership, array $changes, User $actor, Request $request, array $auditMetadata = []): OrganizationMembership
    {
        return DB::transaction(function () use ($membership, $changes, $actor, $request, $auditMetadata): OrganizationMembership {
            $locked = OrganizationMembership::query()->lockForUpdate()->findOrFail($membership->getKey());
            $this->guardOrganizationAdmin($locked, $changes);
            $event = isset($changes['status']) ? 'organization.membership_status_changed' : 'organization.membership_role_changed';
            $previousRole = $locked->role->value;
            $previousStatus = $locked->status->value;
            if (isset($changes['status'])) {
                $changes['suspended_at'] = $changes['status'] === MembershipStatus::Suspended->value ? now() : null;
            }
            $locked->update($changes);
            $this->auditLogger->record($event, $actor, $request, metadata: [
                ...$auditMetadata,
                'organization_id' => $locked->organization->public_id,
                'membership_id' => $locked->public_id,
                'previous_role' => $previousRole,
                'new_role' => $locked->role->value,
                'previous_status' => $previousStatus,
                'new_status' => $locked->status->value,
            ]);

            return $locked;
        });
    }

    public function removeOrganization(OrganizationMembership $membership, User $actor, Request $request): void
    {
        DB::transaction(function () use ($membership, $actor, $request): void {
            $locked = OrganizationMembership::query()->lockForUpdate()->findOrFail($membership->getKey());
            $this->guardOrganizationAdmin($locked, ['status' => MembershipStatus::Suspended->value]);
            $organizationId = $locked->organization->public_id;
            $membershipId = $locked->public_id;
            $locked->delete();
            $this->auditLogger->record('organization.membership_removed', $actor, $request, metadata: [
                'organization_id' => $organizationId,
                'membership_id' => $membershipId,
            ]);
        });
    }

    /** @param array<string, scalar|null> $auditMetadata */
    public function updateArtist(ArtistMembership $membership, array $changes, User $actor, Request $request, array $auditMetadata = []): ArtistMembership
    {
        return DB::transaction(function () use ($membership, $changes, $actor, $request, $auditMetadata): ArtistMembership {
            $locked = ArtistMembership::query()->lockForUpdate()->findOrFail($membership->getKey());
            $this->guardArtistAdmin($locked, $changes);
            $event = isset($changes['status']) ? 'artist.membership_status_changed' : 'artist.membership_role_changed';
            $previousRole = $locked->role->value;
            $previousStatus = $locked->status->value;
            if (isset($changes['status'])) {
                $changes['suspended_at'] = $changes['status'] === MembershipStatus::Suspended->value ? now() : null;
            }
            $locked->update($changes);
            $this->auditLogger->record($event, $actor, $request, metadata: [
                ...$auditMetadata,
                'artist_id' => $locked->artist->public_id,
                'membership_id' => $locked->public_id,
                'previous_role' => $previousRole,
                'new_role' => $locked->role->value,
                'previous_status' => $previousStatus,
                'new_status' => $locked->status->value,
            ]);

            return $locked;
        });
    }

    public function removeArtist(ArtistMembership $membership, User $actor, Request $request): void
    {
        DB::transaction(function () use ($membership, $actor, $request): void {
            $locked = ArtistMembership::query()->lockForUpdate()->findOrFail($membership->getKey());
            $this->guardArtistAdmin($locked, ['status' => MembershipStatus::Suspended->value]);
            $artistId = $locked->artist->public_id;
            $membershipId = $locked->public_id;
            $locked->delete();
            $this->auditLogger->record('artist.membership_removed', $actor, $request, metadata: [
                'artist_id' => $artistId,
                'membership_id' => $membershipId,
            ]);
        });
    }

    private function guardOrganizationAdmin(OrganizationMembership $membership, array $changes): void
    {
        if ($membership->role !== OrganizationRole::Admin || $membership->status !== MembershipStatus::Active) {
            return;
        }
        if (($changes['role'] ?? $membership->role->value) === OrganizationRole::Admin->value
            && ($changes['status'] ?? $membership->status->value) === MembershipStatus::Active->value) {
            return;
        }
        $otherAdmins = OrganizationMembership::query()
            ->where('organization_id', $membership->organization_id)
            ->whereKeyNot($membership->getKey())
            ->where('role', OrganizationRole::Admin->value)
            ->where('status', MembershipStatus::Active->value)
            ->lockForUpdate()
            ->exists();
        if (! $otherAdmins) {
            throw new ConflictHttpException('The last active organization administrator cannot be removed.');
        }
    }

    private function guardArtistAdmin(ArtistMembership $membership, array $changes): void
    {
        if ($membership->role !== ArtistRole::Admin || $membership->status !== MembershipStatus::Active) {
            return;
        }
        if (($changes['role'] ?? $membership->role->value) === ArtistRole::Admin->value
            && ($changes['status'] ?? $membership->status->value) === MembershipStatus::Active->value) {
            return;
        }
        $otherAdmins = ArtistMembership::query()
            ->where('artist_id', $membership->artist_id)
            ->whereKeyNot($membership->getKey())
            ->where('role', ArtistRole::Admin->value)
            ->where('status', MembershipStatus::Active->value)
            ->lockForUpdate()
            ->exists();
        if (! $otherAdmins) {
            throw new ConflictHttpException('The last active artist administrator cannot be removed.');
        }
    }
}
