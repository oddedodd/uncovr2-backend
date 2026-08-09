<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Releases\StoreMediaRequest;
use App\Http\Requests\Api\V1\Releases\UpdateMediaRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Artist;
use App\Models\ContentBlock;
use App\Models\Media;
use App\Models\Organization;
use App\Services\Media\MediaUploadService;
use App\Services\Releases\ReleaseScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class MediaController extends Controller
{
    public function store(StoreMediaRequest $request, ReleaseScopeResolver $resolver): JsonResponse
    {
        $data = $request->validated();
        $owner = $resolver->resolveOwner($data['owner_type'], $data['owner_id'], $request->user());
        unset($data['owner_type'], $data['owner_id']);
        $media = Media::query()->create([...$data, 'organization_id' => $owner instanceof Organization ? $owner->getKey() : null, 'artist_id' => $owner instanceof Artist ? $owner->getKey() : null, 'created_by_user_id' => $request->user()->getKey(), 'updated_by_user_id' => $request->user()->getKey()]);

        return ApiResponse::success($this->resource($media), 201);
    }

    public function show(Media $media): JsonResponse
    {
        Gate::authorize('view', $media);

        return ApiResponse::success($this->resource($media));
    }

    public function update(UpdateMediaRequest $request, Media $media): JsonResponse
    {
        Gate::authorize('update', $media);
        $media->update([...$request->validated(), 'updated_by_user_id' => $request->user()->getKey()]);

        return ApiResponse::success($this->resource($media));
    }

    public function destroy(Request $request, Media $media, MediaUploadService $service): JsonResponse
    {
        Gate::authorize('delete', $media);
        if ($media->releasesAsCover()->exists()) {
            throw ValidationException::withMessages(['media' => ['Media used as a release cover cannot be deleted.']]);
        }
        if ($media->organizationProfilesAsLogo()->exists()) {
            throw ValidationException::withMessages(['media' => ['Media used as a label logo cannot be deleted.']]);
        }
        if ($media->artistProfilesAsLogo()->exists()) {
            throw ValidationException::withMessages(['media' => ['Media used as an artist logo cannot be deleted.']]);
        }
        if ($media->artistProfilesAsImage()->exists()) {
            throw ValidationException::withMessages(['media' => ['Media used as an artist image cannot be deleted.']]);
        }
        $driver = ContentBlock::query()->getConnection()->getDriverName();
        $payloadExpression = $driver === 'pgsql' ? 'payload::text' : 'payload';
        if (ContentBlock::query()->whereRaw("{$payloadExpression} LIKE ?", ['%'.$media->public_id.'%'])->exists()) {
            throw ValidationException::withMessages(['media' => ['Media referenced by release content cannot be deleted.']]);
        }
        $service->delete($media);

        return ApiResponse::success(['message' => 'Media record deleted.']);
    }

    private function resource(Media $media): array
    {
        return ['id' => $media->public_id, 'owner' => ['type' => $media->organization_id ? 'organization' : 'artist', 'id' => $media->organization?->public_id ?? $media->artist?->public_id], 'kind' => $media->kind, 'status' => $media->status, 'original_filename' => $media->original_filename, 'mime_type' => $media->mime_type, 'byte_size' => $media->byte_size, 'width' => $media->width, 'height' => $media->height, 'verified_at' => $media->verified_at?->utc()->format('Y-m-d\TH:i:s.v\Z'), 'metadata' => $media->metadata];
    }
}
