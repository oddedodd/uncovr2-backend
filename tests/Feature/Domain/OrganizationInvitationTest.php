<?php

namespace Tests\Feature\Domain;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\Organizations\OrganizationInvitationNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationInvitationTest extends TestCase
{
    public function test_admin_can_send_invitation_with_correct_recipient_content_and_no_plaintext_token_storage(): void
    {
        Notification::fake();
        [$admin, $organization] = $this->organization();
        Sanctum::actingAs($admin, ['portal:access']);

        $response = $this->postApi("/organizations/{$organization->public_id}/invitations", [
            'email' => 'NEW@EXAMPLE.COM', 'role' => OrganizationRole::User->value,
        ])->assertCreated()->assertJsonPath('data.email', 'new@example.com');

        $invitation = OrganizationInvitation::query()->sole();
        $plainToken = null;
        Notification::assertSentTo($invitation, OrganizationInvitationNotification::class, function ($notification, $channels, $notifiable) use (&$plainToken): bool {
            $plainToken = $notification->plainToken;
            $mail = $notification->toMail($notifiable);

            return $notifiable->routeNotificationForMail() === 'new@example.com'
                && str_contains($mail->subject, 'Invite Label')
                && str_contains($mail->viewData['acceptUrl'], $plainToken)
                && $channels === ['mail'];
        });
        $this->assertIsString($plainToken);
        $this->assertInstanceOf(ShouldBeEncrypted::class, new OrganizationInvitationNotification($plainToken));
        $this->assertSame(hash('sha256', $plainToken), $invitation->token_hash);
        $this->assertNotSame($plainToken, $invitation->token_hash);
        $this->assertDatabaseHas('security_audit_events', ['event_type' => 'organization.invitation_created']);
        $this->assertSame($invitation->public_id, $response->json('data.id'));
    }

    public function test_acceptance_is_email_bound_single_use_and_immediately_grants_scope(): void
    {
        Notification::fake();
        [$admin, $organization] = $this->organization();
        Sanctum::actingAs($admin, ['portal:access']);
        $this->postApi("/organizations/{$organization->public_id}/invitations", ['email' => 'member@example.com', 'role' => 'label_user'])->assertCreated();
        $invitation = OrganizationInvitation::query()->sole();
        $token = null;
        Notification::assertSentTo($invitation, OrganizationInvitationNotification::class, function ($notification) use (&$token): bool {
            $token = $notification->plainToken;

            return true;
        });

        $wrongUser = $this->user('wrong@example.com');
        Sanctum::actingAs($wrongUser, ['portal:access']);
        $this->assertApiError($this->postApi('/organization-invitations/accept', ['token' => $token]), 409, 'conflict');

        $member = $this->user('member@example.com');
        Sanctum::actingAs($member, ['portal:access']);
        $this->postApi('/organization-invitations/accept', ['token' => $token])->assertOk()->assertJsonPath('data.role', 'label_user');
        $this->getApi("/organizations/{$organization->public_id}")->assertOk();
        $this->assertApiError($this->postApi('/organization-invitations/accept', ['token' => $token]), 410, 'gone');
    }

    public function test_expired_invitation_fails_and_resend_rotates_token_and_extends_expiry(): void
    {
        Notification::fake();
        [$admin, $organization] = $this->organization();
        $member = $this->user('member@example.com');
        Sanctum::actingAs($admin, ['portal:access']);
        $this->postApi("/organizations/{$organization->public_id}/invitations", ['email' => $member->email, 'role' => 'label_user'])->assertCreated();
        $invitation = OrganizationInvitation::query()->sole();
        $firstToken = null;
        Notification::assertSentTo($invitation, OrganizationInvitationNotification::class, function ($notification) use (&$firstToken): bool {
            $firstToken = $notification->plainToken;

            return true;
        });
        $oldHash = $invitation->token_hash;
        $invitation->update(['expires_at' => now()->subMinute()]);

        Sanctum::actingAs($member, ['portal:access']);
        $this->assertApiError($this->postApi('/organization-invitations/accept', ['token' => $firstToken]), 410, 'gone');

        $invitation->update(['revoked_at' => null]);
        Sanctum::actingAs($admin, ['portal:access']);
        $this->postApi("/organization-invitations/{$invitation->public_id}/resend")->assertOk()->assertJsonPath('data.send_count', 2);
        $invitation->refresh();
        $this->assertNotSame($oldHash, $invitation->token_hash);
        $this->assertTrue($invitation->expires_at->isFuture());
        $this->assertDatabaseHas('security_audit_events', ['event_type' => 'organization.invitation_resent']);
    }

    public function test_non_admin_cannot_invite_or_resend(): void
    {
        Notification::fake();
        [$admin, $organization] = $this->organization();
        $member = $this->user('member@example.com');
        $organization->memberships()->create(['user_id' => $member->id, 'role' => 'label_user', 'status' => 'active', 'joined_at' => now()]);
        Sanctum::actingAs($member, ['portal:access']);
        $this->postApi("/organizations/{$organization->public_id}/invitations", ['email' => 'new@example.com', 'role' => 'label_user'])->assertForbidden();
        Notification::assertNothingSent();
    }

    public function test_existing_member_cannot_be_invited_again(): void
    {
        Notification::fake();
        [$admin, $organization] = $this->organization();
        Sanctum::actingAs($admin, ['portal:access']);

        $this->assertApiError($this->postApi("/organizations/{$organization->public_id}/invitations", [
            'email' => $admin->email,
            'role' => 'label_user',
        ]), 409, 'conflict');
        Notification::assertNothingSent();
    }

    private function organization(): array
    {
        $admin = $this->user('admin@example.com');
        $organization = Organization::query()->create(['created_by_user_id' => $admin->id]);
        $organization->profile()->create(['name' => 'Invite Label']);
        $organization->memberships()->create(['user_id' => $admin->id, 'role' => 'label_admin', 'status' => 'active', 'joined_at' => now()]);

        return [$admin, $organization];
    }

    private function user(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->profile()->create(['display_name' => 'Test User']);

        return $user;
    }
}
