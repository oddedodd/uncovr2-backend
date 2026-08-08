<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Releases\StoreContributorRequest;
use App\Http\Requests\Api\V1\Releases\UpdateContributorRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Artist;
use App\Models\Contributor;
use App\Models\Organization;
use App\Services\Releases\ReleaseScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ContributorController extends Controller
{
    public function store(StoreContributorRequest $request, ReleaseScopeResolver $resolver): JsonResponse
    {
        $data = $request->validated();
        $owner = $resolver->resolveOwner($data['owner_type'], $data['owner_id'], $request->user());
        unset($data['owner_type'], $data['owner_id']);
        $contributor = Contributor::query()->create([...$data, 'organization_id' => $owner instanceof Organization ? $owner->getKey() : null, 'artist_id' => $owner instanceof Artist ? $owner->getKey() : null, 'created_by_user_id' => $request->user()->getKey(), 'updated_by_user_id' => $request->user()->getKey()]);

        return ApiResponse::success($this->resource($contributor), 201);
    }

    public function show(Contributor $contributor): JsonResponse
    {
        Gate::authorize('view', $contributor);

        return ApiResponse::success($this->resource($contributor));
    }

    public function update(UpdateContributorRequest $request, Contributor $contributor): JsonResponse
    {
        Gate::authorize('update', $contributor);
        $contributor->update([...$request->validated(), 'updated_by_user_id' => $request->user()->getKey()]);

        return ApiResponse::success($this->resource($contributor));
    }

    public function destroy(Request $request, Contributor $contributor): JsonResponse
    {
        Gate::authorize('delete', $contributor);
        $contributor->delete();

        return ApiResponse::success(['message' => 'Contributor deleted.']);
    }

    private function resource(Contributor $contributor): array
    {
        return ['id' => $contributor->public_id, 'owner' => ['type' => $contributor->organization_id ? 'organization' : 'artist', 'id' => $contributor->organization?->public_id ?? $contributor->artist?->public_id], 'display_name' => $contributor->display_name, 'legal_name' => $contributor->legal_name, 'email' => $contributor->email, 'website_url' => $contributor->website_url];
    }
}
