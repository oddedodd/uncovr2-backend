<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReleaseIndexRequest;
use App\Http\Requests\Api\V1\Releases\StoreReleaseRequest;
use App\Http\Requests\Api\V1\Releases\UpdateReleaseRequest;
use App\Http\Resources\ReleaseResource;
use App\Http\Resources\ReleaseSummaryResource;
use App\Http\Responses\ApiResponse;
use App\Models\Release;
use App\Services\Api\CursorPagination;
use App\Services\Releases\ReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ReleaseController extends Controller
{
    public function index(ReleaseIndexRequest $request, CursorPagination $pagination): JsonResponse
    {
        $query = Release::query()
            ->select([
                'releases.*',
                'organizations.public_id as owner_organization_public_id',
                'owner_artists.public_id as owner_artist_public_id',
                'cover_media.public_id as cover_media_public_id',
                'cover_media.status as cover_media_status',
                'cover_media.mime_type as cover_media_mime_type',
                'cover_media.width as cover_media_width',
                'cover_media.height as cover_media_height',
            ])
            ->leftJoin('organizations', 'organizations.id', '=', 'releases.organization_id')
            ->leftJoin('artists as owner_artists', 'owner_artists.id', '=', 'releases.artist_id')
            ->leftJoin('media as cover_media', 'cover_media.id', '=', 'releases.cover_media_id')
            ->orderByDesc('releases.created_at')
            ->orderByDesc('releases.id');

        if (! $request->user()->is_superadmin) {
            $userId = $request->user()->getKey();
            $query->where(function ($owners) use ($userId): void {
                $owners->whereHas('organization', fn ($organization) => $organization
                    ->where('status', 'active')->whereHas('memberships', fn ($memberships) => $memberships->where('user_id', $userId)->where('status', MembershipStatus::Active->value)))
                    ->orWhereHas('ownerArtist', fn ($artist) => $artist->where('status', 'active')->where(function ($access) use ($userId): void {
                        $access->whereHas('memberships', fn ($memberships) => $memberships->where('user_id', $userId)->where('status', MembershipStatus::Active->value))
                            ->orWhereHas('organizationRelationships', fn ($relations) => $relations->whereNull('ended_at')->whereHas('organization.memberships', fn ($memberships) => $memberships->where('user_id', $userId)->where('status', MembershipStatus::Active->value)));
                    }))
                    ->orWhereHas('artistLinks.artist', fn ($artist) => $artist->where('status', 'active')->where(function ($access) use ($userId): void {
                        $access->whereHas('memberships', fn ($memberships) => $memberships->where('user_id', $userId)->where('status', MembershipStatus::Active->value))
                            ->orWhereHas('organizationRelationships', fn ($relations) => $relations->whereNull('ended_at')->whereHas('organization.memberships', fn ($memberships) => $memberships->where('user_id', $userId)->where('status', MembershipStatus::Active->value)));
                    }));
            });
        }

        if ($request->filled('filter.status')) {
            $query->where('releases.status', $request->string('filter.status')->toString());
        }

        if ($request->filled('filter.type')) {
            $query->where('releases.type', $request->string('filter.type')->toString());
        }

        if ($request->filled('filter.search')) {
            $pattern = '%'.trim($request->string('filter.search')->toString()).'%';
            $query->where(function ($search) use ($pattern): void {
                $search->whereLike('releases.public_id', $pattern)
                    ->orWhereLike('releases.title', $pattern)
                    ->orWhereLike('releases.subtitle', $pattern)
                    ->orWhereLike('releases.upc', $pattern)
                    ->orWhereHas('artistLinks.artist.profile', fn ($profile) => $profile->whereLike('name', $pattern))
                    ->orWhereHas('organization.profile', fn ($profile) => $profile->whereLike('name', $pattern))
                    ->orWhereHas('ownerArtist.profile', fn ($profile) => $profile->whereLike('name', $pattern));
            });
        }

        if ($request->filled('filter.artist_id')) {
            $artistPublicId = $request->string('filter.artist_id')->toString();

            $query->where(function ($artists) use ($artistPublicId): void {
                $artists
                    ->whereIn('releases.artist_id', DB::table('artists')
                        ->select('id')
                        ->where('public_id', $artistPublicId))
                    ->orWhereExists(function ($exists) use ($artistPublicId): void {
                        $exists
                            ->selectRaw('1')
                            ->from('release_artists')
                            ->join('artists', 'artists.id', '=', 'release_artists.artist_id')
                            ->whereColumn('release_artists.release_id', 'releases.id')
                            ->where('artists.public_id', $artistPublicId);
                    });
            });
        }

        if ($request->filled('filter.owner_type') || $request->filled('filter.owner_id')) {
            $ownerType = $request->string('filter.owner_type')->toString();
            $ownerId = $request->string('filter.owner_id')->toString();

            if ($ownerType === 'organization') {
                $query->whereIn('releases.organization_id', DB::table('organizations')
                    ->select('id')
                    ->where('public_id', $ownerId));
            } else {
                $query->whereIn('releases.artist_id', DB::table('artists')
                    ->select('id')
                    ->where('public_id', $ownerId));
            }
        }

        $page = $pagination->page($query, $request);
        $releaseIds = $page['items']->map(fn (Release $release): int => $release->getKey())->all();
        $artists = $this->summaryArtists($releaseIds);
        $editorUserIds = $this->summaryEditorUserIds($releaseIds);

        return response()->json([
            'data' => $page['items']
                ->map(fn (Release $release): array => (new ReleaseSummaryResource(
                    $release,
                    $artists[$release->getKey()] ?? [],
                    $editorUserIds[$release->getKey()] ?? [],
                ))->resolve($request))
                ->all(),
            'meta' => $page['meta'],
        ]);
    }

    public function store(StoreReleaseRequest $request, ReleaseService $service): JsonResponse
    {
        $release = $service->create($request->user(), $request->validated())->load($this->includes());

        return ApiResponse::success((new ReleaseResource($release))->resolve(), 201);
    }

    public function show(Release $release): JsonResponse
    {
        Gate::authorize('view', $release);

        return ApiResponse::success((new ReleaseResource($release->loadMissing($this->includes())))->resolve());
    }

    public function update(UpdateReleaseRequest $request, Release $release, ReleaseService $service): JsonResponse
    {
        Gate::authorize('update', $release);

        return ApiResponse::success((new ReleaseResource($service->update($release, $request->user(), $request->validated())->load($this->includes())))->resolve());
    }

    public function destroy(Request $request, Release $release, ReleaseService $service): JsonResponse
    {
        Gate::authorize('delete', $release);
        $service->delete($release, $request->user());

        return ApiResponse::success(['message' => 'Release deleted.']);
    }

    private function includes(): array
    {
        return ['organization', 'ownerArtist', 'coverMedia', 'artistLinks.artist.profile', 'editorAssignments.user', 'pages.blocks', 'streamingLinks', 'credits.contributor'];
    }

    /**
     * @param  array<int, int>  $releaseIds
     * @return array<int, array<int, array{artist_id: string, name: string, is_primary: bool, position: int}>>
     */
    private function summaryArtists(array $releaseIds): array
    {
        if ($releaseIds === []) {
            return [];
        }

        return DB::table('release_artists')
            ->join('artists', 'artists.id', '=', 'release_artists.artist_id')
            ->join('artist_profiles', 'artist_profiles.artist_id', '=', 'artists.id')
            ->whereIn('release_artists.release_id', $releaseIds)
            ->orderBy('release_artists.position')
            ->get([
                'release_artists.release_id',
                'artists.public_id as artist_id',
                'artist_profiles.name',
                'release_artists.is_primary',
                'release_artists.position',
            ])
            ->groupBy('release_id')
            ->map(fn (Collection $rows): array => $rows
                ->map(fn (object $row): array => [
                    'artist_id' => $row->artist_id,
                    'name' => $row->name,
                    'is_primary' => (bool) $row->is_primary,
                    'position' => (int) $row->position,
                ])
                ->all())
            ->all();
    }

    /**
     * @param  array<int, int>  $releaseIds
     * @return array<int, array<int, string>>
     */
    private function summaryEditorUserIds(array $releaseIds): array
    {
        if ($releaseIds === []) {
            return [];
        }

        return DB::table('release_editors')
            ->join('users', 'users.id', '=', 'release_editors.user_id')
            ->whereIn('release_editors.release_id', $releaseIds)
            ->orderBy('release_editors.id')
            ->get(['release_editors.release_id', 'users.public_id'])
            ->groupBy('release_id')
            ->map(fn (Collection $rows): array => $rows
                ->map(fn (object $row): string => $row->public_id)
                ->all())
            ->all();
    }
}
