<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Releases\StoreContentBlockRequest;
use App\Http\Requests\Api\V1\Releases\UpdateContentBlockRequest;
use App\Http\Resources\ContentBlockResource;
use App\Http\Responses\ApiResponse;
use App\Models\ContentBlock;
use App\Models\Page;
use App\Services\Releases\ContentBlockService;
use App\Services\Releases\ReleaseActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ContentBlockController extends Controller
{
    public function store(StoreContentBlockRequest $request, Page $page, ContentBlockService $service): JsonResponse
    {
        Gate::authorize('update', $page->owningRelease());

        return ApiResponse::success($this->resource($service->create($page, $request->user(), $request->validated())), 201);
    }

    public function update(UpdateContentBlockRequest $request, Page $page, ContentBlock $block, ContentBlockService $service): JsonResponse
    {
        $this->assertParent($page, $block);
        Gate::authorize('update', $page->owningRelease());

        return ApiResponse::success($this->resource($service->update($block, $request->user(), $request->validated())));
    }

    public function destroy(Request $request, Page $page, ContentBlock $block, ReleaseActivityLogger $activity): JsonResponse
    {
        $this->assertParent($page, $block);
        $release = $page->owningRelease();
        Gate::authorize('update', $release);
        $activity->record($release, $request->user(), 'content_block.deleted', $block, ['version' => $block->version]);
        $block->delete();

        return ApiResponse::success(['message' => 'Content block deleted.']);
    }

    public function versions(Page $page, ContentBlock $block): JsonResponse
    {
        $this->assertParent($page, $block);
        Gate::authorize('view', $page->owningRelease());

        return ApiResponse::success($block->versions->map(fn ($version) => ['version' => $version->version, 'type' => $version->type->value, 'payload' => $version->payload, 'created_at' => $version->created_at->utc()->format('Y-m-d\TH:i:s.v\Z')])->all());
    }

    private function assertParent(Page $page, ContentBlock $block): void
    {
        if ($block->page_id !== $page->getKey()) {
            throw new NotFoundHttpException;
        }
    }

    private function resource(ContentBlock $block): array
    {
        return (new ContentBlockResource($block->loadMissing('page')))->resolve();
    }
}
