<?php

namespace App\Services\Artists;

use App\Enums\MembershipStatus;
use App\Models\Artist;
use App\Models\ArtistInvitation;
use App\Models\ArtistMembership;
use App\Models\User;
use App\Notifications\Artists\ArtistInvitationNotification;
use App\Services\Auth\SecurityAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

final class ArtistInvitationService
{
    public function __construct(private readonly SecurityAuditLogger $auditLogger) {}

    public function create(Artist $artist, User $actor, Request $request, string $email, string $role): ArtistInvitation
    {
        $email = strtolower(trim($email));
        $plainToken = Str::random(64);

        return DB::transaction(function () use ($artist, $actor, $request, $email, $role, $plainToken): ArtistInvitation {
            $lockedArtist = Artist::query()->lockForUpdate()->findOrFail($artist->getKey());

            if ($lockedArtist->memberships()->whereHas('user', fn ($query) => $query->where('email', $email))->exists()) {
                throw new ConflictHttpException('User is already an artist member.');
            }

            $lockedArtist->invitations()
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $invitation = $lockedArtist->invitations()->create([
                'email' => $email,
                'role' => $role,
                'token_hash' => hash('sha256', $plainToken),
                'invited_by_user_id' => $actor->getKey(),
                'expires_at' => now()->addHours(config('artists.invitation_ttl_hours')),
                'last_sent_at' => now(),
                'send_count' => 1,
            ]);

            $this->auditLogger->record('artist.invitation_created', $actor, $request, metadata: [
                'artist_id' => $lockedArtist->public_id,
                'invitation_id' => $invitation->public_id,
            ]);
            $invitation->notify(new ArtistInvitationNotification($plainToken));

            return $invitation;
        });
    }

    public function resend(ArtistInvitation $invitation, User $actor, Request $request): ArtistInvitation
    {
        $plainToken = Str::random(64);

        return DB::transaction(function () use ($invitation, $actor, $request, $plainToken): ArtistInvitation {
            $locked = ArtistInvitation::query()->lockForUpdate()->findOrFail($invitation->getKey());

            if ($locked->accepted_at || $locked->revoked_at) {
                throw new GoneHttpException('Invitation is no longer active.');
            }

            $locked->update([
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => now()->addHours(config('artists.invitation_ttl_hours')),
                'last_sent_at' => now(),
                'send_count' => $locked->send_count + 1,
            ]);

            $this->auditLogger->record('artist.invitation_resent', $actor, $request, metadata: [
                'artist_id' => $locked->artist->public_id,
                'invitation_id' => $locked->public_id,
            ]);
            $locked->notify(new ArtistInvitationNotification($plainToken));

            return $locked;
        });
    }

    public function accept(string $plainToken, User $user, Request $request): ArtistMembership
    {
        return DB::transaction(function () use ($plainToken, $user, $request): ArtistMembership {
            $tokenHash = hash('sha256', $plainToken);
            $candidate = ArtistInvitation::query()->where('token_hash', $tokenHash)->first();

            if ($candidate === null) {
                throw new GoneHttpException('Invitation is invalid or has already been used.');
            }

            Artist::query()->lockForUpdate()->findOrFail($candidate->artist_id);
            $invitation = ArtistInvitation::query()->where('token_hash', $tokenHash)->lockForUpdate()->first();

            if ($invitation === null || $invitation->accepted_at || $invitation->revoked_at || $invitation->expires_at->isPast()) {
                throw new GoneHttpException('Invitation is invalid, expired, or has already been used.');
            }

            if ($user->email !== $invitation->email) {
                throw new ConflictHttpException('Invitation belongs to another email address.');
            }

            $membership = ArtistMembership::query()->firstOrCreate(
                ['artist_id' => $invitation->artist_id, 'user_id' => $user->getKey()],
                [
                    'role' => $invitation->role->value,
                    'status' => MembershipStatus::Active->value,
                    'invited_by_user_id' => $invitation->invited_by_user_id,
                    'joined_at' => now(),
                    'suspended_at' => null,
                ],
            );

            if (! $membership->wasRecentlyCreated) {
                throw new ConflictHttpException('User is already an artist member.');
            }

            $invitation->update(['accepted_at' => now(), 'accepted_by_user_id' => $user->getKey()]);
            $this->auditLogger->record('artist.invitation_accepted', $user, $request, metadata: [
                'artist_id' => $invitation->artist->public_id,
                'invitation_id' => $invitation->public_id,
            ]);

            return $membership;
        });
    }
}
