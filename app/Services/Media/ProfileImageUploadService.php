<?php

namespace App\Services\Media;

use App\Contracts\MediaStorage;
use App\Models\Artist;
use App\Models\ArtistProfile;
use App\Models\Media;
use App\Models\MediaUpload;
use App\Models\Organization;
use App\Models\OrganizationProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProfileImageUploadService
{
    public function __construct(private readonly MediaStorage $storage) {}

    public function uploadOrganizationLogo(Organization $organization, UploadedFile $file, User $actor): Media
    {
        return $this->uploadAndAttach($organization, 'logo_media_id', $file, $actor);
    }

    public function uploadArtistLogo(Artist $artist, UploadedFile $file, User $actor): Media
    {
        return $this->uploadAndAttach($artist, 'logo_media_id', $file, $actor);
    }

    public function uploadArtistImage(Artist $artist, UploadedFile $file, User $actor): Media
    {
        return $this->uploadAndAttach($artist, 'image_media_id', $file, $actor);
    }

    private function uploadAndAttach(Organization|Artist $owner, string $profileField, UploadedFile $file, User $actor): Media
    {
        [$body, $mimeType, $byteSize, $width, $height, $checksum] = $this->validatedImage($file);
        $bucket = config('media.private_bucket');

        $media = Media::query()->create([
            'organization_id' => $owner instanceof Organization ? $owner->getKey() : null,
            'artist_id' => $owner instanceof Artist ? $owner->getKey() : null,
            'kind' => 'image',
            'status' => 'pending',
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $mimeType,
            'byte_size' => $byteSize,
            'width' => $width,
            'height' => $height,
            'created_by_user_id' => $actor->getKey(),
            'updated_by_user_id' => $actor->getKey(),
        ]);

        $objectKey = $this->objectKey($owner, $media, $mimeType);
        $upload = $media->uploads()->create([
            'generation' => 1,
            'bucket' => $bucket,
            'object_key' => $objectKey,
            'expected_mime_type' => $mimeType,
            'maximum_byte_size' => config('media.limits.image.bytes'),
            'requested_by_user_id' => $actor->getKey(),
            'expires_at' => now()->addSeconds(config('media.upload_ttl_seconds')),
        ]);

        try {
            $this->storage->upload($bucket, $objectKey, $body, $mimeType);
        } catch (\Throwable $exception) {
            $upload->update(['status' => 'failed']);
            $media->update(['status' => 'failed']);

            throw $exception;
        }

        DB::transaction(function () use ($owner, $profileField, $media, $upload, $actor, $bucket, $objectKey, $mimeType, $byteSize, $width, $height, $checksum): void {
            MediaUpload::query()
                ->whereKey($upload->getKey())
                ->update([
                    'status' => 'active',
                    'actual_byte_size' => $byteSize,
                    'actual_mime_type' => $mimeType,
                    'width' => $width,
                    'height' => $height,
                    'checksum_sha256' => $checksum,
                    'verified_at' => now(),
                    'activated_at' => now(),
                ]);

            Media::query()
                ->whereKey($media->getKey())
                ->update([
                    'status' => 'ready',
                    'storage_disk' => $bucket,
                    'storage_key' => $objectKey,
                    'active_upload_id' => $upload->getKey(),
                    'byte_size' => $byteSize,
                    'mime_type' => $mimeType,
                    'width' => $width,
                    'height' => $height,
                    'verified_at' => now(),
                    'updated_by_user_id' => $actor->getKey(),
                ]);

            $profile = [
                $profileField => $media->getKey(),
                'updated_at' => now(),
            ];

            if ($owner instanceof Organization) {
                OrganizationProfile::query()
                    ->where('organization_id', $owner->getKey())
                    ->update($profile);

                return;
            }

            ArtistProfile::query()
                ->where('artist_id', $owner->getKey())
                ->update($profile);
        });

        return $media->forceFill([
            'status' => 'ready',
            'storage_disk' => $bucket,
            'storage_key' => $objectKey,
            'active_upload_id' => $upload->getKey(),
            'byte_size' => $byteSize,
            'mime_type' => $mimeType,
            'width' => $width,
            'height' => $height,
            'verified_at' => now(),
            'updated_by_user_id' => $actor->getKey(),
        ]);
    }

    /** @return array{0: string, 1: string, 2: int, 3: int, 4: int, 5: string} */
    private function validatedImage(UploadedFile $file): array
    {
        $body = $file->get();
        if (! is_string($body) || $body === '') {
            throw ValidationException::withMessages(['image' => ['The uploaded image is empty.']]);
        }

        $byteSize = strlen($body);
        $limits = config('media.limits.image');
        if ($byteSize > $limits['bytes']) {
            throw ValidationException::withMessages(['image' => ['The uploaded image is too large.']]);
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
        if (! is_string($mimeType) || ! in_array($mimeType, $limits['mime_types'], true)) {
            throw ValidationException::withMessages(['image' => ['The uploaded image MIME type is not allowed.']]);
        }

        $dimensions = @getimagesizefromstring($body);
        if ($dimensions === false || ($dimensions['mime'] ?? null) !== $mimeType) {
            throw ValidationException::withMessages(['image' => ['The uploaded file is not a valid image with matching metadata.']]);
        }

        [$width, $height] = $dimensions;
        if ($width > $limits['max_width'] || $height > $limits['max_height'] || ($width * $height) > $limits['max_pixels']) {
            throw ValidationException::withMessages(['image' => ['The uploaded image dimensions exceed the allowed limit.']]);
        }

        return [$body, $mimeType, $byteSize, $width, $height, hash('sha256', $body)];
    }

    private function objectKey(Organization|Artist $owner, Media $media, string $mimeType): string
    {
        $scope = $owner instanceof Organization
            ? 'organizations/'.$owner->public_id
            : 'artists/'.$owner->public_id;

        return "{$scope}/{$media->public_id}/v1/original.{$this->extension($mimeType)}";
    }

    private function extension(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default => 'bin',
        };
    }
}
