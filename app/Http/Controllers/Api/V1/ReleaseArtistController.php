<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Releases\StoreReleaseArtistRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Artist;
use App\Models\Release;
use App\Services\Authorization\ScopeAccess;
use App\Services\Releases\ReleaseActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ReleaseArtistController extends Controller
{
    public function store(StoreReleaseArtistRequest $request, Release $release, ScopeAccess $access, ReleaseActivityLogger $activity): JsonResponse
    {
        Gate::authorize('update', $release);
        $artist = Artist::query()->where('public_id', $request->string('artist_id')->toString())->sole();
        if (! $request->user()->is_superadmin && ! $access->canViewArtist($request->user(), $artist)) {
            throw ValidationException::withMessages(['artist_id' => ['The selected artist is not available in your scopes.']]);
        }
        if ($release->artistLinks()->where('artist_id', $artist->getKey())->exists()) {
            throw ValidationException::withMessages(['artist_id' => ['The artist is already attached to this release.']]);
        }
        if ($release->artistLinks()->where('position', $request->integer('position'))->exists()) {
            throw ValidationException::withMessages(['position' => ['The position is already in use on this release.']]);
        }
        $link = DB::transaction(function () use ($request, $release, $artist, $activity) {
            Release::query()->lockForUpdate()->findOrFail($release->getKey());
            if ($request->boolean('is_primary')) {
                $release->artistLinks()->where('is_primary', true)->update(['is_primary' => false]);
            }
            $link = $release->artistLinks()->create(['artist_id' => $artist->getKey(), 'is_primary' => $request->boolean('is_primary'), 'position' => $request->integer('position')]);
            $activity->record($release, $request->user(), 'release.artist_added', $artist);

            return $link;
        });

        return ApiResponse::success(['artist_id' => $artist->public_id, 'is_primary' => $link->is_primary, 'position' => $link->position], 201);
    }

    public function destroy(Request $request, Release $release, Artist $artist, ReleaseActivityLogger $activity): JsonResponse
    {
        Gate::authorize('update', $release);
        $link = $release->artistLinks()->where('artist_id', $artist->getKey())->firstOrFail();
        if ($link->is_primary) {
            throw ValidationException::withMessages(['artist_id' => ['The primary artist cannot be removed.']]);
        }
        $link->delete();
        $activity->record($release, $request->user(), 'release.artist_removed', $artist);

        return ApiResponse::success(['message' => 'Artist removed from release.']);
    }
}
