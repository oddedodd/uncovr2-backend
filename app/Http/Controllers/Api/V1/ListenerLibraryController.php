<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Listeners\ListenerIndexRequest;
use App\Http\Responses\ApiResponse;
use App\Models\ArtistFollow;
use App\Models\ReleaseFavorite;
use App\Models\TrackFavorite;
use App\Services\Listeners\ListenerPagination;
use App\Services\Listeners\ListenerTargetResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ListenerLibraryController extends Controller
{
    public function followedArtists(ListenerIndexRequest $request, ListenerPagination $pagination, ListenerTargetResolver $targets): JsonResponse
    {
        $payload = $pagination->paginate(ArtistFollow::query()->with('artist.profile')->where('user_id', $request->user()->getKey())
            ->whereHas('artist', fn ($artist) => $artist->where('status', 'active')->whereHas('releaseLinks.release', fn ($release) => $targets->visibleRelease($release)))
            ->orderByDesc('created_at')->orderByDesc('id'), $request,
            fn (ArtistFollow $follow) => ['id' => $follow->artist->public_id, 'name' => $follow->artist->profile->name, 'followed_at' => $follow->created_at->utc()->toISOString()]);

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }

    public function followArtist(Request $request, string $artist, ListenerTargetResolver $targets): JsonResponse
    {
        $target = $targets->artist($artist);
        ArtistFollow::query()->firstOrCreate(['user_id' => $request->user()->getKey(), 'artist_id' => $target->getKey()]);

        return ApiResponse::success(['artist_id' => $target->public_id, 'following' => true]);
    }

    public function unfollowArtist(Request $request, string $artist): JsonResponse
    {
        ArtistFollow::query()->where('user_id', $request->user()->getKey())->whereHas('artist', fn ($query) => $query->where('public_id', $artist))->delete();

        return ApiResponse::success(['artist_id' => $artist, 'following' => false]);
    }

    public function favoriteReleases(ListenerIndexRequest $request, ListenerPagination $pagination, ListenerTargetResolver $targets): JsonResponse
    {
        $payload = $pagination->paginate(ReleaseFavorite::query()->with('release.activePublication')->where('user_id', $request->user()->getKey())
            ->whereHas('release', fn ($release) => $targets->visibleRelease($release))->orderByDesc('created_at')->orderByDesc('id'), $request,
            fn (ReleaseFavorite $favorite) => [...$targets->releaseSummary($favorite->release), 'favorited_at' => $favorite->created_at->utc()->toISOString()]);

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }

    public function favoriteRelease(Request $request, string $release, ListenerTargetResolver $targets): JsonResponse
    {
        $target = $targets->release($release);
        ReleaseFavorite::query()->firstOrCreate(['user_id' => $request->user()->getKey(), 'release_id' => $target->getKey()]);

        return ApiResponse::success(['release_id' => $target->public_id, 'favorite' => true]);
    }

    public function unfavoriteRelease(Request $request, string $release): JsonResponse
    {
        ReleaseFavorite::query()->where('user_id', $request->user()->getKey())->whereHas('release', fn ($query) => $query->where('public_id', $release))->delete();

        return ApiResponse::success(['release_id' => $release, 'favorite' => false]);
    }

    public function favoriteTracks(ListenerIndexRequest $request, ListenerPagination $pagination, ListenerTargetResolver $targets): JsonResponse
    {
        $payload = $pagination->paginate(TrackFavorite::query()->with('track.release.activePublication')->where('user_id', $request->user()->getKey())
            ->whereHas('track.release', fn ($release) => $targets->visibleRelease($release))->orderByDesc('created_at')->orderByDesc('id'), $request,
            fn (TrackFavorite $favorite) => [...$targets->trackSummary($favorite->track), 'favorited_at' => $favorite->created_at->utc()->toISOString()]);

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }

    public function favoriteTrack(Request $request, string $track, ListenerTargetResolver $targets): JsonResponse
    {
        $target = $targets->track($track);
        TrackFavorite::query()->firstOrCreate(['user_id' => $request->user()->getKey(), 'track_id' => $target->getKey()]);

        return ApiResponse::success(['track_id' => $target->public_id, 'favorite' => true]);
    }

    public function unfavoriteTrack(Request $request, string $track): JsonResponse
    {
        TrackFavorite::query()->where('user_id', $request->user()->getKey())->whereHas('track', fn ($query) => $query->where('public_id', $track))->delete();

        return ApiResponse::success(['track_id' => $track, 'favorite' => false]);
    }
}
