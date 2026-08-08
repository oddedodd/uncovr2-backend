<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Releases\StoreStreamingLinkRequest;
use App\Http\Requests\Api\V1\Releases\UpdateStreamingLinkRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Release;
use App\Models\StreamingLink;
use App\Models\Track;
use App\Services\Releases\ReleaseActivityLogger;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class StreamingLinkController extends Controller
{
    public function storeForRelease(StoreStreamingLinkRequest $request, Release $release, ReleaseActivityLogger $activity): JsonResponse
    {
        Gate::authorize('update', $release);

        return $this->store($request, $release, $release->streamingLinks(), $activity);
    }

    public function storeForTrack(StoreStreamingLinkRequest $request, Track $track, ReleaseActivityLogger $activity): JsonResponse
    {
        $release = $track->release;
        Gate::authorize('update', $release);

        return $this->store($request, $release, $track->streamingLinks(), $activity);
    }

    public function update(UpdateStreamingLinkRequest $request, StreamingLink $streamingLink, ReleaseActivityLogger $activity): JsonResponse
    {
        $release = $streamingLink->owningRelease();
        Gate::authorize('update', $release);
        if ($request->has('service')) {
            $this->assertServiceAvailable($streamingLink->release_id ? $release->streamingLinks() : $streamingLink->track->streamingLinks(), $request->string('service')->toString(), $streamingLink);
        }
        if ($request->has('position')) {
            $this->assertPositionAvailable($streamingLink->release_id ? $release->streamingLinks() : $streamingLink->track->streamingLinks(), $request->integer('position'), $streamingLink);
        }
        $streamingLink->update([...$request->validated(), 'updated_by_user_id' => $request->user()->getKey()]);
        $activity->record($release, $request->user(), 'streaming_link.updated', $streamingLink, $request->validated());

        return ApiResponse::success($this->resource($streamingLink));
    }

    public function destroy(Request $request, StreamingLink $streamingLink, ReleaseActivityLogger $activity): JsonResponse
    {
        $release = $streamingLink->owningRelease();
        Gate::authorize('update', $release);
        $activity->record($release, $request->user(), 'streaming_link.deleted', $streamingLink);
        $streamingLink->delete();

        return ApiResponse::success(['message' => 'Streaming link deleted.']);
    }

    private function store(StoreStreamingLinkRequest $request, Release $release, HasMany $relation, ReleaseActivityLogger $activity): JsonResponse
    {
        $this->assertServiceAvailable($relation, $request->string('service')->toString());
        $this->assertPositionAvailable($relation, $request->integer('position'));
        $link = $relation->create([...$request->validated(), 'created_by_user_id' => $request->user()->getKey(), 'updated_by_user_id' => $request->user()->getKey()]);
        $activity->record($release, $request->user(), 'streaming_link.created', $link);

        return ApiResponse::success($this->resource($link), 201);
    }

    private function assertServiceAvailable(HasMany $relation, string $service, ?StreamingLink $except = null): void
    {
        $query = (clone $relation)->where('service', $service);
        if ($except) {
            $query->whereKeyNot($except->getKey());
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['service' => ['This service already has a link for the resource.']]);
        }
    }

    private function resource(StreamingLink $link): array
    {
        return ['id' => $link->public_id, 'service' => $link->service, 'url' => $link->url, 'position' => $link->position];
    }

    private function assertPositionAvailable(HasMany $relation, int $position, ?StreamingLink $except = null): void
    {
        $query = (clone $relation)->where('position', $position);
        if ($except) {
            $query->whereKeyNot($except->getKey());
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['position' => ['The position is already in use for this resource.']]);
        }
    }
}
