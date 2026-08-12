<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\MediaStorage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BatchMediaDownloadRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Media;
use App\Models\MediaUpload;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class MediaUploadController extends Controller
{
    public function store(Request $request, Media $media, MediaUploadService $service): JsonResponse
    {
        Gate::authorize('update', $media);
        $result = $service->request($media, $request->user());
        $upload = $result['upload'];

        return ApiResponse::success([
            'id' => $upload->public_id, 'method' => 'PUT', 'url' => $result['signed']['url'],
            'token' => $result['signed']['token'], 'path' => $upload->object_key,
            'mime_type' => $upload->expected_mime_type, 'maximum_byte_size' => $upload->maximum_byte_size,
            'expires_at' => $upload->expires_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ], 201);
    }

    public function complete(Request $request, Media $media, MediaUpload $mediaUpload, MediaUploadService $service): JsonResponse
    {
        Gate::authorize('update', $media);
        $media = $service->complete($media, $mediaUpload, $request->user());

        return ApiResponse::success(['id' => $media->public_id, 'status' => $media->status, 'mime_type' => $media->mime_type, 'byte_size' => $media->byte_size, 'width' => $media->width, 'height' => $media->height, 'verified_at' => $media->verified_at?->utc()->format('Y-m-d\TH:i:s.v\Z')]);
    }

    public function download(Media $media, MediaStorage $storage): JsonResponse
    {
        Gate::authorize('view', $media);
        abort_unless($media->status === 'ready' && $media->storage_disk && $media->storage_key, 409);
        $expiresIn = config('media.download_ttl_seconds');

        return ApiResponse::success(['url' => $storage->createSignedDownload($media->storage_disk, $media->storage_key, $expiresIn), 'expires_in' => $expiresIn]);
    }

    public function downloads(BatchMediaDownloadRequest $request, MediaStorage $storage): JsonResponse
    {
        $ids = $request->validated('media_ids');
        $mediaById = Media::query()->with('organization', 'artist')->whereIn('public_id', $ids)->get()->keyBy('public_id');

        if ($mediaById->count() !== count($ids)) {
            throw ValidationException::withMessages(['media_ids' => ['One or more media records do not exist.']]);
        }

        $expiresIn = config('media.download_ttl_seconds');

        // Authorize and group first, then sign each bucket in a single call. Signing
        // per media id costs one HTTPS round trip each and does not scale.
        $pathsByBucket = [];
        foreach ($ids as $id) {
            $media = $mediaById->get($id);
            Gate::authorize('view', $media);
            if ($media->status !== 'ready' || ! $media->storage_disk || ! $media->storage_key) {
                throw ValidationException::withMessages(['media_ids' => ["Media {$id} is not ready for download."]]);
            }
            $pathsByBucket[$media->storage_disk][$media->storage_key] = true;
        }

        $urlsByBucket = [];
        foreach ($pathsByBucket as $bucket => $paths) {
            $urlsByBucket[$bucket] = $storage->createSignedDownloads($bucket, array_keys($paths), $expiresIn);
        }

        $items = collect($ids)->map(fn (string $id): array => [
            'media_id' => $id,
            'url' => $urlsByBucket[$mediaById->get($id)->storage_disk][$mediaById->get($id)->storage_key],
        ])->all();

        return ApiResponse::success(['expires_in' => $expiresIn, 'items' => $items]);
    }
}
