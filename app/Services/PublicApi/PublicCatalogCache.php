<?php

namespace App\Services\PublicApi;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class PublicCatalogCache
{
    private const VERSION_KEY = 'public-catalog:version';

    public function respond(Request $request, string $scope, Closure $resolver): JsonResponse
    {
        $version = (int) Cache::get(self::VERSION_KEY, 1);
        $query = $this->sortRecursively($request->query());
        $key = 'public-catalog:response:'.hash('sha256', $version.'|'.$scope.'|'.json_encode($query, JSON_THROW_ON_ERROR));
        $payload = Cache::remember($key, now()->addMinutes(5), $resolver);
        $etag = '"'.hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)).'"';
        $headers = [
            'Cache-Control' => 'public, max-age=60, s-maxage=300, stale-while-revalidate=600',
            'ETag' => $etag,
            'Vary' => 'Accept, Accept-Encoding',
        ];
        if ($request->headers->get('If-None-Match') === $etag) {
            return response()->json(null, 304, $headers);
        }

        return response()->json($payload, 200, $headers);
    }

    public function invalidate(): void
    {
        Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 1) + 1);
    }

    private function sortRecursively(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->sortRecursively($item);
            }
        }
        ksort($value);

        return $value;
    }
}
