<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProtectedScopeIndexRequest;
use App\Http\Requests\Api\V1\StoreArtistRequest;
use App\Http\Requests\Api\V1\StoreProfileImageRequest;
use App\Http\Requests\Api\V1\UpdateArtistRequest;
use App\Http\Requests\Api\V1\UpdateScopeStatusRequest;
use App\Http\Resources\ArtistResource;
use App\Http\Responses\ApiResponse;
use App\Models\Artist;
use App\Services\Api\CursorPagination;
use App\Services\Artists\ArtistService;
use App\Services\Auth\SecurityAuditLogger;
use App\Services\Media\MediaAttachmentValidator;
use App\Services\Media\ProfileImageUploadService;
use App\Services\PublicApi\PublicCatalogCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ArtistController extends Controller
{
    public function index(ProtectedScopeIndexRequest $request, CursorPagination $pagination): JsonResponse
    {
        $query = Artist::query()
            ->with(['profile.logoMedia', 'profile.imageMedia'])
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
        $validated = $request->validated();
        $creatorRole = $validated['creator_role'] ?? null;
        unset($validated['creator_role']);
        $artist = $service->create($request->user(), $validated, $creatorRole);

        return ApiResponse::success((new ArtistResource($artist))->resolve(), 201);
    }

    public function show(Artist $artist): JsonResponse
    {
        Gate::authorize('view', $artist);

        return ApiResponse::success((new ArtistResource($artist->load(['profile.logoMedia', 'profile.imageMedia'])))->resolve());
    }

    public function update(UpdateArtistRequest $request, Artist $artist, MediaAttachmentValidator $mediaValidator, PublicCatalogCache $cache): JsonResponse
    {
        Gate::authorize('update', $artist);
        $data = $request->validated();
        if (array_key_exists('logo_media_id', $data) || array_key_exists('image_media_id', $data)) {
            Gate::authorize('manageMedia', $artist);
        }
        DB::transaction(function () use ($artist, $mediaValidator, &$data): void {
            $profile = $artist->profile()->lockForUpdate()->firstOrFail();
            foreach (['logo_media_id', 'image_media_id'] as $field) {
                if (array_key_exists($field, $data)) {
                    $media = $mediaValidator->resolveImage($data[$field], $artist, $field);
                    $data[$field] = $media?->getKey();
                }
            }
            $profile->update($data);
        });
        $cache->invalidate();

        return ApiResponse::success((new ArtistResource($artist->load(['profile.logoMedia', 'profile.imageMedia'])))->resolve());
    }

    public function uploadLogo(StoreProfileImageRequest $request, Artist $artist, ProfileImageUploadService $uploads, PublicCatalogCache $cache): JsonResponse
    {
        Gate::authorize('manageMedia', $artist);
        $uploads->uploadArtistLogo($artist, $request->file('image'), $request->user());
        $cache->invalidate();

        return ApiResponse::success((new ArtistResource($artist))->resolve(), 201);
    }

    public function uploadImage(StoreProfileImageRequest $request, Artist $artist, ProfileImageUploadService $uploads, PublicCatalogCache $cache): JsonResponse
    {
        Gate::authorize('manageMedia', $artist);
        $uploads->uploadArtistImage($artist, $request->file('image'), $request->user());
        $cache->invalidate();

        return ApiResponse::success((new ArtistResource($artist))->resolve(), 201);
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
