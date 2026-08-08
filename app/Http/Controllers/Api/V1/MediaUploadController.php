<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\MediaStorage;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Media;
use App\Models\MediaUpload;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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
}
