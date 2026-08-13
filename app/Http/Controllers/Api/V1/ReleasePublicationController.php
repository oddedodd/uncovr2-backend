<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Releases\FeatureReleaseRequest;
use App\Http\Requests\Api\V1\Releases\ReleaseDecisionRequest;
use App\Http\Requests\Api\V1\Releases\ScheduleReleaseRequest;
use App\Http\Resources\ReleaseResource;
use App\Http\Responses\ApiResponse;
use App\Models\Release;
use App\Models\User;
use App\Services\Authorization\ReleaseCapabilities;
use App\Services\Releases\ReleasePublicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

final class ReleasePublicationController extends Controller
{
    public function preview(Release $release, ReleasePublicationService $service): JsonResponse
    {
        Gate::authorize('view', $release);

        return ApiResponse::success($service->preview($release));
    }

    public function submit(ReleaseDecisionRequest $request, Release $release, ReleasePublicationService $service): JsonResponse
    {
        Gate::authorize('submit', $release);
        $approval = $service->submit($release, $request->user(), $request->validated('note'));

        return ApiResponse::success(['approval_request_id' => $approval->public_id, 'status' => 'review'], 201);
    }

    public function approve(ReleaseDecisionRequest $request, Release $release, ReleasePublicationService $service, ReleaseCapabilities $capabilities): JsonResponse
    {
        Gate::authorize('approve', $release);

        return $this->release($service->decide($release, $request->user(), true, $request->validated('note')), $request->user(), $capabilities);
    }

    public function reject(ReleaseDecisionRequest $request, Release $release, ReleasePublicationService $service, ReleaseCapabilities $capabilities): JsonResponse
    {
        Gate::authorize('approve', $release);

        return $this->release($service->decide($release, $request->user(), false, $request->validated('note')), $request->user(), $capabilities);
    }

    public function schedule(ScheduleReleaseRequest $request, Release $release, ReleasePublicationService $service, ReleaseCapabilities $capabilities): JsonResponse
    {
        Gate::authorize('publish', $release);

        return $this->release($service->schedule($release, $request->user(), Carbon::parse($request->validated('publish_at'))), $request->user(), $capabilities);
    }

    public function publish(Request $request, Release $release, ReleasePublicationService $service, ReleaseCapabilities $capabilities): JsonResponse
    {
        Gate::authorize('publish', $release);

        return $this->release($service->publish($release, $request->user()), $request->user(), $capabilities);
    }

    public function unpublish(Request $request, Release $release, ReleasePublicationService $service, ReleaseCapabilities $capabilities): JsonResponse
    {
        Gate::authorize('unpublish', $release);

        return $this->release($service->unpublish($release, $request->user()), $request->user(), $capabilities);
    }

    public function archive(Request $request, Release $release, ReleasePublicationService $service, ReleaseCapabilities $capabilities): JsonResponse
    {
        Gate::authorize('archive', $release);

        return $this->release($service->archive($release, $request->user()), $request->user(), $capabilities);
    }

    public function feature(FeatureReleaseRequest $request, Release $release, ReleasePublicationService $service, ReleaseCapabilities $capabilities): JsonResponse
    {
        Gate::authorize('feature', $release);

        return $this->release($service->feature($release, $request->user(), $request->boolean('featured')), $request->user(), $capabilities);
    }

    private function release(Release $release, User $actor, ReleaseCapabilities $capabilities): JsonResponse
    {
        return ApiResponse::success((new ReleaseResource($release, $capabilities->forRelease($actor, $release)))->resolve());
    }
}
