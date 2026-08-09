<?php

namespace App\Services\Onboarding;

use App\Enums\ArtistRole;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\Artist;
use App\Models\ArtistInvitation;
use App\Models\ArtistMembership;
use App\Models\Organization;
use App\Models\OrganizationArtistRelationship;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Artists\ArtistInvitationService;
use App\Services\Auth\SecurityAuditLogger;
use App\Services\Organizations\OrganizationInvitationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class OnboardingService
{
    public function __construct(
        private readonly OrganizationInvitationService $organizationInvitations,
        private readonly ArtistInvitationService $artistInvitations,
        private readonly SecurityAuditLogger $auditLogger,
    ) {}

    /** @return array{organization: Organization, invitation: OrganizationInvitation} */
    public function organization(User $actor, Request $request, array $profile, string $administratorEmail): array
    {
        return DB::transaction(function () use ($actor, $request, $profile, $administratorEmail): array {
            $organization = Organization::query()->create(['created_by_user_id' => $actor->getKey()]);
            $organization->profile()->create($profile);
            $invitation = $this->organizationInvitations->create(
                $organization,
                $actor,
                $request,
                $administratorEmail,
                OrganizationRole::Admin->value,
            );

            $this->auditLogger->record('platform.organization_onboarded', $actor, $request, metadata: [
                'organization_id' => $organization->public_id,
                'invitation_id' => $invitation->public_id,
            ]);

            return ['organization' => $organization->load('profile'), 'invitation' => $invitation];
        });
    }

    /**
     * @return array{
     *   artist: Artist,
     *   relationship: OrganizationArtistRelationship,
     *   invitation: ArtistInvitation,
     *   creator_membership: ArtistMembership|null
     * }
     */
    public function artist(
        Organization $organization,
        User $actor,
        Request $request,
        array $profile,
        string $administratorEmail,
        string $relationshipType,
        ?string $creatorRole,
    ): array {
        return DB::transaction(function () use ($organization, $actor, $request, $profile, $administratorEmail, $relationshipType, $creatorRole): array {
            $lockedOrganization = Organization::query()->lockForUpdate()->findOrFail($organization->getKey());

            if ($lockedOrganization->status !== 'active') {
                throw new ConflictHttpException('Organization must be active to onboard an artist.');
            }

            $artist = Artist::query()->create(['created_by_user_id' => $actor->getKey()]);
            $artist->profile()->create($profile);
            $relationship = $lockedOrganization->artistRelationships()->create([
                'artist_id' => $artist->getKey(),
                'relationship_type' => $relationshipType,
                'created_by_user_id' => $actor->getKey(),
                'started_at' => now(),
            ]);

            $creatorMembership = null;
            if ($creatorRole !== null) {
                $creatorMembership = $artist->memberships()->create([
                    'user_id' => $actor->getKey(),
                    'role' => ArtistRole::from($creatorRole)->value,
                    'status' => MembershipStatus::Active->value,
                    'joined_at' => now(),
                ]);
            }

            $invitation = $this->artistInvitations->create(
                $artist,
                $actor,
                $request,
                $administratorEmail,
                ArtistRole::Admin->value,
            );

            $this->auditLogger->record('organization.artist_onboarded', $actor, $request, metadata: [
                'organization_id' => $lockedOrganization->public_id,
                'artist_id' => $artist->public_id,
                'relationship_id' => $relationship->public_id,
                'invitation_id' => $invitation->public_id,
                'creator_role' => $creatorRole,
            ]);

            return [
                'artist' => $artist->load('profile'),
                'relationship' => $relationship,
                'invitation' => $invitation,
                'creator_membership' => $creatorMembership,
            ];
        });
    }
}
