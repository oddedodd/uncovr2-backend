<?php

namespace App\Services\Releases;

use App\Models\Artist;
use App\Models\ContentBlock;
use App\Models\Credit;
use App\Models\Organization;
use App\Models\Page;
use App\Models\Release;
use App\Models\StreamingLink;
use App\Models\Track;
use App\Models\User;
use App\Services\Authorization\ScopeAccess;
use App\Services\Media\MediaAttachmentValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReleaseService
{
    public function __construct(
        private readonly ReleaseScopeResolver $scopeResolver,
        private readonly ScopeAccess $access,
        private readonly ReleaseActivityLogger $activity,
        private readonly MediaAttachmentValidator $mediaValidator,
    ) {}

    public function create(User $actor, array $data): Release
    {
        $owner = $this->scopeResolver->resolveOwner($data['owner_type'], $data['owner_id'], $actor);
        $primaryArtist = Artist::query()->where('public_id', $data['primary_artist_id'])->first();
        if (! $primaryArtist || (! $actor->is_superadmin && ! $this->access->canViewArtist($actor, $primaryArtist))) {
            throw ValidationException::withMessages(['primary_artist_id' => ['The selected primary artist is invalid.']]);
        }
        if ($owner instanceof Organization && ! $owner->artistRelationships()->where('artist_id', $primaryArtist->getKey())->whereNull('ended_at')->exists()) {
            throw ValidationException::withMessages(['primary_artist_id' => ['The primary artist must have an active relationship with the organization.']]);
        }
        if ($owner instanceof Artist && ! $owner->is($primaryArtist)) {
            throw ValidationException::withMessages(['primary_artist_id' => ['An artist-owned release must use that artist as primary artist.']]);
        }

        return DB::transaction(function () use ($actor, $data, $owner, $primaryArtist): Release {
            $cover = $this->mediaValidator->resolveImage($data['cover_media_id'] ?? null, $owner, 'cover_media_id');
            $release = Release::query()->create([
                'organization_id' => $owner instanceof Organization ? $owner->getKey() : null,
                'artist_id' => $owner instanceof Artist ? $owner->getKey() : null,
                'type' => $data['type'], 'title' => $data['title'],
                'subtitle' => $data['subtitle'] ?? null, 'description' => $data['description'] ?? null,
                'release_date' => $data['release_date'] ?? null, 'upc' => $data['upc'] ?? null,
                'cover_media_id' => $cover?->getKey(),
                'created_by_user_id' => $actor->getKey(), 'updated_by_user_id' => $actor->getKey(),
            ]);
            $release->artistLinks()->create(['artist_id' => $primaryArtist->getKey(), 'is_primary' => true, 'position' => 1]);
            $release->editorAssignments()->create(['user_id' => $actor->getKey(), 'granted_by_user_id' => $actor->getKey()]);
            $this->activity->record($release, $actor, 'release.created', $release);

            return $release;
        });
    }

    public function update(Release $release, User $actor, array $data): Release
    {
        return DB::transaction(function () use ($release, $actor, $data): Release {
            $locked = Release::query()->lockForUpdate()->findOrFail($release->getKey());
            $auditData = $data;
            if (array_key_exists('cover_media_id', $data)) {
                $media = $this->mediaValidator->resolveImage($data['cover_media_id'], $locked, 'cover_media_id');
                $data['cover_media_id'] = $media?->getKey();
            }
            $locked->update([...$data, 'updated_by_user_id' => $actor->getKey()]);
            $changedKeys = array_keys($locked->getChanges());
            $changes = collect($auditData)->only($changedKeys)->all();
            if ($changes) {
                $this->activity->record($locked, $actor, 'release.updated', $locked, $changes);
            }

            return $locked;
        });
    }

    public function delete(Release $release, User $actor): void
    {
        DB::transaction(function () use ($release, $actor): void {
            $locked = Release::query()->lockForUpdate()->findOrFail($release->getKey());
            $trackIds = Track::query()->where('release_id', $locked->getKey())->pluck('id');
            $pageIds = Page::query()->where('release_id', $locked->getKey())->orWhereIn('track_id', $trackIds)->pluck('id');

            $this->activity->record($locked, $actor, 'release.deleted', $locked);
            ContentBlock::query()->whereIn('page_id', $pageIds)->delete();
            StreamingLink::query()->where('release_id', $locked->getKey())->orWhereIn('track_id', $trackIds)->delete();
            Credit::query()->where('release_id', $locked->getKey())->orWhereIn('track_id', $trackIds)->delete();
            Page::query()->whereIn('id', $pageIds)->delete();
            Track::query()->whereIn('id', $trackIds)->delete();
            $locked->delete();
        });
    }
}
