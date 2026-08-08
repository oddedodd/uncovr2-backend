<?php

namespace App\Services\Organizations;

use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Notifications\Organizations\OrganizationInvitationNotification;
use App\Services\Auth\SecurityAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

final class OrganizationInvitationService
{
    public function __construct(private readonly SecurityAuditLogger $auditLogger) {}

    public function create(Organization $organization, User $actor, Request $request, string $email, string $role): OrganizationInvitation
    {
        $email = strtolower(trim($email));
        $plainToken = Str::random(64);

        $invitation = DB::transaction(function () use ($organization, $actor, $request, $email, $role, $plainToken): OrganizationInvitation {
            Organization::query()->lockForUpdate()->findOrFail($organization->getKey());

            if ($organization->memberships()->whereHas('user', fn ($query) => $query->where('email', $email))->exists()) {
                throw new ConflictHttpException('User is already an organization member.');
            }

            $organization->invitations()
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $invitation = $organization->invitations()->create([
                'email' => $email,
                'role' => $role,
                'token_hash' => hash('sha256', $plainToken),
                'invited_by_user_id' => $actor->getKey(),
                'expires_at' => now()->addHours(config('organizations.invitation_ttl_hours')),
                'last_sent_at' => now(),
                'send_count' => 1,
            ]);

            $this->auditLogger->record('organization.invitation_created', $actor, $request, metadata: [
                'organization_id' => $organization->public_id,
                'invitation_id' => $invitation->public_id,
            ]);

            $invitation->notify(new OrganizationInvitationNotification($plainToken));

            return $invitation;
        });

        return $invitation;
    }

    public function resend(OrganizationInvitation $invitation, User $actor, Request $request): OrganizationInvitation
    {
        $plainToken = Str::random(64);

        return DB::transaction(function () use ($invitation, $actor, $request, $plainToken): OrganizationInvitation {
            $locked = OrganizationInvitation::query()->lockForUpdate()->findOrFail($invitation->getKey());

            if ($locked->accepted_at || $locked->revoked_at) {
                throw new GoneHttpException('Invitation is no longer active.');
            }

            $locked->update([
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => now()->addHours(config('organizations.invitation_ttl_hours')),
                'last_sent_at' => now(),
                'send_count' => $locked->send_count + 1,
            ]);

            $this->auditLogger->record('organization.invitation_resent', $actor, $request, metadata: [
                'organization_id' => $locked->organization->public_id,
                'invitation_id' => $locked->public_id,
            ]);
            $locked->notify(new OrganizationInvitationNotification($plainToken));

            return $locked;
        });
    }

    public function accept(string $plainToken, User $user, Request $request): OrganizationMembership
    {
        return DB::transaction(function () use ($plainToken, $user, $request): OrganizationMembership {
            $tokenHash = hash('sha256', $plainToken);
            $candidate = OrganizationInvitation::query()->where('token_hash', $tokenHash)->first();

            if ($candidate === null) {
                throw new GoneHttpException('Invitation is invalid or has already been used.');
            }

            Organization::query()->lockForUpdate()->findOrFail($candidate->organization_id);
            $invitation = OrganizationInvitation::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if ($invitation === null || $invitation->accepted_at || $invitation->revoked_at) {
                throw new GoneHttpException('Invitation is invalid or has already been used.');
            }

            if ($invitation->expires_at->isPast()) {
                $invitation->update(['revoked_at' => now()]);
                throw new GoneHttpException('Invitation has expired.');
            }

            if ($user->email !== $invitation->email) {
                throw new ConflictHttpException('Invitation belongs to another email address.');
            }

            $membership = OrganizationMembership::query()->firstOrCreate(
                ['organization_id' => $invitation->organization_id, 'user_id' => $user->getKey()],
                [
                    'role' => $invitation->role->value,
                    'status' => MembershipStatus::Active->value,
                    'invited_by_user_id' => $invitation->invited_by_user_id,
                    'joined_at' => now(),
                    'suspended_at' => null,
                ],
            );

            if (! $membership->wasRecentlyCreated) {
                throw new ConflictHttpException('User is already an organization member.');
            }

            $invitation->update(['accepted_at' => now(), 'accepted_by_user_id' => $user->getKey()]);
            $this->auditLogger->record('organization.invitation_accepted', $user, $request, metadata: [
                'organization_id' => $invitation->organization->public_id,
                'invitation_id' => $invitation->public_id,
            ]);

            return $membership;
        });
    }
}
