<?php

namespace Tests\Feature\Domain;

use App\Contracts\MediaStorage;
use App\Data\StoredObject;
use App\Models\Media;
use App\Models\MediaUpload;
use Tests\Fakes\FakeMediaStorage;
use Tests\Feature\Domain\Concerns\BuildsReleaseDomain;
use Tests\TestCase;

class MediaUploadAndRetentionTest extends TestCase
{
    use BuildsReleaseDomain;

    private FakeMediaStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new FakeMediaStorage;
        $this->app->instance(MediaStorage::class, $this->storage);
    }

    public function test_authorized_upload_is_verified_from_provider_metadata_and_can_be_downloaded(): void
    {
        [$media, $admin] = $this->mediaContext();
        $this->actAsDomain($admin);
        $response = $this->postApi("/media/{$media->public_id}/uploads")
            ->assertCreated()->assertJsonPath('data.method', 'PUT')->assertJsonPath('data.token', 'signed-token');
        $upload = MediaUpload::query()->where('public_id', $response->json('data.id'))->sole();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $this->storage->objects["{$upload->bucket}/{$upload->object_key}"] = new StoredObject('image/png', strlen($png), $png, hash('sha256', $png));

        $this->postApi("/media/{$media->public_id}/uploads/{$upload->public_id}/complete")
            ->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.width', 1)->assertJsonPath('data.height', 1);
        $this->getApi("/media/{$media->public_id}/download")->assertOk()->assertJsonPath('data.expires_in', 900);
        $this->assertDatabaseHas('media_uploads', ['id' => $upload->id, 'status' => 'active']);
    }

    public function test_mime_spoofing_is_rejected_and_failed_object_is_removed(): void
    {
        [$media, $admin] = $this->mediaContext();
        $this->actAsDomain($admin);
        $id = $this->postApi("/media/{$media->public_id}/uploads")->assertCreated()->json('data.id');
        $upload = MediaUpload::query()->where('public_id', $id)->sole();
        $this->storage->objects["{$upload->bucket}/{$upload->object_key}"] = new StoredObject('application/pdf', 100, '%PDF');

        $this->assertApiError($this->postApi("/media/{$media->public_id}/uploads/{$upload->public_id}/complete"), 422, 'validation_failed');
        $this->assertSame('failed', $upload->fresh()->status);
        $this->assertContains("{$upload->bucket}/{$upload->object_key}", $this->storage->deleted);
    }

    public function test_spoofed_provider_metadata_is_rejected_by_file_signature(): void
    {
        [$media, $admin] = $this->mediaContext();
        $this->actAsDomain($admin);
        $id = $this->postApi("/media/{$media->public_id}/uploads")->assertCreated()->json('data.id');
        $upload = MediaUpload::query()->where('public_id', $id)->sole();
        $this->storage->objects["{$upload->bucket}/{$upload->object_key}"] = new StoredObject('image/png', 8, '%PDF-1.7');

        $this->assertApiError($this->postApi("/media/{$media->public_id}/uploads/{$upload->public_id}/complete"), 422, 'validation_failed');
        $this->assertSame('failed', $upload->fresh()->status);
    }

    public function test_cross_tenant_upload_is_forbidden_and_active_object_is_deleted_with_record(): void
    {
        [$media, $admin] = $this->mediaContext();
        $outsider = $this->domainUser('outsider-media@example.com');
        $this->actAsDomain($outsider);
        $this->postApi("/media/{$media->public_id}/uploads")->assertForbidden();

        $media->update(['status' => 'ready', 'storage_disk' => 'uncovr-private-media', 'storage_key' => 'active/image.png']);
        $this->actAsDomain($admin);
        $this->deleteApi("/media/{$media->public_id}")->assertOk();
        $this->assertContains('uncovr-private-media/active/image.png', $this->storage->deleted);
    }

    public function test_expired_uploads_are_pruned_but_active_uploads_are_retained(): void
    {
        [$media, $admin] = $this->mediaContext();
        $expired = $media->uploads()->create(['generation' => 1, 'bucket' => 'private', 'object_key' => 'expired.png', 'expected_mime_type' => 'image/png', 'maximum_byte_size' => 1000, 'requested_by_user_id' => $admin->id, 'expires_at' => now()->subMinute()]);
        $active = $media->uploads()->create(['generation' => 2, 'bucket' => 'private', 'object_key' => 'active.png', 'expected_mime_type' => 'image/png', 'maximum_byte_size' => 1000, 'status' => 'active', 'requested_by_user_id' => $admin->id, 'expires_at' => now()->subMinute()]);

        $this->artisan('media:prune-uploads')->assertSuccessful();
        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertSame('active', $active->fresh()->status);
        $this->assertSame(['private/expired.png'], $this->storage->deleted);
    }

    private function mediaContext(): array
    {
        $admin = $this->domainUser('media-admin@example.com');
        $organization = $this->domainOrganization($admin);
        $media = Media::query()->create(['organization_id' => $organization->id, 'kind' => 'image', 'original_filename' => 'cover.png', 'mime_type' => 'image/png', 'created_by_user_id' => $admin->id, 'updated_by_user_id' => $admin->id]);

        return [$media, $admin];
    }
}
