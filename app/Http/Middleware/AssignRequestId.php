<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignRequestId
{
    public const ATTRIBUTE = 'request_id';

    private const PREVIOUS_LOG_CONTEXT = '_previous_log_context';

    private const STARTED_AT = '_request_started_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*')) {
            return $next($request);
        }

        $requestId = $this->resolveRequestId($request);

        $request->attributes->set(self::ATTRIBUTE, $requestId);
        $request->attributes->set(self::PREVIOUS_LOG_CONTEXT, Log::sharedContext());
        $request->attributes->set(self::STARTED_AT, hrtime(true));

        Log::shareContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $requestId = $request->attributes->get(self::ATTRIBUTE);

        if (! is_string($requestId)) {
            return;
        }

        try {
            Log::info('HTTP request completed.', [
                'http_method' => $request->method(),
                'http_path' => '/'.$request->path(),
                'http_route' => $request->route()?->getName(),
                'http_status' => $response->getStatusCode(),
                'duration_ms' => $this->durationInMilliseconds($request),
            ]);
        } finally {
            $this->restoreLogContext($request);
        }
    }

    private function resolveRequestId(Request $request): string
    {
        $requestId = $request->headers->get('X-Request-ID');

        if (is_string($requestId) && Str::isUuid($requestId)) {
            return strtolower($requestId);
        }

        return (string) Str::uuid();
    }

    private function durationInMilliseconds(Request $request): float
    {
        $startedAt = $request->attributes->get(self::STARTED_AT);

        if (! is_int($startedAt)) {
            return 0.0;
        }

        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }

    private function restoreLogContext(Request $request): void
    {
        $previousContext = $request->attributes->get(self::PREVIOUS_LOG_CONTEXT, []);

        Log::withoutContext();
        Log::flushSharedContext();

        if (is_array($previousContext) && $previousContext !== []) {
            Log::shareContext($previousContext);
        }
    }
}
