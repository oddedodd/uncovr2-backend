<?php

namespace App\Services\Media;

use App\Contracts\MediaStorage;
use App\Data\StoredObject;
use App\Models\Media;
use App\Models\MediaUpload;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MediaUploadService
{
    public function __construct(private readonly MediaStorage $storage) {}

    /** @return array{upload: MediaUpload, signed: array{url: string, token: string}} */
    public function request(Media $media, User $actor): array
    {
        $limits = config("media.limits.{$media->kind}");
        if (! is_array($limits) || ! in_array($media->mime_type, $limits['mime_types'], true)) {
            throw ValidationException::withMessages(['mime_type' => ['This MIME type is not allowed for the selected media kind.']]);
        }

        $upload = DB::transaction(function () use ($media, $actor, $limits): MediaUpload {
            $locked = Media::query()->lockForUpdate()->findOrFail($media->getKey());
            $generation = ((int) $locked->uploads()->max('generation')) + 1;
            $extension = $this->extension($locked->mime_type);
            $owner = $locked->organization_id ? 'organizations/'.$locked->organization->public_id : 'artists/'.$locked->artist->public_id;
            $path = "{$owner}/{$locked->public_id}/v{$generation}/original.{$extension}";

            return $locked->uploads()->create([
                'generation' => $generation,
                'bucket' => config('media.private_bucket'),
                'object_key' => $path,
                'expected_mime_type' => $locked->mime_type,
                'maximum_byte_size' => $limits['bytes'],
                'requested_by_user_id' => $actor->getKey(),
                'expires_at' => now()->addSeconds(config('media.upload_ttl_seconds')),
            ]);
        });

        try {
            $signed = $this->storage->createSignedUpload($upload->bucket, $upload->object_key);
        } catch (\Throwable $exception) {
            $upload->update(['status' => 'failed']);
            throw $exception;
        }

        return ['upload' => $upload, 'signed' => $signed];
    }

    public function complete(Media $media, MediaUpload $upload, User $actor): Media
    {
        if ($upload->media_id !== $media->getKey() || $upload->status !== 'requested' || $upload->expires_at->isPast()) {
            throw ValidationException::withMessages(['upload' => ['This upload request is invalid or expired.']]);
        }
        $object = $this->storage->inspect($upload->bucket, $upload->object_key);
        $errors = [];
        if ($object->mimeType !== $upload->expected_mime_type) {
            $errors['mime_type'][] = 'The uploaded file MIME type does not match the request.';
        }
        if ($object->byteSize < 1 || $object->byteSize > $upload->maximum_byte_size) {
            $errors['byte_size'][] = 'The uploaded file size is outside the allowed limit.';
        }
        $width = $height = null;
        if ($errors === []) {
            $object = $this->storage->inspect($upload->bucket, $upload->object_key, true);
            $detectedMime = $object->body === null ? false : (new \finfo(FILEINFO_MIME_TYPE))->buffer($object->body);
            if (! is_string($detectedMime) || $detectedMime !== $upload->expected_mime_type) {
                $errors['mime_type'][] = 'The uploaded file contents do not match the requested MIME type.';
            } elseif ($media->kind === 'image') {
                $dimensions = @getimagesizefromstring($object->body);
                if ($dimensions === false || ($dimensions['mime'] ?? null) !== $detectedMime) {
                    $errors['image'][] = 'The uploaded file is not a valid image with matching metadata.';
                } else {
                    [$width, $height] = $dimensions;
                    $limits = config('media.limits.image');
                    if ($width > $limits['max_width'] || $height > $limits['max_height'] || ($width * $height) > $limits['max_pixels']) {
                        $errors['image'][] = 'The uploaded image dimensions exceed the allowed limit.';
                    }
                }
            }
            if ($errors === []) {
                $object = new StoredObject($detectedMime, $object->byteSize, $object->body, hash('sha256', $object->body));
            }
        }
        if ($errors !== []) {
            $upload->update(['status' => 'failed']);
            try {
                $this->storage->delete($upload->bucket, $upload->object_key);
            } catch (\Throwable) {
            }
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($media, $upload, $actor, $object, $width, $height): void {
            $locked = Media::query()->lockForUpdate()->findOrFail($media->getKey());
            $pending = MediaUpload::query()->lockForUpdate()->findOrFail($upload->getKey());
            if ($pending->status !== 'requested') {
                throw ValidationException::withMessages(['upload' => ['This upload was already completed.']]);
            }
            $previous = $locked->activeUpload;
            $pending->update([
                'status' => 'active', 'actual_byte_size' => $object->byteSize, 'actual_mime_type' => $object->mimeType,
                'width' => $width, 'height' => $height, 'checksum_sha256' => $object->checksum,
                'verified_at' => now(), 'activated_at' => now(),
            ]);
            if ($previous) {
                $previous->update(['status' => 'superseded', 'superseded_at' => now()]);
            }
            $locked->update([
                'status' => 'ready', 'storage_disk' => $pending->bucket, 'storage_key' => $pending->object_key,
                'active_upload_id' => $pending->getKey(), 'byte_size' => $object->byteSize,
                'mime_type' => $object->mimeType, 'width' => $width, 'height' => $height,
                'verified_at' => now(), 'updated_by_user_id' => $actor->getKey(),
            ]);

        });

        return $media->fresh();
    }

    public function delete(Media $media): void
    {
        if ($media->storage_disk && $media->storage_key) {
            $this->storage->delete($media->storage_disk, $media->storage_key);
        }
        $media->delete();
    }

    private function extension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif',
            'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/wav', 'audio/x-wav' => 'wav', 'audio/flac' => 'flac',
            'video/mp4' => 'mp4', 'video/webm' => 'webm', 'application/pdf' => 'pdf',
            default => 'bin',
        };
    }
}
