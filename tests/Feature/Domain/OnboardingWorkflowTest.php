<?php

namespace Tests\Feature\Domain;

use App\Models\Artist;
use App\Models\ArtistInvitation;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\Artists\ArtistInvitationNotification;
use App\Notifications\Organizations\OrganizationInvitationNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class OnboardingWorkflowTest extends TestCase
{
    public function test_superadmin_atomically_onboards_label_and_first_admin_without_membership_for_self(): void
    {
        Notification::fake();
        $superadmin = $this->user('superadmin@example.com', true);
        Sanctum::actingAs($superadmin, ['portal:access']);

        $response = $this->postApi('/platform/organization-onboardings', [
            'organization' => [
                'name' => 'Northern Sounds',
                'legal_name' => 'Northern Sounds AS',
                'description' => 'Independent label.',
                'website_url' => 'https://northern.example',
            ],
            'administrator' => ['email' => 'LABEL-ADMIN@EXAMPLE.COM'],
            'confirmation' => true,
        ])->assertCreated()
            ->assertJsonPath('data.organization.profile.name', 'Northern Sounds')
            ->assertJsonPath('data.administrator_invitation.email', 'label-admin@example.com')
            ->assertJsonPath('data.administrator_invitation.role', 'label_admin');

        $organization = Organization::query()->sole();
        $invitation = OrganizationInvitation::query()->sole();
        $this->assertSame($organization->public_id, $response->json('data.organization.id'));
        $this->assertDatabaseCount('organization_memberships', 0);
        $this->assertDatabaseHas('security_audit_events', ['event_type' => 'platform.organization_onboarded']);
        Notification::assertSentTo($invitation, OrganizationInvitationNotification::class);
    }

    public function test_only_superadmin_can_start_label_onboarding(): void
    {
        $user = $this->user('regular@example.com');
        Sanctum::actingAs($user, ['portal:access']);

        $this->postApi('/platform/organization-onboardings', [
            'organization' => ['name' => 'Forbidden Label'],
            'administrator' => ['email' => 'admin@example.com'],
            'confirmation' => true,
        ])->assertForbidden();

        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('organization_invitations', 0);
    }

    public function test_onboarding_rejects_unexpected_nested_fields(): void
    {
        $superadmin = $this->user('superadmin@example.com', true);
        Sanctum::actingAs($superadmin, ['portal:access']);

        $this->postApi('/platform/organization-onboardings', [
            'organization' => ['name' => 'Strict Label', 'unexpected' => 'value'],
            'administrator' => ['email' => 'admin@example.com'],
            'confirmation' => true,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_label_onboarding_rolls_back_profile_when_invitation_creation_fails(): void
    {
        Notification::fake();
        OrganizationInvitation::creating(fn () => throw new RuntimeException('Simulated invitation failure.'));
        $superadmin = $this->user('superadmin@example.com', true);
        Sanctum::actingAs($superadmin, ['portal:access']);

        $this->postApi('/platform/organization-onboardings', [
            'organization' => ['name' => 'Rollback Label'],
            'administrator' => ['email' => 'label-admin@example.com'],
            'confirmation' => true,
        ])->assertInternalServerError();

        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('organization_profiles', 0);
        $this->assertDatabaseCount('organization_invitations', 0);
        Notification::assertNothingSent();
    }

    public function test_label_admin_onboards_linked_artist_and_first_artist_admin_without_automatic_artist_role(): void
    {
        Notification::fake();
        [$labelAdmin, $organization] = $this->label();
        Sanctum::actingAs($labelAdmin, ['portal:access']);

        $response = $this->postApi("/organizations/{$organization->public_id}/artist-onboardings", [
            'artist' => [
                'name' => 'Midnight Echo',
                'biography' => 'Electronic duo.',
                'website_url' => 'https://midnight.example',
            ],
            'administrator' => ['email' => 'ARTIST-ADMIN@EXAMPLE.COM'],
            'relationship_type' => 'managing_label',
            'confirmation' => true,
        ])->assertCreated()
            ->assertJsonPath('data.artist.profile.name', 'Midnight Echo')
            ->assertJsonPath('data.relationship.organization_id', $organization->public_id)
            ->assertJsonPath('data.administrator_invitation.role', 'artist_admin')
            ->assertJsonPath('data.creator_membership', null);

        $artist = Artist::query()->sole();
        $invitation = ArtistInvitation::query()->sole();
        $this->assertSame($artist->public_id, $response->json('data.artist.id'));
        $this->assertDatabaseHas('organization_artist_relationships', [
            'organization_id' => $organization->id,
            'artist_id' => $artist->id,
            'relationship_type' => 'managing_label',
            'ended_at' => null,
        ]);
        $this->assertDatabaseCount('artist_memberships', 0);
        $this->assertDatabaseHas('security_audit_events', ['event_type' => 'organization.artist_onboarded']);
        Notification::assertSentTo($invitation, ArtistInvitationNotification::class, function ($notification, $channels, $notifiable): bool {
            $mail = $notification->toMail($notifiable);

            return $notifiable->email === 'artist-admin@example.com'
                && str_contains($mail->viewData['acceptUrl'], '/artist-invitations/accept?token=')
                && $channels === ['mail'];
        });
    }

    public function test_creator_artist_role_is_only_added_when_explicitly_selected(): void
    {
        Notification::fake();
        [$labelAdmin, $organization] = $this->label();
        Sanctum::actingAs($labelAdmin, ['portal:access']);

        $this->postApi("/organizations/{$organization->public_id}/artist-onboardings", [
            'artist' => ['name' => 'Explicit Role'],
            'administrator' => ['email' => 'artist-admin@example.com'],
            'creator_role' => 'artist_user',
            'confirmation' => true,
        ])->assertCreated()
            ->assertJsonPath('data.creator_membership.role', 'artist_user');

        $this->assertDatabaseHas('artist_memberships', [
            'user_id' => $labelAdmin->id,
            'role' => 'artist_user',
            'status' => 'active',
        ]);
    }

    public function test_user_without_label_admin_scope_cannot_onboard_an_artist(): void
    {
        Notification::fake();
        [$labelAdmin, $organization] = $this->label();
        $labelUser = $this->user('label-user@example.com');
        $organization->memberships()->create([
            'user_id' => $labelUser->id,
            'role' => 'label_user',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        Sanctum::actingAs($labelUser, ['portal:access']);

        $this->postApi("/organizations/{$organization->public_id}/artist-onboardings", [
            'artist' => ['name' => 'Forbidden Artist'],
            'administrator' => ['email' => 'artist-admin@example.com'],
            'confirmation' => true,
        ])->assertForbidden();

        $this->assertDatabaseCount('artists', 0);
        $this->assertDatabaseCount('artist_invitations', 0);
        Notification::assertNothingSent();
    }

    public function test_artist_onboarding_rolls_back_artist_profile_and_relationship_when_invitation_creation_fails(): void
    {
        Notification::fake();
        ArtistInvitation::creating(fn () => throw new RuntimeException('Simulated invitation failure.'));
        [$labelAdmin, $organization] = $this->label();
        Sanctum::actingAs($labelAdmin, ['portal:access']);

        $this->postApi("/organizations/{$organization->public_id}/artist-onboardings", [
            'artist' => ['name' => 'Rollback Artist'],
            'administrator' => ['email' => 'artist-admin@example.com'],
            'creator_role' => 'artist_user',
            'confirmation' => true,
        ])->assertInternalServerError();

        $this->assertDatabaseCount('artists', 0);
        $this->assertDatabaseCount('artist_profiles', 0);
        $this->assertDatabaseCount('organization_artist_relationships', 0);
        $this->assertDatabaseCount('artist_memberships', 0);
        $this->assertDatabaseCount('artist_invitations', 0);
        Notification::assertNothingSent();
    }

    public function test_artist_invitation_is_email_bound_single_use_and_grants_artist_admin_scope(): void
    {
        Notification::fake();
        [$labelAdmin, $organization] = $this->label();
        Sanctum::actingAs($labelAdmin, ['portal:access']);
        $this->postApi("/organizations/{$organization->public_id}/artist-onboardings", [
            'artist' => ['name' => 'Invited Artist'],
            'administrator' => ['email' => 'artist-admin@example.com'],
            'confirmation' => true,
        ])->assertCreated();

        $invitation = ArtistInvitation::query()->sole();
        $plainToken = null;
        Notification::assertSentTo($invitation, ArtistInvitationNotification::class, function ($notification) use (&$plainToken): bool {
            $plainToken = $notification->plainToken;

            return true;
        });
        $this->assertInstanceOf(ShouldBeEncrypted::class, new ArtistInvitationNotification($plainToken));
        $this->assertInstanceOf(ShouldQueue::class, new ArtistInvitationNotification($plainToken));
        $this->assertSame(hash('sha256', $plainToken), $invitation->token_hash);
        $this->assertNotSame($plainToken, $invitation->token_hash);

        $wrongUser = $this->user('wrong@example.com');
        Sanctum::actingAs($wrongUser, ['portal:access']);
        $this->assertApiError($this->postApi('/artist-invitations/accept', ['token' => $plainToken]), 409, 'conflict');

        $artistAdmin = $this->user('artist-admin@example.com');
        Sanctum::actingAs($artistAdmin, ['portal:access']);
        $this->postApi('/artist-invitations/accept', ['token' => $plainToken])
            ->assertOk()
            ->assertJsonPath('data.role', 'artist_admin');
        $this->getApi('/artists/'.$invitation->artist->public_id)->assertOk();
        $this->assertApiError($this->postApi('/artist-invitations/accept', ['token' => $plainToken]), 410, 'gone');
    }

    public function test_existing_user_can_accept_an_artist_invitation(): void
    {
        Notification::fake();
        $existingUser = $this->user('existing-artist-admin@example.com');
        [$labelAdmin, $organization] = $this->label();
        Sanctum::actingAs($labelAdmin, ['portal:access']);
        $this->postApi("/organizations/{$organization->public_id}/artist-onboardings", [
            'artist' => ['name' => 'Existing User Artist'],
            'administrator' => ['email' => $existingUser->email],
            'confirmation' => true,
        ])->assertCreated();

        $invitation = ArtistInvitation::query()->sole();
        $plainToken = null;
        Notification::assertSentTo($invitation, ArtistInvitationNotification::class, function ($notification) use (&$plainToken): bool {
            $plainToken = $notification->plainToken;

            return true;
        });

        Sanctum::actingAs($existingUser, ['portal:access']);
        $this->postApi('/artist-invitations/accept', ['token' => $plainToken])
            ->assertOk()
            ->assertJsonPath('data.role', 'artist_admin');
    }

    public function test_artist_invites_require_member_management_permission_and_resend_rotates_token(): void
    {
        Notification::fake();
        [$labelAdmin, $organization] = $this->label();
        Sanctum::actingAs($labelAdmin, ['portal:access']);
        $this->postApi("/organizations/{$organization->public_id}/artist-onboardings", [
            'artist' => ['name' => 'Managed Artist'],
            'administrator' => ['email' => 'first@example.com'],
            'confirmation' => true,
        ])->assertCreated();
        $artist = Artist::query()->sole();
        $invitation = ArtistInvitation::query()->sole();
        $oldHash = $invitation->token_hash;
        $plainToken = null;
        Notification::assertSentTo($invitation, ArtistInvitationNotification::class, function ($notification) use (&$plainToken): bool {
            $plainToken = $notification->plainToken;

            return true;
        });
        $invitation->update(['expires_at' => now()->subMinute()]);

        $invitedUser = $this->user('first@example.com');
        Sanctum::actingAs($invitedUser, ['portal:access']);
        $this->assertApiError($this->postApi('/artist-invitations/accept', ['token' => $plainToken]), 410, 'gone');

        Sanctum::actingAs($labelAdmin, ['portal:access']);
        $this->postApi("/artist-invitations/{$invitation->public_id}/resend")
            ->assertOk()
            ->assertJsonPath('data.send_count', 2);
        $this->assertNotSame($oldHash, $invitation->fresh()->token_hash);

        $outsider = $this->user('outsider@example.com');
        Sanctum::actingAs($outsider, ['portal:access']);
        $this->postApi("/artists/{$artist->public_id}/invitations", [
            'email' => 'second@example.com',
            'role' => 'artist_user',
        ])->assertForbidden();
        $this->postApi("/artist-invitations/{$invitation->public_id}/resend")->assertForbidden();
    }

    private function label(): array
    {
        $admin = $this->user('label-admin@example.com');
        $organization = Organization::query()->create(['created_by_user_id' => $admin->id]);
        $organization->profile()->create(['name' => 'Workflow Label']);
        $organization->memberships()->create([
            'user_id' => $admin->id,
            'role' => 'label_admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$admin, $organization];
    }

    private function user(string $email, bool $superadmin = false): User
    {
        $user = User::factory()->create(['email' => $email, 'is_superadmin' => $superadmin]);
        $user->profile()->create(['display_name' => str($email)->before('@')->headline()]);

        return $user;
    }
}
