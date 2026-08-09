<?php

namespace App\Services\OpenApi;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

class OpenApiGenerator
{
    public function __construct(private readonly Router $router) {}

    /** @return array<string, mixed> */
    public function generate(): array
    {
        $paths = [];

        collect($this->router->getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1'))
            ->sortBy(fn (Route $route): string => $route->uri().'|'.implode(',', $route->methods()))
            ->each(function (Route $route) use (&$paths): void {
                $path = '/'.preg_replace('/\{([^}:]+)(?::[^}]+)?\}/', '{$1}', $route->uri());

                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $paths[$path][strtolower($method)] = $this->operation($route, strtolower($method), $path);
                }
            });

        ksort($paths);
        foreach ($paths as &$operations) {
            ksort($operations);
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Uncovr Backend API',
                'version' => '1.0.0',
                'description' => 'Machine-readable contract for the Laravel backend. Resource-specific validation remains authoritative in the API.',
            ],
            'servers' => [['url' => 'https://api.uncovr.no', 'description' => 'Production']],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                    'cookieAuth' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'uncovr_access_token'],
                ],
                'schemas' => [
                    'JsonObject' => ['type' => 'object', 'additionalProperties' => true],
                    'Error' => [
                        'type' => 'object',
                        'required' => ['message'],
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'errors' => ['type' => 'object', 'additionalProperties' => true],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function operation(Route $route, string $method, string $path): array
    {
        $name = $route->getName() ?: str($method.' '.$path)->slug('.')->toString();
        $middleware = $route->gatherMiddleware();
        $authenticated = collect($middleware)->contains(fn (string $item): bool => str_contains($item, 'Authenticate:sanctum') || $item === 'auth:sanctum');
        preg_match_all('/\{([^}]+)\}/', $path, $matches);

        $operation = [
            'operationId' => str_replace('.', '_', $name),
            'tags' => [$this->tag($path)],
            'summary' => str($name)->after('api.v1.')->replace('.', ' ')->headline()->toString(),
            'parameters' => array_map(fn (string $parameter): array => [
                'name' => $parameter,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ], $matches[1]),
            'responses' => $this->responses($method, $authenticated),
        ];

        if ($authenticated) {
            $operation['security'] = [['bearerAuth' => []], ['cookieAuth' => []]];
        }

        if (in_array($method, ['post', 'put', 'patch'], true)) {
            $operation['requestBody'] = [
                'required' => true,
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/JsonObject']]],
            ];
        }

        return $operation;
    }

    /** @return array<string, mixed> */
    private function responses(string $method, bool $authenticated): array
    {
        $success = $method === 'post' ? '201' : ($method === 'delete' ? '204' : '200');
        $responses = [
            $success => ['description' => 'Successful response'],
            '422' => [
                'description' => 'Validation error',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
            ],
            '429' => ['description' => 'Rate limit exceeded'],
        ];

        if ($authenticated) {
            $responses = ['401' => ['description' => 'Unauthenticated'], '403' => ['description' => 'Forbidden']] + $responses;
        }

        ksort($responses);

        return $responses;
    }

    private function tag(string $path): string
    {
        $segments = explode('/', trim($path, '/'));

        return str($segments[2] ?? 'root')->headline()->toString();
    }
}
