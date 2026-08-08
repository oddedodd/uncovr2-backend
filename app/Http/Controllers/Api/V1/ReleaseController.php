<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Releases\StoreReleaseRequest;
use App\Http\Requests\Api\V1\Releases\UpdateReleaseRequest;
use App\Http\Resources\ReleaseResource;
use App\Http\Responses\ApiResponse;
use App\Models\Release;
use App\Services\Releases\ReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ReleaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (array_diff(array_keys($request->query()), ['page'])) {
            throw ValidationException::withMessages(['query' => ['The query contains unsupported parameters.']]);
        }
        Validator::make($request->query(), [
            'page' => ['sometimes', 'array:size,after,before'],
            'page.size' => ['sometimes', 'integer', 'between:1,100'],
            'page.after' => ['sometimes', 'string'],
            'page.before' => ['sometimes', 'string'],
        ])->validate();
        if ($request->has('page.after') && $request->has('page.before')) {
            throw ValidationException::withMessages(['page' => ['The after and before cursors are mutually exclusive.']]);
        }
        $size = (int) $request->input('page.size', 25);
        $encodedCursor = $request->input('page.after', $request->input('page.before'));
        $cursor = is_string($encodedCursor) ? Cursor::fromEncoded($encodedCursor) : null;
        if (is_string($encodedCursor) && ! $cursor) {
            throw ValidationException::withMessages(['page' => ['The pagination cursor is invalid.']]);
        }
        $query = Release::query()->with($this->includes())->orderByDesc('created_at')->orderByDesc('public_id');
        if (! $request->user()->is_superadmin) {
            $userId = $request->user()->getKey();
            $query->where(function ($owners) use ($userId): void {
                $owners->whereHas('organization', fn ($organization) => $organization
                    ->where('status', 'active')->whereHas('memberships', fn ($memberships) => $memberships->where('user_id', $userId)->where('status', MembershipStatus::Active->value)))
                    ->orWhereHas('ownerArtist', fn ($artist) => $artist->where('status', 'active')->where(function ($access) use ($userId): void {
                        $access->whereHas('memberships', fn ($memberships) => $memberships->where('user_id', $userId)->where('status', MembershipStatus::Active->value))
                            ->orWhereHas('organizationRelationships', fn ($relations) => $relations->whereNull('ended_at')->whereHas('organization.memberships', fn ($memberships) => $memberships->where('user_id', $userId)->where('status', MembershipStatus::Active->value)));
                    }));
            });
        }
        $page = $query->cursorPaginate($size, cursor: $cursor);

        return ApiResponse::success(collect($page->items())->map(fn ($release) => (new ReleaseResource($release))->resolve())->all(), meta: ['pagination' => ['per_page' => $page->perPage(), 'next_cursor' => $page->nextCursor()?->encode(), 'previous_cursor' => $page->previousCursor()?->encode(), 'has_more' => $page->hasMorePages()]]);
    }

    public function store(StoreReleaseRequest $request, ReleaseService $service): JsonResponse
    {
        $release = $service->create($request->user(), $request->validated())->load($this->includes());

        return ApiResponse::success((new ReleaseResource($release))->resolve(), 201);
    }

    public function show(Release $release): JsonResponse
    {
        Gate::authorize('view', $release);

        return ApiResponse::success((new ReleaseResource($release->load($this->includes())))->resolve());
    }

    public function update(UpdateReleaseRequest $request, Release $release, ReleaseService $service): JsonResponse
    {
        Gate::authorize('update', $release);

        return ApiResponse::success((new ReleaseResource($service->update($release, $request->user(), $request->validated())->load($this->includes())))->resolve());
    }

    public function destroy(Request $request, Release $release, ReleaseService $service): JsonResponse
    {
        Gate::authorize('delete', $release);
        $service->delete($release, $request->user());

        return ApiResponse::success(['message' => 'Release deleted.']);
    }

    private function includes(): array
    {
        return ['organization', 'ownerArtist', 'coverMedia', 'artistLinks.artist.profile', 'editorAssignments.user.profile', 'tracks.pages.blocks', 'tracks.streamingLinks', 'tracks.credits.contributor', 'pages.blocks', 'streamingLinks', 'credits.contributor'];
    }
}
