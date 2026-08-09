<?php

namespace App\Services\Listeners;

use App\Models\Artist;
use App\Models\Release;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;

final class ListenerTargetResolver
{
    public function artist(string $publicId): Artist
    {
        return Artist::query()->with('profile')->where('public_id', $publicId)->where('status', 'active')
            ->whereHas('releaseLinks.release', fn (Builder $release) => $this->visibleRelease($release))->firstOrFail();
    }

    public function release(string $publicId): Release
    {
        return $this->visibleRelease(Release::query()->with('activePublication')->where('public_id', $publicId))->firstOrFail();
    }

    public function track(string $publicId): Track
    {
        return Track::query()->with('release.activePublication')->where('public_id', $publicId)->whereHas('release', fn (Builder $release) => $this->visibleRelease($release))->firstOrFail();
    }

    public function visibleRelease(Builder $release): Builder
    {
        return $release->where('status', 'published')->whereHas('publications', fn (Builder $publication) => $publication->whereNull('withdrawn_at'))
            ->where(function (Builder $owner): void {
                $owner->whereHas('organization', fn (Builder $organization) => $organization->where('status', 'active'))
                    ->orWhereHas('ownerArtist', fn (Builder $artist) => $artist->where('status', 'active'));
            })->whereHas('artistLinks', fn (Builder $link) => $link->where('is_primary', true)->whereHas('artist', fn (Builder $artist) => $artist->where('status', 'active')));
    }

    public function releaseSummary(Release $release): array
    {
        $release->loadMissing('activePublication');

        return ['id' => $release->public_id, 'title' => $release->activePublication->title];
    }

    public function trackSummary(Track $track): array
    {
        $track->loadMissing('release.activePublication');
        $snapshot = collect($track->release->activePublication->snapshot['tracks'] ?? [])->firstWhere('id', $track->public_id);

        return ['id' => $track->public_id, 'title' => $snapshot['title'], 'release_id' => $track->release->public_id];
    }
}
