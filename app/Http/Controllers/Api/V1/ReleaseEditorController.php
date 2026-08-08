<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Releases\StoreReleaseEditorRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Release;
use App\Models\User;
use App\Services\Releases\ReleaseActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ReleaseEditorController extends Controller
{
    public function store(StoreReleaseEditorRequest $request, Release $release, ReleaseActivityLogger $activity): JsonResponse
    {
        Gate::authorize('manageEditors', $release);
        $user = User::query()->where('public_id', $request->string('user_id')->toString())->sole();
        if (! Gate::forUser($user)->allows('view', $release)) {
            throw ValidationException::withMessages(['user_id' => ['The user must have active access to the release owner scope.']]);
        }
        $assignment = $release->editorAssignments()->firstOrCreate(['user_id' => $user->getKey()], ['granted_by_user_id' => $request->user()->getKey()]);
        if ($assignment->wasRecentlyCreated) {
            $activity->record($release, $request->user(), 'release.editor_added', $user);
        }

        return ApiResponse::success(['user_id' => $user->public_id], $assignment->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, Release $release, User $user, ReleaseActivityLogger $activity): JsonResponse
    {
        Gate::authorize('manageEditors', $release);
        $release->editorAssignments()->where('user_id', $user->getKey())->delete();
        $activity->record($release, $request->user(), 'release.editor_removed', $user);

        return ApiResponse::success(['message' => 'Release editor removed.']);
    }
}
