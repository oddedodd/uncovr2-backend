<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Releases\StoreTrackRequest;
use App\Http\Requests\Api\V1\Releases\UpdateTrackRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Release;
use App\Models\Track;
use App\Services\Releases\ReleaseActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TrackController extends Controller
{
    public function store(StoreTrackRequest $request, Release $release, ReleaseActivityLogger $activity): JsonResponse
    {
        Gate::authorize('update', $release);
        $track = DB::transaction(function () use ($request, $release, $activity): Track {
            Release::query()->lockForUpdate()->findOrFail($release->getKey());
            $this->assertPosition($release, $request->integer('position'));
            $track = $release->tracks()->create([...$request->validated(), 'created_by_user_id' => $request->user()->getKey(), 'updated_by_user_id' => $request->user()->getKey()]);
            $activity->record($release, $request->user(), 'track.created', $track);

            return $track;
        });

        return ApiResponse::success($this->resource($track), 201);
    }

    public function update(UpdateTrackRequest $request, Release $release, Track $track, ReleaseActivityLogger $activity): JsonResponse
    {
        $this->assertParent($release, $track);
        Gate::authorize('update', $release);
        if ($request->has('position') && $request->integer('position') !== $track->position) {
            $this->assertPosition($release, $request->integer('position'), $track);
        }
        $track->update([...$request->validated(), 'updated_by_user_id' => $request->user()->getKey()]);
        $activity->record($release, $request->user(), 'track.updated', $track, $request->validated());

        return ApiResponse::success($this->resource($track));
    }

    public function destroy(Request $request, Release $release, Track $track, ReleaseActivityLogger $activity): JsonResponse
    {
        $this->assertParent($release, $track);
        Gate::authorize('update', $release);
        $activity->record($release, $request->user(), 'track.deleted', $track);
        $track->delete();

        return ApiResponse::success(['message' => 'Track deleted.']);
    }

    private function assertPosition(Release $release, int $position, ?Track $except = null): void
    {
        $query = $release->tracks()->where('position', $position);
        if ($except) {
            $query->whereKeyNot($except->getKey());
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['position' => ['The position is already in use on this release.']]);
        }
    }

    private function assertParent(Release $release, Track $track): void
    {
        if ($track->release_id !== $release->getKey()) {
            throw new NotFoundHttpException;
        }
    }

    private function resource(Track $track): array
    {
        return ['id' => $track->public_id, 'release_id' => $track->release->public_id, 'position' => $track->position, 'title' => $track->title, 'duration_ms' => $track->duration_ms, 'isrc' => $track->isrc, 'is_explicit' => $track->is_explicit];
    }
}
