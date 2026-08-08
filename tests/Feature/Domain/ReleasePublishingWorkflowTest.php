<?php

namespace Tests\Feature\Domain;

use App\Contracts\MediaStorage;
use App\Jobs\PublishScheduledRelease;
use App\Models\Media;
use App\Models\ReleasePublication;
use App\Services\Releases\ReleasePublicationService;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\Fakes\FakeMediaStorage;
use Tests\Feature\Domain\Concerns\BuildsReleaseDomain;
use Tests\TestCase;

class ReleasePublishingWorkflowTest extends TestCase
{
    use BuildsReleaseDomain;

    private FakeMediaStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new FakeMediaStorage;
        $this->app->instance(MediaStorage::class, $this->storage);
    }

    public function test_draft_can_be_previewed_submitted_approved_published_and_unpublished(): void
    {
        [$release, $admin, $editor] = $this->releaseContext();
        $this->actAsDomain($editor);
        $this->getApi("/releases/{$release->public_id}/preview")->assertOk()->assertJsonPath('data.ready_for_review', true);
        $this->postApi("/releases/{$release->public_id}/submit", ['note' => 'Ready'])->assertCreated()->assertJsonPath('data.status', 'review');
        $this->patchApi("/releases/{$release->public_id}", ['title' => 'Locked'])->assertForbidden();
        $this->postApi("/releases/{$release->public_id}/approve", ['note' => 'No'])->assertForbidden();

        $this->actAsDomain($admin);
        $this->postApi("/releases/{$release->public_id}/approve", ['note' => 'Approved'])->assertOk()->assertJsonPath('data.status', 'review');
        $this->postApi("/releases/{$release->public_id}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        $publication = ReleasePublication::query()->where('release_id', $release->id)->sole();
        $this->assertSame('published', $publication->snapshot['status']);
        $this->assertArrayNotHasKey('editor_user_ids', $publication->snapshot);
        $this->assertNotEmpty($publication->snapshot['media']);
        $this->assertCount(1, $this->storage->copies);

        $this->postApi("/releases/{$release->public_id}/unpublish")->assertOk()->assertJsonPath('data.status', 'unpublished');
        $this->assertNotNull($publication->fresh()->withdrawn_at);
        $this->patchApi("/releases/{$release->public_id}", ['title' => 'Revised'])->assertOk();
    }

    public function test_rejection_returns_release_to_draft_and_only_admin_can_decide(): void
    {
        [$release, $admin, $editor] = $this->releaseContext();
        $this->actAsDomain($editor);
        $this->postApi("/releases/{$release->public_id}/submit", [])->assertCreated();
        $this->postApi("/releases/{$release->public_id}/reject", ['note' => 'Needs work'])->assertForbidden();
        $this->actAsDomain($admin);
        $this->postApi("/releases/{$release->public_id}/reject", ['note' => 'Needs work'])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->assertDatabaseHas('release_approval_requests', ['release_id' => $release->id, 'status' => 'rejected']);
    }

    public function test_scheduled_release_is_dispatched_and_job_publishes_when_due(): void
    {
        Queue::fake();
        [$release, $admin] = $this->releaseContext();
        $this->actAsDomain($admin);
        $this->postApi("/releases/{$release->public_id}/submit", [])->assertCreated();
        $this->postApi("/releases/{$release->public_id}/approve", [])->assertOk();
        $this->postApi("/releases/{$release->public_id}/schedule", ['publish_at' => now()->addMinute()->toISOString()])->assertOk()->assertJsonPath('data.status', 'scheduled');
        $release->update(['scheduled_for' => now()->subSecond()]);
        $this->artisan('releases:dispatch-scheduled')->assertSuccessful();
        Queue::assertPushed(PublishScheduledRelease::class, fn ($job) => $job->releaseId === $release->id);

        Queue::fake(false);
        (new PublishScheduledRelease($release->id, $admin->id))->handle($this->app->make(ReleasePublicationService::class));
        $this->assertSame('published', $release->fresh()->status);
    }

    public function test_sensitive_activity_records_are_immutable(): void
    {
        [$release, $admin] = $this->releaseContext();
        $event = $release->activityEvents()->create(['user_id' => $admin->id, 'event_type' => 'release.test', 'occurred_at' => now()]);
        $this->expectException(LogicException::class);
        $event->update(['event_type' => 'release.tampered']);
    }

    private function releaseContext(): array
    {
        $admin = $this->domainUser('publish-admin@example.com');
        $editor = $this->domainUser('publish-editor@example.com');
        $organization = $this->domainOrganization($admin);
        $this->addLabelMember($organization, $editor);
        $artist = $this->domainArtist($admin);
        $this->linkArtist($organization, $artist, $admin);
        $cover = Media::query()->create([
            'organization_id' => $organization->id, 'kind' => 'image', 'status' => 'ready',
            'original_filename' => 'cover.png', 'mime_type' => 'image/png', 'byte_size' => 100,
            'width' => 1200, 'height' => 1200, 'storage_disk' => 'uncovr-private-media', 'storage_key' => 'covers/cover.png',
            'verified_at' => now(), 'created_by_user_id' => $editor->id, 'updated_by_user_id' => $editor->id,
        ]);
        $release = $this->createOrganizationRelease($editor, $organization, $artist, ['cover_media_id' => $cover->public_id]);

        return [$release, $admin, $editor];
    }
}
