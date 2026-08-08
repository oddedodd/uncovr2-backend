<?php

namespace App\Services\Releases;

use App\Models\Artist;
use App\Models\ContentBlock;
use App\Models\Credit;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Page;
use App\Models\Release;
use App\Models\StreamingLink;
use App\Models\Track;
use App\Models\User;
use App\Services\Authorization\ScopeAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReleaseService
{
    public function __construct(
        private readonly ReleaseScopeResolver $scopeResolver,
        private readonly ScopeAccess $access,
        private readonly ReleaseActivityLogger $activity,
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

        $cover = null;
        if ($data['cover_media_id'] ?? null) {
            $cover = Media::query()->where('public_id', $data['cover_media_id'])->first();
            if (! $cover) {
                throw ValidationException::withMessages(['cover_media_id' => ['The selected media is invalid.']]);
            }
        }

        return DB::transaction(function () use ($actor, $data, $owner, $primaryArtist, $cover): Release {
            $release = Release::query()->create([
                'organization_id' => $owner instanceof Organization ? $owner->getKey() : null,
                'artist_id' => $owner instanceof Artist ? $owner->getKey() : null,
                'type' => $data['type'], 'title' => $data['title'],
                'subtitle' => $data['subtitle'] ?? null, 'description' => $data['description'] ?? null,
                'release_date' => $data['release_date'] ?? null, 'upc' => $data['upc'] ?? null,
                'cover_media_id' => $cover?->getKey(),
                'created_by_user_id' => $actor->getKey(), 'updated_by_user_id' => $actor->getKey(),
            ]);
            if ($cover) {
                $this->scopeResolver->assertSameOwner($release, $cover, 'cover_media_id');
            }
            $release->artistLinks()->create(['artist_id' => $primaryArtist->getKey(), 'is_primary' => true, 'position' => 1]);
            $release->editorAssignments()->create(['user_id' => $actor->getKey(), 'granted_by_user_id' => $actor->getKey()]);
            $this->activity->record($release, $actor, 'release.created', $release);

            return $release;
        });
    }

    public function update(Release $release, User $actor, array $data): Release
    {
        $auditData = $data;
        if (isset($data['cover_media_id'])) {
            $media = $data['cover_media_id'] ? Media::query()->where('public_id', $data['cover_media_id'])->first() : null;
            if ($data['cover_media_id'] && ! $media) {
                throw ValidationException::withMessages(['cover_media_id' => ['The selected media is invalid.']]);
            }
            if ($media) {
                $this->scopeResolver->assertSameOwner($release, $media, 'cover_media_id');
            }
            $data['cover_media_id'] = $media?->getKey();
        }
        $release->update([...$data, 'updated_by_user_id' => $actor->getKey()]);
        $changedKeys = array_keys($release->getChanges());
        $changes = collect($auditData)->only($changedKeys)->all();
        if ($changes) {
            $this->activity->record($release, $actor, 'release.updated', $release, $changes);
        }

        return $release;
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
