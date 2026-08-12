<?php

namespace App\Services\PublicApi;

use App\Models\Artist;
use App\Models\Organization;
use App\Models\ReleasePublication;
use App\Models\ReleasePublicationTrack;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PublicCatalog
{
    public function labels(Request $request): array
    {
        $query = Organization::query()
            ->select('organizations.*', 'organization_profiles.name as catalog_name')
            ->join('organization_profiles', 'organization_profiles.organization_id', '=', 'organizations.id')
            ->with('profile')
            ->where('organizations.status', 'active')
            ->whereHas('releases', fn (Builder $releases) => $this->applyVisibleRelease($releases));
        $this->searchProfile($query, $request->input('filter.search'), 'organization_profiles', ['name', 'description']);

        return $this->paginate($query->orderBy('catalog_name')->orderBy('organizations.public_id'), $request, fn (Organization $label) => $this->label($label));
    }

    public function labelById(string $publicId): array
    {
        $label = Organization::query()->with('profile')->where('status', 'active')->where('public_id', $publicId)
            ->whereHas('releases', fn (Builder $releases) => $this->applyVisibleRelease($releases))
            ->firstOrFail();

        return $this->label($label, $this->visiblePublications()->whereHas('release', fn (Builder $release) => $release->where('organization_id', $label->id))->latest('published_at')->limit(12)->get());
    }

    public function artists(Request $request): array
    {
        $query = Artist::query()
            ->select('artists.*', 'artist_profiles.name as catalog_name')
            ->join('artist_profiles', 'artist_profiles.artist_id', '=', 'artists.id')
            ->with('profile')
            ->where('artists.status', 'active')
            ->whereHas('releaseLinks.release', fn (Builder $release) => $this->applyVisibleRelease($release));
        $this->searchProfile($query, $request->input('filter.search'), 'artist_profiles', ['name', 'biography']);

        return $this->paginate($query->orderBy('catalog_name')->orderBy('artists.public_id'), $request, fn (Artist $artist) => $this->artist($artist));
    }

    public function artistById(string $publicId): array
    {
        $artist = Artist::query()->with('profile')->where('status', 'active')->where('public_id', $publicId)
            ->whereHas('releaseLinks.release', fn (Builder $release) => $this->applyVisibleRelease($release))->firstOrFail();
        $publications = $this->visiblePublications()->whereHas('release.artistLinks', fn (Builder $links) => $links->where('artist_id', $artist->id))
            ->latest('published_at')->limit(12)->get();

        return $this->artist($artist, $publications);
    }

    public function releases(Request $request, bool $featured = false): array
    {
        $query = $this->visiblePublications();
        $this->searchPublication($query, $request->input('filter.search'));
        if ($featured) {
            $query->join('releases as featured_releases', 'featured_releases.id', '=', 'release_publications.release_id')
                ->select('release_publications.*', 'featured_releases.featured_at as catalog_featured_at')
                ->whereNotNull('featured_releases.featured_at');

            return $this->paginate($query->orderByDesc('catalog_featured_at')->orderByDesc('release_publications.public_id'), $request, fn (ReleasePublication $publication) => $this->releaseSummary($publication));
        }

        return $this->paginate($query->orderByDesc('release_publications.published_at')->orderByDesc('release_publications.public_id'), $request, fn (ReleasePublication $publication) => $this->releaseSummary($publication));
    }

    public function releaseById(string $publicId): array
    {
        return $this->releaseDetail($this->visiblePublications()->whereHas('release', fn (Builder $release) => $release->where('public_id', $publicId))->firstOrFail());
    }

    public function trackById(string $publicId): array
    {
        $track = ReleasePublicationTrack::query()->with('publication')
            ->where('track_public_id', $publicId)
            ->whereHas('publication', fn (Builder $publication) => $this->applyVisiblePublication($publication))
            ->firstOrFail();

        return [
            ...$this->safeTrack($track->snapshot),
            'release' => $this->releaseSummary($track->publication),
        ];
    }

    private function visiblePublications(): Builder
    {
        // The presenters read denormalized publication columns only, so the
        // `release` relation is deliberately not eager loaded here.
        return $this->applyVisiblePublication(ReleasePublication::query());
    }

    private function applyVisiblePublication(Builder $query): Builder
    {
        return $query->whereNull('release_publications.withdrawn_at')->whereNotNull('release_publications.title')->whereHas('release', fn (Builder $release) => $this->applyVisibleRelease($release));
    }

    private function applyVisibleRelease(Builder $release): Builder
    {
        return $release->where('status', 'published')
            ->whereHas('publications', fn (Builder $publications) => $publications->whereNull('withdrawn_at'))
            ->where(function (Builder $owner): void {
                $owner->whereHas('organization', fn (Builder $organization) => $organization->where('status', 'active'))
                    ->orWhereHas('ownerArtist', fn (Builder $artist) => $artist->where('status', 'active'));
            })
            ->whereHas('artistLinks', fn (Builder $link) => $link->where('is_primary', true)->whereHas('artist', fn (Builder $artist) => $artist->where('status', 'active')));
    }

    private function paginate(Builder $query, Request $request, callable $presenter): array
    {
        $encoded = $request->input('page.after', $request->input('page.before'));
        $cursor = is_string($encoded) ? Cursor::fromEncoded($encoded) : null;
        if (is_string($encoded) && ! $cursor) {
            throw ValidationException::withMessages(['page' => ['The pagination cursor is invalid.']]);
        }
        $page = $query->cursorPaginate((int) $request->input('page.size', 25), cursor: $cursor);

        return ['data' => collect($page->items())->map($presenter)->all(), 'meta' => ['pagination' => [
            'per_page' => $page->perPage(), 'next_cursor' => $page->nextCursor()?->encode(),
            'previous_cursor' => $page->previousCursor()?->encode(), 'has_more' => $page->hasMorePages(),
        ]]];
    }

    private function searchPublication(Builder $query, mixed $search): void
    {
        if (! is_string($search) || trim($search) === '') {
            return;
        }
        $search = trim($search);
        if (DB::getDriverName() === 'pgsql') {
            $query->whereRaw("to_tsvector('simple', coalesce(release_publications.search_text, '')) @@ websearch_to_tsquery('simple', ?)", [$search]);
        } else {
            $query->whereRaw('lower(release_publications.search_text) like ?', ['%'.mb_strtolower($search).'%']);
        }
    }

    private function searchProfile(Builder $query, mixed $search, string $table, array $columns): void
    {
        if (! is_string($search) || trim($search) === '') {
            return;
        }
        $search = trim($search);
        if (DB::getDriverName() === 'pgsql') {
            $parts = implode(" || ' ' || ", array_map(fn (string $column) => "coalesce({$table}.{$column}, '')", $columns));
            $query->whereRaw("to_tsvector('simple', {$parts}) @@ websearch_to_tsquery('simple', ?)", [$search]);
        } else {
            $query->where(function (Builder $where) use ($table, $columns, $search): void {
                foreach ($columns as $column) {
                    $where->orWhereRaw("lower({$table}.{$column}) like ?", ['%'.mb_strtolower($search).'%']);
                }
            });
        }
    }

    private function label(Organization $label, ?iterable $publications = null): array
    {
        $result = ['id' => $label->public_id, 'name' => $label->profile->name, 'description' => $label->profile->description, 'website_url' => $label->profile->website_url];

        return $publications === null ? $result : [...$result, 'releases' => collect($publications)->map(fn ($publication) => $this->releaseSummary($publication))->all()];
    }

    private function artist(Artist $artist, ?iterable $publications = null): array
    {
        $result = ['id' => $artist->public_id, 'name' => $artist->profile->name, 'biography' => $artist->profile->biography, 'website_url' => $artist->profile->website_url];

        return $publications === null ? $result : [...$result, 'releases' => collect($publications)->map(fn ($publication) => $this->releaseSummary($publication))->all()];
    }

    private function releaseSummary(ReleasePublication $publication): array
    {
        return [
            'id' => $publication->snapshot['id'],
            'type' => $publication->release_type, 'title' => $publication->title, 'subtitle' => $publication->subtitle,
            'primary_artist_name' => $publication->primary_artist_name,
            'release_date' => $publication->release_date?->format('Y-m-d'), 'cover_url' => $publication->cover_url,
            'published_at' => $publication->published_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    private function releaseDetail(ReleasePublication $publication): array
    {
        $snapshot = $publication->snapshot;

        return [
            ...Arr::only($snapshot, ['id', 'owner', 'type', 'title', 'subtitle', 'description', 'release_date', 'upc', 'artists', 'pages', 'streaming_links', 'credits', 'media']),
            'status' => 'published',
            'cover_url' => $publication->cover_url,
            'tracks' => collect($snapshot['tracks'] ?? [])->map(fn (array $track) => $this->safeTrack($track))->all(),
            'published_at' => $publication->published_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    private function safeTrack(array $track): array
    {
        return Arr::only($track, ['id', 'position', 'title', 'duration_ms', 'isrc', 'is_explicit', 'pages', 'streaming_links', 'credits']);
    }
}
