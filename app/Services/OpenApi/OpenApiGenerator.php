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
            'parameters' => [
                ...array_map(fn (string $parameter): array => [
                    'name' => $parameter,
                    'in' => 'path',
                    'required' => true,
                    'schema' => ['type' => 'string'],
                ], $matches[1]),
                ...$this->queryParameters($name, $method),
            ],
            'responses' => $this->responses($method, $authenticated),
        ];

        if ($authenticated) {
            $operation['security'] = [['bearerAuth' => []], ['cookieAuth' => []]];
        }

        if (in_array($method, ['post', 'put', 'patch'], true)) {
            $operation['requestBody'] = [
                'required' => true,
                'content' => ['application/json' => ['schema' => $this->requestSchema($name)]],
            ];
        }

        return $operation;
    }

    /** @return array<int, array<string, mixed>> */
    private function queryParameters(string $name, string $method): array
    {
        if ($method !== 'get') {
            return [];
        }

        $filters = match (true) {
            str_ends_with($name, 'users.index') => [
                $this->queryParameter('filter[search]', ['type' => 'string', 'minLength' => 2, 'maxLength' => 100]),
                $this->queryParameter('filter[status]', ['type' => 'string', 'enum' => ['active', 'suspended']]),
            ],
            str_ends_with($name, 'organizations.index'), str_ends_with($name, 'artists.index') => [
                $this->queryParameter('filter[search]', ['type' => 'string', 'minLength' => 2, 'maxLength' => 100]),
                $this->queryParameter('filter[status]', ['type' => 'string', 'enum' => ['active', 'suspended']]),
            ],
            str_ends_with($name, 'releases.index') && ! str_contains($name, '.public.') => [
                $this->queryParameter('filter[search]', ['type' => 'string', 'minLength' => 2, 'maxLength' => 100]),
                $this->queryParameter('filter[status]', ['type' => 'string', 'enum' => ['draft', 'review', 'scheduled', 'published', 'unpublished', 'archived']]),
                $this->queryParameter('filter[type]', ['type' => 'string', 'enum' => ['album', 'ep', 'single']]),
            ],
            default => [],
        };

        if ($filters === []) {
            return [];
        }

        return [
            ...$filters,
            $this->queryParameter('page[size]', ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25]),
            $this->queryParameter('page[after]', ['type' => 'string']),
            $this->queryParameter('page[before]', ['type' => 'string']),
        ];
    }

    /** @param array<string, mixed> $schema */
    private function queryParameter(string $name, array $schema): array
    {
        return [
            'name' => $name,
            'in' => 'query',
            'required' => false,
            'schema' => $schema,
        ];
    }

    /** @return array<string, mixed> */
    private function requestSchema(string $name): array
    {
        if (str_ends_with($name, 'platform.organization-onboardings.store')) {
            return [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['organization', 'administrator', 'confirmation'],
                'properties' => [
                    'organization' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name'],
                        'properties' => [
                            'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 150],
                            'legal_name' => ['type' => ['string', 'null'], 'maxLength' => 200],
                            'description' => ['type' => ['string', 'null'], 'maxLength' => 5000],
                            'website_url' => ['type' => ['string', 'null'], 'format' => 'uri'],
                        ],
                    ],
                    'administrator' => $this->administratorSchema(),
                    'confirmation' => ['type' => 'boolean', 'const' => true],
                ],
            ];
        }

        if (str_ends_with($name, 'organizations.artist-onboardings.store')) {
            return [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['artist', 'administrator', 'confirmation'],
                'properties' => [
                    'artist' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name'],
                        'properties' => [
                            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
                            'biography' => ['type' => ['string', 'null'], 'maxLength' => 10000],
                            'website_url' => ['type' => ['string', 'null'], 'format' => 'uri'],
                        ],
                    ],
                    'administrator' => $this->administratorSchema(),
                    'relationship_type' => ['type' => 'string', 'enum' => ['managing_label', 'distributor'], 'default' => 'managing_label'],
                    'creator_role' => ['type' => ['string', 'null'], 'enum' => ['artist_admin', 'artist_user', null]],
                    'confirmation' => ['type' => 'boolean', 'const' => true],
                ],
            ];
        }

        if (str_ends_with($name, 'artists.invitations.store')) {
            return $this->invitationSchema(['artist_admin', 'artist_user']);
        }

        if (str_ends_with($name, 'artists.store')) {
            return [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['name'],
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
                    'biography' => ['type' => ['string', 'null'], 'maxLength' => 10000],
                    'website_url' => ['type' => ['string', 'null'], 'format' => 'uri'],
                    'creator_role' => ['type' => ['string', 'null'], 'enum' => ['artist_admin', 'artist_user', null]],
                ],
            ];
        }

        if (str_ends_with($name, 'artist-invitations.accept')) {
            return [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['token'],
                'properties' => ['token' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64]],
            ];
        }

        if (str_ends_with($name, 'users.status.update')) {
            return [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['status', 'reason', 'confirmation'],
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['active', 'suspended']],
                    'reason' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 500],
                    'confirmation' => ['type' => 'string', 'description' => 'Public ID of the target user.'],
                ],
            ];
        }

        if (str_ends_with($name, 'users.organization-memberships.role.update')) {
            return $this->roleCorrectionSchema(['label_admin', 'label_user']);
        }

        if (str_ends_with($name, 'users.artist-memberships.role.update')) {
            return $this->roleCorrectionSchema(['artist_admin', 'artist_user']);
        }

        return ['$ref' => '#/components/schemas/JsonObject'];
    }

    /** @return array<string, mixed> */
    private function administratorSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['email'],
            'properties' => ['email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 254]],
        ];
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, mixed>
     */
    private function invitationSchema(array $roles): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['email', 'role'],
            'properties' => [
                'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 254],
                'role' => ['type' => 'string', 'enum' => $roles],
            ],
        ];
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, mixed>
     */
    private function roleCorrectionSchema(array $roles): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['role', 'reason', 'confirmation'],
            'properties' => [
                'role' => ['type' => 'string', 'enum' => $roles],
                'reason' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 500],
                'confirmation' => ['type' => 'string', 'description' => 'Public ID of the target user.'],
            ],
        ];
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
