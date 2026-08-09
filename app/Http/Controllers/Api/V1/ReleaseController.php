<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReleaseIndexRequest;
use App\Http\Requests\Api\V1\Releases\StoreReleaseRequest;
use App\Http\Requests\Api\V1\Releases\UpdateReleaseRequest;
use App\Http\Resources\ReleaseResource;
use App\Http\Responses\ApiResponse;
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
        $query = Release::query()->with($this->includes())->orderByDesc('public_id');
        if (! $request->user()->is_superadmin) {
            $userId = $request->user()->getKey();
            $query->where(function ($owners) use ($userId): void {
                $owners->whereHas('organization', fn ($organization) => $organization
                    ->where('status', 'active')->whereHas('memberships', fn ($memberships) => $memberships->where('user_id', $userId)->where('status', MembershipStatus::Active->value)))
                    ->orWhereHas('ownerArtist', fn ($artist) => $artist->where('status', 'active')->where(function ($access) use ($userId): void {
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

        $payload = $pagination->paginate(
            $query,
            $request,
            fn (Release $release): array => (new ReleaseResource($release))->resolve($request),
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
        return ['organization', 'ownerArtist', 'coverMedia', 'artistLinks.artist.profile', 'editorAssignments.user.profile', 'tracks.pages.blocks', 'tracks.streamingLinks', 'tracks.credits.contributor', 'pages.blocks', 'streamingLinks', 'credits.contributor'];
    }
}
