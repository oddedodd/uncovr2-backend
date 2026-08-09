<?php

namespace App\Services\Media;

use App\Models\Artist;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Release;
use Illuminate\Validation\ValidationException;

final class MediaAttachmentValidator
{
    public function resolveImage(?string $publicId, Organization|Artist|Release $owner, string $field): ?Media
    {
        if ($publicId === null) {
            return null;
        }

        $media = Media::query()
            ->where('public_id', $publicId)
            ->lockForUpdate()
            ->first();

        if ($media === null) {
            $this->invalid($field, 'The selected media does not exist.');
        }

        if ($media->kind !== 'image') {
            $this->invalid($field, 'The selected media must be an image.');
        }

        if ($media->status !== 'ready') {
            $this->invalid($field, 'The selected image upload must be completed and ready.');
        }

        if (! $this->hasSameOwner($media, $owner)) {
            $this->invalid($field, 'The selected image must belong to the same owner scope.');
        }

        return $media;
    }

    private function hasSameOwner(Media $media, Organization|Artist|Release $owner): bool
    {
        if ($owner instanceof Organization) {
            return $media->organization_id === $owner->getKey() && $media->artist_id === null;
        }

        if ($owner instanceof Artist) {
            return $media->artist_id === $owner->getKey() && $media->organization_id === null;
        }

        return $owner->organization_id !== null
            ? $media->organization_id === $owner->organization_id && $media->artist_id === null
            : $media->artist_id === $owner->artist_id && $media->organization_id === null;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
