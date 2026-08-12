<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Releases\StorePageRequest;
use App\Http\Requests\Api\V1\Releases\UpdatePageRequest;
use App\Http\Resources\ReleasePageResource;
use App\Http\Responses\ApiResponse;
use App\Models\Page;
use App\Models\Release;
use App\Models\Track;
use App\Services\Releases\ReleaseActivityLogger;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class PageController extends Controller
{
    public function storeForRelease(StorePageRequest $request, Release $release, ReleaseActivityLogger $activity): JsonResponse
    {
        Gate::authorize('update', $release);

        return $this->store($request, $release, $release->pages(), $activity);
    }

    public function storeForTrack(StorePageRequest $request, Track $track, ReleaseActivityLogger $activity): JsonResponse
    {
        $release = $track->release;
        Gate::authorize('update', $release);

        return $this->store($request, $release, $track->pages(), $activity);
    }

    public function update(UpdatePageRequest $request, Page $page, ReleaseActivityLogger $activity): JsonResponse
    {
        $release = $page->owningRelease();
        Gate::authorize('update', $release);
        if ($request->has('position') && $request->integer('position') !== $page->position) {
            $this->assertPosition($page->release_id ? $release->pages() : $page->track->pages(), $request->integer('position'), $page);
        }
        $page->update([...$request->validated(), 'updated_by_user_id' => $request->user()->getKey()]);
        $activity->record($release, $request->user(), 'page.updated', $page, $request->validated());

        return ApiResponse::success($this->resource($page));
    }

    public function destroy(Request $request, Page $page, ReleaseActivityLogger $activity): JsonResponse
    {
        $release = $page->owningRelease();
        Gate::authorize('update', $release);
        $activity->record($release, $request->user(), 'page.deleted', $page);
        $page->delete();

        return ApiResponse::success(['message' => 'Page deleted.']);
    }

    private function store(StorePageRequest $request, Release $release, HasMany $relation, ReleaseActivityLogger $activity): JsonResponse
    {
        $this->assertPosition($relation, $request->integer('position'));
        $page = $relation->create([...$request->validated(), 'created_by_user_id' => $request->user()->getKey(), 'updated_by_user_id' => $request->user()->getKey()]);
        $activity->record($release, $request->user(), 'page.created', $page);

        return ApiResponse::success($this->resource($page), 201);
    }

    private function assertPosition(HasMany $relation, int $position, ?Page $except = null): void
    {
        $query = $relation->where('position', $position);
        if ($except) {
            $query->whereKeyNot($except->getKey());
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['position' => ['The position is already in use for this parent.']]);
        }
    }

    private function resource(Page $page): array
    {
        return (new ReleasePageResource($page->loadMissing('blocks')))->resolve();
    }
}
