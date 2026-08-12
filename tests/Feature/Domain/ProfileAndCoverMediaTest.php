<?php

namespace Tests\Feature\Domain;

use App\Contracts\MediaStorage;
use App\Data\StoredObject;
use App\Enums\ArtistRole;
use App\Enums\OrganizationRole;
use App\Models\Artist;
use App\Models\Media;
use App\Models\MediaUpload;
use App\Models\Organization;
use App\Models\Release;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\Fakes\FakeMediaStorage;
use Tests\Feature\Domain\Concerns\BuildsReleaseDomain;
use Tests\TestCase;

class ProfileAndCoverMediaTest extends TestCase
{
    use BuildsReleaseDomain;

    private FakeMediaStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new FakeMediaStorage;
        $this->app->instance(MediaStorage::class, $this->storage);
    }

    public function test_label_admin_can_add_replace_and_remove_a_label_logo(): void
    {
        $admin = $this->domainUser('label-admin@example.com');
        $organization = $this->domainOrganization($admin);
        $first = $this->organizationMedia($organization, $admin, 'first.png');
        $second = $this->organizationMedia($organization, $admin, 'second.png');
        $this->actAsDomain($admin);

        $this->patchApi("/organizations/{$organization->public_id}", ['logo_media_id' => $first->public_id])
            ->assertOk()
            ->assertJsonPath('data.profile.logo_media_id', $first->public_id)
            ->assertJsonPath('data.profile.logo_media.id', $first->public_id)
            ->assertJsonPath('data.profile.logo_media.mime_type', 'image/png')
            ->assertJsonMissingPath('data.profile.logo_media.storage_key');
        $this->patchApi("/organizations/{$organization->public_id}", ['logo_media_id' => $second->public_id])
            ->assertOk()
            ->assertJsonPath('data.profile.logo_media_id', $second->public_id);
        $this->patchApi("/organizations/{$organization->public_id}", ['logo_media_id' => null])
            ->assertOk()
            ->assertJsonPath('data.profile.logo_media_id', null)
            ->assertJsonPath('data.profile.logo_media', null);
    }

    public function test_label_admin_can_upload_and_attach_small_logo_in_one_request(): void
    {
        $admin = $this->domainUser('fast-label-admin@example.com');
        $organization = $this->domainOrganization($admin);
        $this->actAsDomain($admin);

        $this->post($this->apiUrl("/organizations/{$organization->public_id}/logo"), [
            'image' => UploadedFile::fake()->image('logo.png', 564, 511),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.profile.logo_media.mime_type', 'image/png')
            ->assertJsonPath('data.profile.logo_media.width', 564)
            ->assertJsonPath('data.profile.logo_media.height', 511);

        $media = Media::query()->where('organization_id', $organization->getKey())->where('original_filename', 'logo.png')->sole();
        $this->assertSame('ready', $media->status);
        $this->assertSame($media->getKey(), $organization->fresh()->profile->logo_media_id);
        $this->assertArrayHasKey("{$media->storage_disk}/{$media->storage_key}", $this->storage->objects);
        $this->assertDatabaseHas('media_uploads', [
            'media_id' => $media->getKey(),
            'status' => 'active',
            'actual_mime_type' => 'image/png',
            'width' => 564,
            'height' => 511,
        ]);
    }

    public function test_label_user_cannot_change_a_label_logo_but_superadmin_can(): void
    {
        $admin = $this->domainUser('label-admin@example.com');
        $labelUser = $this->domainUser('label-user@example.com');
        $superadmin = $this->domainUser('superadmin@example.com', true);
        $organization = $this->domainOrganization($admin);
        $this->addLabelMember($organization, $labelUser, OrganizationRole::User);
        $media = $this->organizationMedia($organization, $admin);
        $artist = $this->domainArtist($admin);
        $artistMedia = $this->artistMedia($artist, $admin);

        $this->actAsDomain($labelUser);
        $this->patchApi("/organizations/{$organization->public_id}", ['logo_media_id' => $media->public_id])->assertForbidden();

        $this->actAsDomain($superadmin);
        $this->patchApi("/organizations/{$organization->public_id}", ['logo_media_id' => $media->public_id])
            ->assertOk()
            ->assertJsonPath('data.profile.logo_media_id', $media->public_id);
        $this->patchApi("/artists/{$artist->public_id}", ['image_media_id' => $artistMedia->public_id])
            ->assertOk()
            ->assertJsonPath('data.profile.image_media_id', $artistMedia->public_id);
    }

    public function test_artist_admin_can_add_replace_and_remove_logo_and_artist_image(): void
    {
        $admin = $this->domainUser('artist-admin@example.com');
        $artist = $this->domainArtist($admin);
        $logo = $this->artistMedia($artist, $admin, 'logo.png');
        $image = $this->artistMedia($artist, $admin, 'portrait.png');
        $replacement = $this->artistMedia($artist, $admin, 'replacement.png');
        $this->actAsDomain($admin);

        $this->patchApi("/artists/{$artist->public_id}", [
            'logo_media_id' => $logo->public_id,
            'image_media_id' => $image->public_id,
        ])->assertOk()
            ->assertJsonPath('data.profile.logo_media_id', $logo->public_id)
            ->assertJsonPath('data.profile.image_media_id', $image->public_id)
            ->assertJsonPath('data.profile.image_media.width', 1200);
        $this->patchApi("/artists/{$artist->public_id}", ['image_media_id' => $replacement->public_id])
            ->assertOk()
            ->assertJsonPath('data.profile.image_media_id', $replacement->public_id);
        $this->patchApi("/artists/{$artist->public_id}", ['logo_media_id' => null, 'image_media_id' => null])
            ->assertOk()
            ->assertJsonPath('data.profile.logo_media_id', null)
            ->assertJsonPath('data.profile.image_media_id', null);
    }

    public function test_artist_admin_can_upload_profile_images_in_one_request(): void
    {
        $admin = $this->domainUser('fast-artist-admin@example.com');
        $artist = $this->domainArtist($admin);
        $this->actAsDomain($admin);

        $this->post($this->apiUrl("/artists/{$artist->public_id}/logo"), [
            'image' => UploadedFile::fake()->image('artist-logo.png', 256, 256),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.profile.logo_media.mime_type', 'image/png')
            ->assertJsonPath('data.profile.logo_media.width', 256);

        $this->post($this->apiUrl("/artists/{$artist->public_id}/image"), [
            'image' => UploadedFile::fake()->image('artist-image.png', 900, 600),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.profile.image_media.mime_type', 'image/png')
            ->assertJsonPath('data.profile.image_media.height', 600);

        $profile = $artist->fresh()->profile;
        $this->assertNotNull($profile->logo_media_id);
        $this->assertNotNull($profile->image_media_id);
    }

    public function test_artist_user_cannot_change_profile_images_but_can_keep_existing_profile_edit_permissions(): void
    {
        $admin = $this->domainUser('artist-admin@example.com');
        $artistUser = $this->domainUser('artist-user@example.com');
        $artist = $this->domainArtist($admin);
        $this->addArtistMember($artist, $artistUser, ArtistRole::User);
        $media = $this->artistMedia($artist, $admin);
        $this->actAsDomain($artistUser);

        $this->patchApi("/artists/{$artist->public_id}", ['biography' => 'Allowed profile edit.'])
            ->assertOk();
        $this->patchApi("/artists/{$artist->public_id}", ['logo_media_id' => $media->public_id])
            ->assertForbidden();
        $this->patchApi("/artists/{$artist->public_id}", ['image_media_id' => $media->public_id])
            ->assertForbidden();
    }

    public function test_cross_scope_pending_failed_and_non_image_media_are_rejected(): void
    {
        $admin = $this->domainUser('admin@example.com');
        $otherAdmin = $this->domainUser('other@example.com');
        $organization = $this->domainOrganization($admin);
        $otherOrganization = $this->domainOrganization($otherAdmin, 'Other Label');
        $artist = $this->domainArtist($admin);
        $crossScope = $this->organizationMedia($otherOrganization, $otherAdmin);
        $pending = $this->organizationMedia($organization, $admin, status: 'pending');
        $failed = $this->organizationMedia($organization, $admin, status: 'failed');
        $audio = $this->organizationMedia($organization, $admin, kind: 'audio');
        $artistCrossScope = $this->organizationMedia($organization, $admin, 'label-image.png');
        $this->actAsDomain($admin);

        foreach ([$crossScope, $pending, $failed, $audio] as $media) {
            $this->assertApiError(
                $this->patchApi("/organizations/{$organization->public_id}", ['logo_media_id' => $media->public_id]),
                422,
                'validation_failed',
            );
        }
        $this->assertApiError(
            $this->patchApi("/organizations/{$organization->public_id}", ['logo_media_id' => '01h00000000000000000000000']),
            422,
            'validation_failed',
        );
        $this->assertApiError(
            $this->patchApi("/artists/{$artist->public_id}", ['image_media_id' => $artistCrossScope->public_id]),
            422,
            'validation_failed',
        );
    }

    public function test_referenced_profile_and_cover_media_cannot_be_deleted_until_detached(): void
    {
        $admin = $this->domainUser('admin@example.com');
        $organization = $this->domainOrganization($admin);
        $artist = $this->domainArtist($admin);
        $this->linkArtist($organization, $artist, $admin);
        $labelLogo = $this->organizationMedia($organization, $admin, 'label.png');
        $artistLogo = $this->artistMedia($artist, $admin, 'artist-logo.png');
        $artistImage = $this->artistMedia($artist, $admin, 'artist-image.png');
        $cover = $this->organizationMedia($organization, $admin, 'cover.png');
        $this->actAsDomain($admin);
        $this->patchApi("/organizations/{$organization->public_id}", ['logo_media_id' => $labelLogo->public_id])->assertOk();
        $this->patchApi("/artists/{$artist->public_id}", [
            'logo_media_id' => $artistLogo->public_id,
            'image_media_id' => $artistImage->public_id,
        ])->assertOk();
        $release = $this->createOrganizationRelease($admin, $organization, $artist, ['cover_media_id' => $cover->public_id]);

        foreach ([$labelLogo, $artistLogo, $artistImage, $cover] as $media) {
            $this->assertApiError($this->deleteApi("/media/{$media->public_id}"), 422, 'validation_failed');
        }

        $this->patchApi("/organizations/{$organization->public_id}", ['logo_media_id' => null])->assertOk();
        $this->patchApi("/artists/{$artist->public_id}", ['logo_media_id' => null, 'image_media_id' => null])->assertOk();
        $this->patchApi("/releases/{$release->public_id}", ['cover_media_id' => null])->assertOk();
        foreach ([$labelLogo, $artistLogo, $artistImage, $cover] as $media) {
            $this->deleteApi("/media/{$media->public_id}")->assertOk();
        }
    }

    public function test_release_cover_is_validated_on_create_update_replace_and_remove(): void
    {
        $admin = $this->domainUser('release-admin@example.com');
        $otherAdmin = $this->domainUser('other@example.com');
        $organization = $this->domainOrganization($admin);
        $otherOrganization = $this->domainOrganization($otherAdmin);
        $artist = $this->domainArtist($admin);
        $this->linkArtist($organization, $artist, $admin);
        $ready = $this->organizationMedia($organization, $admin, 'ready.png');
        $replacement = $this->organizationMedia($organization, $admin, 'replacement.png');
        $pending = $this->organizationMedia($organization, $admin, 'pending.png', status: 'pending');
        $failed = $this->organizationMedia($organization, $admin, 'failed.png', status: 'failed');
        $audio = $this->organizationMedia($organization, $admin, 'track.mp3', kind: 'audio');
        $crossScope = $this->organizationMedia($otherOrganization, $otherAdmin, 'cross.png');
        $this->actAsDomain($admin);

        foreach ([$pending, $failed, $audio, $crossScope] as $invalid) {
            $this->assertApiError($this->postApi('/releases', $this->releasePayload($organization, $artist, $invalid)), 422, 'validation_failed');
        }

        $releaseResponse = $this->postApi('/releases', $this->releasePayload($organization, $artist, $ready))
            ->assertCreated()
            ->assertJsonPath('data.cover_media_id', $ready->public_id)
            ->assertJsonPath('data.cover_media.id', $ready->public_id)
            ->assertJsonMissingPath('data.cover_media.storage_disk');
        $release = Release::query()->where('public_id', $releaseResponse->json('data.id'))->sole();

        foreach ([$pending, $failed, $audio, $crossScope] as $invalid) {
            $this->assertApiError($this->patchApi("/releases/{$release->public_id}", ['cover_media_id' => $invalid->public_id]), 422, 'validation_failed');
        }
        $this->patchApi("/releases/{$release->public_id}", ['cover_media_id' => $replacement->public_id])
            ->assertOk()
            ->assertJsonPath('data.cover_media_id', $replacement->public_id);
        $this->patchApi("/releases/{$release->public_id}", ['cover_media_id' => null])
            ->assertOk()
            ->assertJsonPath('data.cover_media_id', null)
            ->assertJsonPath('data.cover_media', null);
    }

    public function test_batch_download_returns_authorized_short_lived_urls_without_storage_details(): void
    {
        $admin = $this->domainUser('batch-admin@example.com');
        $organization = $this->domainOrganization($admin);
        $first = $this->organizationMedia($organization, $admin, 'one.png');
        $second = $this->organizationMedia($organization, $admin, 'two.png');
        $pending = $this->organizationMedia($organization, $admin, 'pending.png', status: 'pending');
        $this->actAsDomain($admin);

        $this->postApi('/media/downloads', ['media_ids' => [$first->public_id, $second->public_id]])
            ->assertOk()
            ->assertJsonPath('data.expires_in', 900)
            ->assertJsonPath('data.items.0.media_id', $first->public_id)
            ->assertJsonPath('data.items.1.media_id', $second->public_id)
            ->assertJsonMissingPath('data.items.0.storage_key');
        $this->assertSame(
            [['bucket' => 'uncovr-private-media', 'paths' => [$first->storage_key, $second->storage_key]]],
            $this->storage->batchSignCalls,
            'Batch download must sign every object in one storage call per bucket.',
        );

        $this->assertApiError($this->postApi('/media/downloads', ['media_ids' => [$pending->public_id]]), 422, 'validation_failed');

        $outsider = $this->domainUser('outsider@example.com');
        $this->actAsDomain($outsider);
        $this->postApi('/media/downloads', ['media_ids' => [$first->public_id]])->assertForbidden();
    }

    public function test_artist_owned_release_requires_artist_owned_cover_media(): void
    {
        $admin = $this->domainUser('artist-release-admin@example.com');
        $organization = $this->domainOrganization($admin);
        $artist = $this->domainArtist($admin);
        $validCover = $this->artistMedia($artist, $admin, 'artist-cover.png');
        $invalidCover = $this->organizationMedia($organization, $admin, 'label-cover.png');
        $this->actAsDomain($admin);
        $base = [
            'owner_type' => 'artist',
            'owner_id' => $artist->public_id,
            'primary_artist_id' => $artist->public_id,
            'type' => 'single',
            'title' => 'Artist-owned release',
            'subtitle' => null,
            'description' => null,
            'release_date' => null,
            'upc' => null,
        ];

        $this->assertApiError(
            $this->postApi('/releases', [...$base, 'cover_media_id' => $invalidCover->public_id]),
            422,
            'validation_failed',
        );
        $this->postApi('/releases', [...$base, 'cover_media_id' => $validCover->public_id])
            ->assertCreated()
            ->assertJsonPath('data.cover_media_id', $validCover->public_id);
    }

    public function test_verified_existing_upload_flow_can_be_attached_to_a_profile(): void
    {
        $admin = $this->domainUser('upload-admin@example.com');
        $organization = $this->domainOrganization($admin);
        $this->actAsDomain($admin);
        $mediaId = $this->postApi('/media', [
            'owner_type' => 'organization',
            'owner_id' => $organization->public_id,
            'kind' => 'image',
            'original_filename' => 'verified.png',
            'mime_type' => 'image/png',
            'byte_size' => null,
            'width' => null,
            'height' => null,
            'metadata' => ['purpose' => 'label_logo'],
        ])->assertCreated()->json('data.id');
        $media = Media::query()->where('public_id', $mediaId)->sole();
        $uploadId = $this->postApi("/media/{$media->public_id}/uploads")->assertCreated()->json('data.id');
        $upload = MediaUpload::query()->where('public_id', $uploadId)->sole();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $this->storage->objects["{$upload->bucket}/{$upload->object_key}"] = new StoredObject('image/png', strlen($png), $png, hash('sha256', $png));

        $this->postApi("/media/{$media->public_id}/uploads/{$upload->public_id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');
        $this->patchApi("/organizations/{$organization->public_id}", ['logo_media_id' => $media->public_id])
            ->assertOk()
            ->assertJsonPath('data.profile.logo_media_id', $media->public_id)
            ->assertJsonPath('data.profile.logo_media.width', 1);
    }

    private function organizationMedia(
        Organization $organization,
        User $actor,
        string $filename = 'image.png',
        string $status = 'ready',
        string $kind = 'image',
    ): Media {
        return $this->media($actor, $filename, $status, $kind, organization: $organization);
    }

    private function artistMedia(
        Artist $artist,
        User $actor,
        string $filename = 'image.png',
        string $status = 'ready',
        string $kind = 'image',
    ): Media {
        return $this->media($actor, $filename, $status, $kind, artist: $artist);
    }

    private function media(
        User $actor,
        string $filename,
        string $status,
        string $kind,
        ?Organization $organization = null,
        ?Artist $artist = null,
    ): Media {
        return Media::query()->create([
            'organization_id' => $organization?->id,
            'artist_id' => $artist?->id,
            'kind' => $kind,
            'status' => $status,
            'original_filename' => $filename,
            'mime_type' => $kind === 'image' ? 'image/png' : 'audio/mpeg',
            'byte_size' => 1024,
            'width' => $kind === 'image' ? 1200 : null,
            'height' => $kind === 'image' ? 1200 : null,
            'storage_disk' => 'uncovr-private-media',
            'storage_key' => ($organization ? 'organizations/' : 'artists/').$filename,
            'verified_at' => $status === 'ready' ? now() : null,
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
        ]);
    }

    private function releasePayload(Organization $organization, Artist $artist, Media $cover): array
    {
        return [
            'owner_type' => 'organization',
            'owner_id' => $organization->public_id,
            'primary_artist_id' => $artist->public_id,
            'type' => 'album',
            'title' => 'Profile Images',
            'subtitle' => null,
            'description' => null,
            'release_date' => null,
            'upc' => null,
            'cover_media_id' => $cover->public_id,
        ];
    }
}
