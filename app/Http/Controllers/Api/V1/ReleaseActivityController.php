<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Release;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class ReleaseActivityController extends Controller
{
    public function index(Release $release): JsonResponse
    {
        Gate::authorize('view', $release);
        $events = $release->activityEvents()->with('user.profile')->orderBy('occurred_at')->orderBy('public_id')->get()->map(fn ($event) => [
            'id' => $event->public_id, 'event_type' => $event->event_type,
            'actor_user_id' => $event->user?->public_id, 'subject_type' => $event->subject_type,
            'subject_id' => $event->subject_public_id, 'changes' => $event->changes,
            'occurred_at' => $event->occurred_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ])->all();

        return ApiResponse::success($events);
    }
}
