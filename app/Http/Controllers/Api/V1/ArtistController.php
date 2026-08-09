<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProtectedScopeIndexRequest;
use App\Http\Requests\Api\V1\StoreArtistRequest;
use App\Http\Requests\Api\V1\UpdateArtistRequest;
use App\Http\Requests\Api\V1\UpdateScopeStatusRequest;
use App\Http\Resources\ArtistResource;
use App\Http\Responses\ApiResponse;
use App\Models\Artist;
use App\Services\Api\CursorPagination;
use App\Services\Artists\ArtistService;
use App\Services\Auth\SecurityAuditLogger;
use App\Services\PublicApi\PublicCatalogCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class ArtistController extends Controller
{
    public function index(ProtectedScopeIndexRequest $request, CursorPagination $pagination): JsonResponse
    {
        $query = Artist::query()
            ->with('profile')
            ->orderByDesc('public_id');

        if (! $request->user()->is_superadmin) {
            $query->where('status', 'active')->where(function ($scope) use ($request): void {
                $scope->whereHas('memberships', fn ($memberships) => $memberships
                    ->where('user_id', $request->user()->getKey())
                    ->where('status', MembershipStatus::Active->value))
                    ->orWhereHas('organizationRelationships', fn ($relationships) => $relationships
                        ->whereNull('ended_at')
                        ->whereHas('organization.memberships', fn ($memberships) => $memberships
                            ->where('user_id', $request->user()->getKey())
                            ->where('status', MembershipStatus::Active->value)));
            });
        }

        if ($request->filled('filter.status')) {
            $query->where('status', $request->string('filter.status')->toString());
        }

        if ($request->filled('filter.search')) {
            $pattern = '%'.trim($request->string('filter.search')->toString()).'%';
            $query->where(function ($search) use ($pattern): void {
                $search->whereLike('public_id', $pattern)
                    ->orWhereHas('profile', fn ($profile) => $profile->whereLike('name', $pattern));
            });
        }

        $payload = $pagination->paginate(
            $query,
            $request,
            fn (Artist $artist): array => (new ArtistResource($artist))->resolve($request),
        );

        return response()->json($payload);
    }

    public function store(StoreArtistRequest $request, ArtistService $service): JsonResponse
    {
        $artist = $service->create($request->user(), $request->validated());

        return ApiResponse::success((new ArtistResource($artist))->resolve(), 201);
    }

    public function show(Artist $artist): JsonResponse
    {
        Gate::authorize('view', $artist);

        return ApiResponse::success((new ArtistResource($artist->load('profile')))->resolve());
    }

    public function update(UpdateArtistRequest $request, Artist $artist, PublicCatalogCache $cache): JsonResponse
    {
        Gate::authorize('update', $artist);
        $artist->profile()->update($request->validated());
        $cache->invalidate();

        return ApiResponse::success((new ArtistResource($artist->load('profile')))->resolve());
    }

    public function updateStatus(UpdateScopeStatusRequest $request, Artist $artist, SecurityAuditLogger $audit, PublicCatalogCache $cache): JsonResponse
    {
        Gate::authorize('suspend', $artist);
        $status = $request->string('status')->toString();
        $artist->update(['status' => $status, 'suspended_at' => $status === 'suspended' ? now() : null]);
        $audit->record('artist.status_changed', $request->user(), $request, metadata: ['artist_id' => $artist->public_id, 'status' => $status]);
        $cache->invalidate();

        return ApiResponse::success((new ArtistResource($artist->load('profile')))->resolve());
    }
}
