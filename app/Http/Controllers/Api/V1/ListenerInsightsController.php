<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Artist;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ListenerInsightsController extends Controller
{
    public function organization(Organization $organization): JsonResponse
    {
        Gate::authorize('view', $organization);
        $artistFollowers = DB::table('artist_follows')->join('organization_artist_relationships', 'organization_artist_relationships.artist_id', '=', 'artist_follows.artist_id')
            ->where('organization_artist_relationships.organization_id', $organization->getKey())->whereNull('organization_artist_relationships.ended_at')->count();
        $releaseFavorites = DB::table('release_favorites')->join('releases', 'releases.id', '=', 'release_favorites.release_id')->where('releases.organization_id', $organization->getKey())->count();
        $trackFavorites = DB::table('track_favorites')->join('tracks', 'tracks.id', '=', 'track_favorites.track_id')->join('releases', 'releases.id', '=', 'tracks.release_id')->where('releases.organization_id', $organization->getKey())->count();

        return ApiResponse::success(['scope' => ['type' => 'organization', 'id' => $organization->public_id], 'totals' => ['artist_followers' => $artistFollowers, 'release_favorites' => $releaseFavorites, 'track_favorites' => $trackFavorites]]);
    }

    public function artist(Artist $artist): JsonResponse
    {
        Gate::authorize('view', $artist);
        $artistFollowers = DB::table('artist_follows')->where('artist_id', $artist->getKey())->count();
        $releaseFavorites = DB::table('release_favorites')->join('release_artists', 'release_artists.release_id', '=', 'release_favorites.release_id')->where('release_artists.artist_id', $artist->getKey())->count();
        $trackFavorites = DB::table('track_favorites')->join('tracks', 'tracks.id', '=', 'track_favorites.track_id')->join('release_artists', 'release_artists.release_id', '=', 'tracks.release_id')->where('release_artists.artist_id', $artist->getKey())->count();

        return ApiResponse::success(['scope' => ['type' => 'artist', 'id' => $artist->public_id], 'totals' => ['artist_followers' => $artistFollowers, 'release_favorites' => $releaseFavorites, 'track_favorites' => $trackFavorites]]);
    }
}
