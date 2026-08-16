<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Releases\ReorderPagesRequest;
use App\Http\Requests\Api\V1\Releases\StorePageRequest;
use App\Http\Requests\Api\V1\Releases\UpdatePageRequest;
use App\Http\Resources\ReleasePageResource;
use App\Http\Responses\ApiResponse;
use App\Models\Page;
use App\Models\Release;
use App\Models\Track;
use App\Services\Releases\ContentOrderService;
use App\Services\Releases\ReleaseActivityLogger;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function update(UpdatePageRequest $request, Page $page, ContentOrderService $order, ReleaseActivityLogger $activity): JsonResponse
    {
        $release = $page->owningRelease();
        Gate::authorize('update', $release);
        $data = $request->validated();
        $position = $data['position'] ?? null;
        unset($data['position']);

        DB::transaction(function () use ($request, $page, $data, $position, $order): void {
            $page->update([...$data, 'updated_by_user_id' => $request->user()->getKey()]);
            if ($position !== null) {
                $order->movePage($page, $request->user(), $position);
            }
        });

        // Log the position the page ended up in rather than the one that was asked
        // for: `movePage` clamps to the sibling count.
        $activity->record($release, $request->user(), 'page.updated', $page, [
            ...$data,
            ...($position !== null ? ['position' => $page->position] : []),
        ]);

        return ApiResponse::success($this->resource($page));
    }

    public function reorderForRelease(ReorderPagesRequest $request, Release $release, ContentOrderService $order): JsonResponse
    {
        Gate::authorize('update', $release);
        $pages = $order->reorderPages($release, $request->user(), $request->input('page_ids'));
        $pages->load('blocks');

        return ApiResponse::success($pages
            ->map(fn (Page $page): array => (new ReleasePageResource($page, $release->public_id))->resolve())
            ->all());
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

    private function assertPosition(HasMany $relation, int $position): void
    {
        if ($relation->where('position', $position)->exists()) {
            throw ValidationException::withMessages(['position' => ['The position is already in use for this parent.']]);
        }
    }

    private function resource(Page $page): array
    {
        return (new ReleasePageResource($page->loadMissing('blocks')))->resolve();
    }
}
