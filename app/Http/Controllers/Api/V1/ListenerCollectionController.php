<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Listeners\ListenerIndexRequest;
use App\Http\Requests\Api\V1\Listeners\ReplaceCollectionItemsRequest;
use App\Http\Requests\Api\V1\Listeners\StoreCollectionRequest;
use App\Http\Requests\Api\V1\Listeners\UpdateCollectionRequest;
use App\Http\Responses\ApiResponse;
use App\Models\ListenerCollection;
use App\Services\Listeners\ListenerPagination;
use App\Services\Listeners\ListenerTargetResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ListenerCollectionController extends Controller
{
    public function __construct(private readonly ListenerTargetResolver $targets) {}

    public function index(ListenerIndexRequest $request, ListenerPagination $pagination): JsonResponse
    {
        $payload = $pagination->paginate(ListenerCollection::query()->withCount('items')->where('user_id', $request->user()->getKey())->orderByDesc('updated_at')->orderByDesc('public_id'), $request,
            fn (ListenerCollection $collection) => $this->resource($collection));

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreCollectionRequest $request): JsonResponse
    {
        $collection = $request->user()->listenerCollections()->create($request->validated());

        return ApiResponse::success($this->resource($collection), 201);
    }

    public function show(Request $request, string $collection): JsonResponse
    {
        return ApiResponse::success($this->resource($this->owned($request, $collection)->load(['items.release.activePublication', 'items.track.release.activePublication']), true));
    }

    public function update(UpdateCollectionRequest $request, string $collection): JsonResponse
    {
        $owned = $this->owned($request, $collection);
        $owned->update($request->validated());

        return ApiResponse::success($this->resource($owned->fresh()));
    }

    public function destroy(Request $request, string $collection): JsonResponse
    {
        $this->owned($request, $collection)->delete();

        return ApiResponse::success(['message' => 'Collection deleted.']);
    }

    public function replaceItems(ReplaceCollectionItemsRequest $request, string $collection): JsonResponse
    {
        $owned = $this->owned($request, $collection);
        $updated = DB::transaction(function () use ($owned, $request): ListenerCollection {
            $locked = ListenerCollection::query()->whereKey($owned->getKey())->lockForUpdate()->firstOrFail();
            $rows = [];
            foreach ($request->validated('items') as $index => $item) {
                $target = $item['type'] === 'release' ? $this->targets->release($item['id']) : $this->targets->track($item['id']);
                $rows[] = [
                    'item_type' => $item['type'], 'release_id' => $item['type'] === 'release' ? $target->getKey() : null,
                    'track_id' => $item['type'] === 'track' ? $target->getKey() : null, 'position' => $index + 1,
                ];
            }
            $locked->items()->delete();
            $locked->items()->createMany($rows);
            $locked->touch();

            return $locked->load(['items.release.activePublication', 'items.track.release.activePublication']);
        }, attempts: 3);

        return ApiResponse::success($this->resource($updated, true));
    }

    private function owned(Request $request, string $publicId): ListenerCollection
    {
        return ListenerCollection::query()->where('public_id', $publicId)->where('user_id', $request->user()->getKey())->firstOrFail();
    }

    private function resource(ListenerCollection $collection, bool $includeItems = false): array
    {
        $data = [
            'id' => $collection->public_id, 'name' => $collection->name, 'description' => $collection->description,
            'item_count' => $collection->items_count ?? ($collection->relationLoaded('items') ? $collection->items->count() : $collection->items()->count()),
            'updated_at' => $collection->updated_at->utc()->toISOString(),
        ];
        if ($includeItems) {
            $data['items'] = $collection->items->map(function ($item): array {
                $available = $item->item_type === 'release' ? $item->release?->activePublication !== null : $item->track?->release?->activePublication !== null;

                return [
                    'id' => $item->public_id, 'type' => $item->item_type, 'position' => $item->position, 'available' => $available,
                    'target' => ! $available ? ['id' => $item->release?->public_id ?? $item->track?->public_id]
                        : ($item->item_type === 'release' ? $this->targets->releaseSummary($item->release) : $this->targets->trackSummary($item->track)),
                ];
            })->all();
        }

        return $data;
    }
}
