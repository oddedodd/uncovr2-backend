<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PublicCatalogIndexRequest;
use App\Http\Requests\Api\V1\PublicCatalogShowRequest;
use App\Services\PublicApi\PublicCatalog;
use App\Services\PublicApi\PublicCatalogCache;
use Illuminate\Http\JsonResponse;

final class PublicCatalogController extends Controller
{
    public function labels(PublicCatalogIndexRequest $request, PublicCatalog $catalog, PublicCatalogCache $cache): JsonResponse
    {
        return $cache->respond($request, 'labels:index', fn () => $catalog->labels($request));
    }

    public function label(PublicCatalogShowRequest $request, string $label, PublicCatalog $catalog, PublicCatalogCache $cache): JsonResponse
    {
        return $cache->respond($request, "labels:{$label}", fn () => ['data' => $catalog->labelById($label)]);
    }

    public function artists(PublicCatalogIndexRequest $request, PublicCatalog $catalog, PublicCatalogCache $cache): JsonResponse
    {
        return $cache->respond($request, 'artists:index', fn () => $catalog->artists($request));
    }

    public function artist(PublicCatalogShowRequest $request, string $artist, PublicCatalog $catalog, PublicCatalogCache $cache): JsonResponse
    {
        return $cache->respond($request, "artists:{$artist}", fn () => ['data' => $catalog->artistById($artist)]);
    }

    public function releases(PublicCatalogIndexRequest $request, PublicCatalog $catalog, PublicCatalogCache $cache): JsonResponse
    {
        return $cache->respond($request, 'releases:index', fn () => $catalog->releases($request));
    }

    public function recent(PublicCatalogIndexRequest $request, PublicCatalog $catalog, PublicCatalogCache $cache): JsonResponse
    {
        return $cache->respond($request, 'releases:recent', fn () => $catalog->releases($request));
    }

    public function featured(PublicCatalogIndexRequest $request, PublicCatalog $catalog, PublicCatalogCache $cache): JsonResponse
    {
        return $cache->respond($request, 'releases:featured', fn () => $catalog->releases($request, true));
    }

    public function release(PublicCatalogShowRequest $request, string $release, PublicCatalog $catalog, PublicCatalogCache $cache): JsonResponse
    {
        return $cache->respond($request, "releases:{$release}", fn () => ['data' => $catalog->releaseById($release)]);
    }

    public function track(PublicCatalogShowRequest $request, string $track, PublicCatalog $catalog, PublicCatalogCache $cache): JsonResponse
    {
        return $cache->respond($request, "tracks:{$track}", fn () => ['data' => $catalog->trackById($track)]);
    }
}
