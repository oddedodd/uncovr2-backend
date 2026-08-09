<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Listeners\ListenerIndexRequest;
use App\Http\Responses\ApiResponse;
use App\Models\ListenerNotification;
use App\Services\Listeners\ListenerPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ListenerNotificationController extends Controller
{
    public function index(ListenerIndexRequest $request, ListenerPagination $pagination): JsonResponse
    {
        $payload = $pagination->paginate(ListenerNotification::query()->where('user_id', $request->user()->getKey())->orderByDesc('created_at')->orderByDesc('public_id'), $request, fn (ListenerNotification $notification) => $this->resource($notification));
        $payload['meta']['unread_count'] = ListenerNotification::query()->where('user_id', $request->user()->getKey())->whereNull('read_at')->count();

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $owned = ListenerNotification::query()->where('public_id', $notification)->where('user_id', $request->user()->getKey())->firstOrFail();
        if (! $owned->read_at) {
            $owned->update(['read_at' => now()]);
        }

        return ApiResponse::success($this->resource($owned->fresh()));
    }

    public function readAll(Request $request): JsonResponse
    {
        ListenerNotification::query()->where('user_id', $request->user()->getKey())->whereNull('read_at')->update(['read_at' => now()]);

        return ApiResponse::success(['message' => 'Notifications marked as read.']);
    }

    private function resource(ListenerNotification $notification): array
    {
        return ['id' => $notification->public_id, 'type' => $notification->type, 'title' => $notification->title, 'body' => $notification->body, 'data' => $notification->data ?? [], 'read_at' => $notification->read_at?->utc()->toISOString(), 'created_at' => $notification->created_at->utc()->toISOString()];
    }
}
