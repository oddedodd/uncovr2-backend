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
use App\Models\Artist;
use App\Models\Organization;
use App\Models\Release;
use App\Services\Api\CursorPagination;
use App\Services\Releases\ReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ReleaseController extends Controller
{
    public function index(ReleaseIndexRequest $request, CursorPagination $pagination): JsonResponse
    {
        $query = Release::query()
            ->with($this->summaryIncludes())
            ->orderByDesc('created_at')
            ->orderByDesc('id');

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
            $query->where('status', $request->string('filter.status')->toString());
        }

        if ($request->filled('filter.type')) {
            $query->where('type', $request->string('filter.type')->toString());
        }

        if ($request->filled('filter.search')) {
            $pattern = '%'.trim($request->string('filter.search')->toString()).'%';
            $query->where(function ($search) use ($pattern): void {
                $search->whereLike('public_id', $pattern)
                    ->orWhereLike('title', $pattern)
                    ->orWhereLike('subtitle', $pattern)
                    ->orWhereLike('upc', $pattern)
                    ->orWhereHas('artistLinks.artist.profile', fn ($profile) => $profile->whereLike('name', $pattern))
                    ->orWhereHas('organization.profile', fn ($profile) => $profile->whereLike('name', $pattern))
                    ->orWhereHas('ownerArtist.profile', fn ($profile) => $profile->whereLike('name', $pattern));
            });
        }

        if ($request->filled('filter.artist_id')) {
            $artist = Artist::query()
                ->where('public_id', $request->string('filter.artist_id')->toString())
                ->firstOrFail();

            $query->where(function ($artists) use ($artist): void {
                $artists->where('artist_id', $artist->getKey())
                    ->orWhereHas('artistLinks', fn ($links) => $links->where('artist_id', $artist->getKey()));
            });
        }

        if ($request->filled('filter.owner_type') || $request->filled('filter.owner_id')) {
            $ownerType = $request->string('filter.owner_type')->toString();
            $ownerId = $request->string('filter.owner_id')->toString();

            if ($ownerType === 'organization') {
                $organization = Organization::query()->where('public_id', $ownerId)->firstOrFail();
                $query->where('organization_id', $organization->getKey());
            } else {
                $artist = Artist::query()->where('public_id', $ownerId)->firstOrFail();
                $query->where('artist_id', $artist->getKey());
            }
        }

        $payload = $pagination->paginate(
            $query,
            $request,
            fn (Release $release): array => (new ReleaseSummaryResource($release))->resolve($request),
        );

        return response()->json($payload);
    }

    public function store(StoreReleaseRequest $request, ReleaseService $service): JsonResponse
    {
        $release = $service->create($request->user(), $request->validated())->load($this->includes());

        return ApiResponse::success((new ReleaseResource($release))->resolve(), 201);
    }

    public function show(Release $release): JsonResponse
    {
        Gate::authorize('view', $release);

        return ApiResponse::success((new ReleaseResource($release->load($this->includes())))->resolve());
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
        return ['organization', 'ownerArtist', 'coverMedia', 'artistLinks.artist.profile', 'editorAssignments.user.profile', 'pages.blocks', 'streamingLinks', 'credits.contributor'];
    }

    private function summaryIncludes(): array
    {
        return ['organization', 'ownerArtist', 'coverMedia', 'artistLinks.artist.profile', 'editorAssignments.user'];
    }
}
