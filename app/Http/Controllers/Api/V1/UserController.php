<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SuperadminRequest;
use App\Http\Requests\Api\V1\UpdateUserStatusRequest;
use App\Http\Requests\Api\V1\UserIndexRequest;
use App\Http\Resources\UserDetailResource;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Administration\UserAccountService;
use App\Services\Api\CursorPagination;
use Illuminate\Http\JsonResponse;

final class UserController extends Controller
{
    public function index(UserIndexRequest $request, CursorPagination $pagination): JsonResponse
    {
        $query = User::query()
            ->with('profile')
            ->orderByDesc('public_id');

        if ($request->filled('filter.search')) {
            $pattern = '%'.trim($request->string('filter.search')->toString()).'%';
            $query->where(function ($search) use ($pattern): void {
                $search->whereLike('public_id', $pattern)
                    ->orWhereLike('email', $pattern)
                    ->orWhereHas('profile', fn ($profile) => $profile->whereLike('display_name', $pattern));
            });
        }

        if ($request->filled('filter.status')) {
            $query->where('status', $request->string('filter.status')->toString());
        }

        $payload = $pagination->paginate(
            $query,
            $request,
            fn (User $user): array => (new UserResource($user))->resolve($request),
        );

        return response()->json($payload);
    }

    public function show(SuperadminRequest $request, User $user): JsonResponse
    {
        $user->load([
            'profile',
            'organizationMemberships.organization.profile',
            'organizationMemberships.organization.artistRelationships' => fn ($query) => $query->whereNull('ended_at'),
            'organizationMemberships.organization.artistRelationships.artist.profile',
            'organizationMemberships.organization.releases',
            'artistMemberships.artist.profile',
            'artistMemberships.artist.organizationRelationships' => fn ($query) => $query->whereNull('ended_at'),
            'artistMemberships.artist.organizationRelationships.organization.profile',
            'artistMemberships.artist.ownedReleases',
            'releaseEditorAssignments.release',
        ]);

        return ApiResponse::success((new UserDetailResource($user))->resolve($request));
    }

    public function updateStatus(
        UpdateUserStatusRequest $request,
        User $user,
        UserAccountService $service,
    ): JsonResponse {
        $updated = $service->updateStatus(
            $user,
            UserStatus::from($request->string('status')->toString()),
            $request->string('reason')->toString(),
            $request->user(),
            $request,
        );

        return ApiResponse::success((new UserResource($updated))->resolve($request));
    }
}
