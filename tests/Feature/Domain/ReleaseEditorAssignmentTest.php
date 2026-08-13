<?php

namespace Tests\Feature\Domain;

use App\Enums\ArtistRole;
use App\Models\Release;
use App\Models\User;
use App\Notifications\Releases\ReleaseEditorAssignedNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Domain\Concerns\BuildsReleaseDomain;
use Tests\TestCase;

class ReleaseEditorAssignmentTest extends TestCase
{
    use BuildsReleaseDomain;

    public function test_assigning_an_editor_notifies_the_assigned_user_once(): void
    {
        Notification::fake();
        [$release, $admin, $artistUser] = $this->artistContext();

        $this->actAsDomain($admin);
        $this->postApi("/releases/{$release->public_id}/editors", ['user_id' => $artistUser->public_id])
            ->assertCreated()
            ->assertJsonPath('data.user_id', $artistUser->public_id);

        Notification::assertSentTo($artistUser, ReleaseEditorAssignedNotification::class, function ($notification, $channels, $notifiable) use ($release): bool {
            $this->assertInstanceOf(ShouldQueue::class, $notification);
            $this->assertInstanceOf(ShouldBeEncrypted::class, $notification);
            $this->assertSame(config('email.queue'), $notification->queue);

            $mail = $notification->toMail($notifiable);
            $this->assertStringContainsString(rtrim(config('authentication.portal_url'), '/'), $mail->viewData['builderUrl']);
            $this->assertStringContainsString($release->public_id, $mail->viewData['builderUrl']);
            $this->assertSame('Solo Artist', $mail->viewData['ownerName']);
            $this->assertSame('Assign Admin', $mail->viewData['assignedByName']);
            $this->assertSame([
                'html' => 'mail.releases.editor-assigned',
                'text' => 'mail.releases.editor-assigned-text',
            ], $mail->view);
            $this->assertContains('release-editor-assignment', $mail->tags);

            return $notification->releaseId === $release->public_id;
        });
        Notification::assertSentToTimes($artistUser, ReleaseEditorAssignedNotification::class, 1);
    }

    public function test_repeated_assignment_is_idempotent_and_sends_no_second_notification(): void
    {
        Notification::fake();
        [$release, $admin, $artistUser] = $this->artistContext();

        $this->actAsDomain($admin);
        $this->postApi("/releases/{$release->public_id}/editors", ['user_id' => $artistUser->public_id])->assertCreated();
        $this->postApi("/releases/{$release->public_id}/editors", ['user_id' => $artistUser->public_id])->assertOk();

        Notification::assertSentToTimes($artistUser, ReleaseEditorAssignedNotification::class, 1);
        $this->assertDatabaseCount('release_editors', 2);
    }

    public function test_removing_an_editor_sends_no_notification(): void
    {
        Notification::fake();
        [$release, $admin, $artistUser] = $this->artistContext();

        $this->actAsDomain($admin);
        $this->postApi("/releases/{$release->public_id}/editors", ['user_id' => $artistUser->public_id])->assertCreated();
        Notification::fake();
        $this->deleteApi("/releases/{$release->public_id}/editors/{$artistUser->public_id}")->assertOk();

        Notification::assertNothingSent();
    }

    public function test_assigning_a_user_without_scope_access_is_rejected_and_sends_nothing(): void
    {
        Notification::fake();
        [$release, $admin] = $this->artistContext();
        $outsider = $this->domainUser('assign-outsider@example.com');

        $this->actAsDomain($admin);
        $this->assertApiError(
            $this->postApi("/releases/{$release->public_id}/editors", ['user_id' => $outsider->public_id]),
            422,
            'validation_failed',
        );

        Notification::assertNothingSent();
        $this->assertDatabaseMissing('release_editors', ['user_id' => $outsider->id]);
    }

    public function test_assigned_editor_can_build_pages_and_is_listed_with_a_display_name(): void
    {
        [$release, $admin, $artistUser] = $this->artistContext();

        $this->actAsDomain($artistUser);
        $this->postApi("/releases/{$release->public_id}/pages", ['position' => 1, 'title' => 'Denied'])->assertForbidden();

        $this->actAsDomain($admin);
        $this->postApi("/releases/{$release->public_id}/editors", ['user_id' => $artistUser->public_id])->assertCreated();

        $this->actAsDomain($artistUser);
        $pageId = $this->postApi("/releases/{$release->public_id}/pages", ['position' => 1, 'title' => 'Story'])
            ->assertCreated()->json('data.id');
        $this->postApi("/pages/{$pageId}/blocks", [
            'position' => 1, 'type' => 'heading', 'payload' => ['text' => 'Hello', 'level' => 1],
        ])->assertCreated();

        $detail = $this->getApi("/releases/{$release->public_id}")->assertOk();
        $editors = collect($detail->json('data.editors'));
        $this->assertTrue($editors->contains(fn (array $editor): bool => $editor['user_id'] === $artistUser->public_id
            && $editor['display_name'] === 'Assign User'));
        $this->assertContains($artistUser->public_id, $detail->json('data.editor_user_ids'));
        $this->assertTrue($detail->json('data.permissions.can_update'));
        $this->assertFalse($detail->json('data.permissions.can_manage_editors'));
    }

    public function test_assigned_to_me_filter_returns_only_explicit_assignments(): void
    {
        [$release, $admin, $artistUser] = $this->artistContext();
        $artist = $release->ownerArtist;
        $other = $this->createArtistRelease($admin, $artist, ['title' => 'Unassigned Release']);

        $this->actAsDomain($admin);
        $this->postApi("/releases/{$release->public_id}/editors", ['user_id' => $artistUser->public_id])->assertCreated();

        $this->actAsDomain($artistUser);
        $this->getApi('/releases')->assertOk()->assertJsonCount(2, 'data');

        $assigned = $this->getApi('/releases?filter[assigned_to_me]=1')->assertOk();
        $assigned->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $release->public_id);
        $this->assertTrue($assigned->json('data.0.permissions.can_update'));

        // The summary must not leak editor email addresses to ordinary members.
        $this->assertArrayNotHasKey('email', $assigned->json('data.0.editors.0'));

        // Creating a release assigns the creator, so the creating admin matches
        // both. A second administrator who created and was assigned nothing gets
        // an empty list even though they may edit everything in the scope.
        $secondAdmin = $this->domainUser('assign-admin-two@example.com');
        $this->addArtistMember($artist, $secondAdmin, ArtistRole::Admin);
        $this->actAsDomain($secondAdmin);
        $this->getApi('/releases')->assertOk()->assertJsonCount(2, 'data');
        $this->getApi('/releases?filter[assigned_to_me]=1')->assertOk()->assertJsonCount(0, 'data');

        $this->actAsDomain($admin);
        $this->getApi('/releases?filter[assigned_to_me]=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $other->public_id]);
    }

    /** @return array{0: Release, 1: User, 2: User} */
    private function artistContext(): array
    {
        $admin = $this->domainUser('assign-admin@example.com');
        $artistUser = $this->domainUser('assign-user@example.com');
        $artist = $this->domainArtist($admin, 'Solo Artist');
        $this->addArtistMember($artist, $artistUser, ArtistRole::User);
        $release = $this->createArtistRelease($admin, $artist, ['title' => 'Assignable Release']);

        return [$release, $admin, $artistUser];
    }
}
