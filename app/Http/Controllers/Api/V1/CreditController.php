<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Releases\StoreCreditRequest;
use App\Http\Requests\Api\V1\Releases\UpdateCreditRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Contributor;
use App\Models\Credit;
use App\Models\Release;
use App\Models\Track;
use App\Services\Releases\ReleaseActivityLogger;
use App\Services\Releases\ReleaseScopeResolver;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CreditController extends Controller
{
    public function storeForRelease(StoreCreditRequest $request, Release $release, ReleaseScopeResolver $resolver, ReleaseActivityLogger $activity): JsonResponse
    {
        Gate::authorize('update', $release);

        return $this->store($request, $release, $release->credits(), $resolver, $activity);
    }

    public function storeForTrack(StoreCreditRequest $request, Track $track, ReleaseScopeResolver $resolver, ReleaseActivityLogger $activity): JsonResponse
    {
        $release = $track->release;
        Gate::authorize('update', $release);

        return $this->store($request, $release, $track->credits(), $resolver, $activity);
    }

    public function update(UpdateCreditRequest $request, Credit $credit, ReleaseScopeResolver $resolver, ReleaseActivityLogger $activity): JsonResponse
    {
        $release = $credit->owningRelease();
        Gate::authorize('update', $release);
        $data = $request->validated();
        if (isset($data['contributor_id'])) {
            $contributor = Contributor::query()->where('public_id', $data['contributor_id'])->sole();
            $resolver->assertSameOwner($release, $contributor, 'contributor_id');
            $data['contributor_id'] = $contributor->getKey();
        }
        if (isset($data['position'])) {
            $this->assertPositionAvailable($credit->release_id ? $release->credits() : $credit->track->credits(), $data['position'], $credit);
        }
        $credit->update([...$data, 'updated_by_user_id' => $request->user()->getKey()]);
        $activity->record($release, $request->user(), 'credit.updated', $credit, $request->validated());

        return ApiResponse::success($this->resource($credit));
    }

    public function destroy(Request $request, Credit $credit, ReleaseActivityLogger $activity): JsonResponse
    {
        $release = $credit->owningRelease();
        Gate::authorize('update', $release);
        $activity->record($release, $request->user(), 'credit.deleted', $credit);
        $credit->delete();

        return ApiResponse::success(['message' => 'Credit deleted.']);
    }

    private function store(StoreCreditRequest $request, Release $release, HasMany $relation, ReleaseScopeResolver $resolver, ReleaseActivityLogger $activity): JsonResponse
    {
        $data = $request->validated();
        $contributor = Contributor::query()->where('public_id', $data['contributor_id'])->sole();
        $resolver->assertSameOwner($release, $contributor, 'contributor_id');
        $data['contributor_id'] = $contributor->getKey();
        $this->assertPositionAvailable($relation, $data['position']);
        $credit = $relation->create([...$data, 'created_by_user_id' => $request->user()->getKey(), 'updated_by_user_id' => $request->user()->getKey()]);
        $activity->record($release, $request->user(), 'credit.created', $credit);

        return ApiResponse::success($this->resource($credit), 201);
    }

    private function resource(Credit $credit): array
    {
        $credit->loadMissing('contributor');

        return ['id' => $credit->public_id, 'contributor' => ['id' => $credit->contributor->public_id, 'display_name' => $credit->contributor->display_name], 'role' => $credit->role, 'detail' => $credit->detail, 'position' => $credit->position];
    }

    private function assertPositionAvailable(HasMany $relation, int $position, ?Credit $except = null): void
    {
        $query = (clone $relation)->where('position', $position);
        if ($except) {
            $query->whereKeyNot($except->getKey());
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['position' => ['The position is already in use for this resource.']]);
        }
    }
}
