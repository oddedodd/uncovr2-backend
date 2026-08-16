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
                    'MediaReference' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['id', 'status', 'mime_type', 'width', 'height'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'status' => ['type' => 'string', 'enum' => ['ready']],
                            'mime_type' => ['type' => 'string'],
                            'width' => ['type' => ['integer', 'null']],
                            'height' => ['type' => ['integer', 'null']],
                        ],
                    ],
                    'ContentBlock' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['id', 'page_id', 'position', 'type', 'version', 'payload'],
                        'properties' => [
                            'id' => $this->ulidSchema(),
                            'page_id' => $this->ulidSchema(),
                            'position' => ['type' => 'integer', 'minimum' => 1],
                            'type' => ['type' => 'string', 'enum' => ['heading', 'text', 'image', 'gallery', 'video', 'quote', 'lyrics']],
                            'version' => ['type' => 'integer', 'minimum' => 1],
                            'payload' => ['type' => 'object', 'additionalProperties' => true],
                        ],
                    ],
                    'ReleasePage' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['id', 'parent', 'position', 'title', 'blocks'],
                        'properties' => [
                            'id' => $this->ulidSchema(),
                            'parent' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['type', 'id'],
                                'properties' => [
                                    'type' => ['type' => 'string', 'const' => 'release'],
                                    'id' => $this->ulidSchema(),
                                ],
                            ],
                            'position' => ['type' => 'integer', 'minimum' => 1],
                            'title' => ['type' => ['string', 'null'], 'maxLength' => 200],
                            'blocks' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ContentBlock']],
                        ],
                    ],
                    'ReleaseEditor' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['user_id', 'display_name'],
                        'properties' => [
                            'user_id' => $this->ulidSchema(),
                            'display_name' => ['type' => ['string', 'null']],
                        ],
                    ],
                    'ReleasePermissions' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'description' => 'Role capability for the requesting user, not state-machine validity. A true flag means the user is allowed to attempt the action; the service still rejects illegal transitions.',
                        'required' => ['can_update', 'can_submit', 'can_delete', 'can_approve', 'can_publish', 'can_manage_editors'],
                        'properties' => [
                            'can_update' => ['type' => 'boolean'],
                            'can_submit' => ['type' => 'boolean'],
                            'can_delete' => ['type' => 'boolean'],
                            'can_approve' => ['type' => 'boolean'],
                            'can_publish' => ['type' => 'boolean'],
                            'can_manage_editors' => ['type' => 'boolean'],
                        ],
                    ],
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
            'responses' => $this->responses($name, $method, $authenticated),
        ];

        if ($authenticated) {
            $operation['security'] = [['bearerAuth' => []], ['cookieAuth' => []]];
        }

        if ($this->isPortalReleaseBuilderOperation($name)) {
            $operation['x-uncovr-contract'] = 'portal-release-builder';
        } elseif ($this->isTrackOperation($path)) {
            $operation['x-uncovr-contract'] = 'legacy-track-compatibility';
            $operation['description'] = 'Outside the portal release-builder contract. Retained temporarily for compatibility with the legacy listener and published-track domain.';
            if ($this->isLegacyTrackMutation($name)) {
                $operation['deprecated'] = true;
            }
        }

        if (in_array($method, ['post', 'put', 'patch'], true)) {
            if ($this->isProfileImageUpload($name)) {
                $operation['requestBody'] = [
                    'required' => true,
                    'content' => ['multipart/form-data' => ['schema' => $this->profileImageUploadSchema()]],
                ];

                return $operation;
            }

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
                $this->queryParameter('filter[artist_id]', ['type' => 'string', 'pattern' => '^[0-9A-Za-z]{26}$']),
                $this->queryParameter('filter[owner_type]', ['type' => 'string', 'enum' => ['organization', 'artist']]),
                $this->queryParameter('filter[owner_id]', ['type' => 'string', 'pattern' => '^[0-9A-Za-z]{26}$']),
                $this->queryParameter('filter[assigned_to_me]', ['type' => 'boolean']),
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
        if (str_ends_with($name, 'organizations.update')) {
            return [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 150],
                    'legal_name' => ['type' => ['string', 'null'], 'maxLength' => 200],
                    'description' => ['type' => ['string', 'null'], 'maxLength' => 5000],
                    'website_url' => ['type' => ['string', 'null'], 'format' => 'uri'],
                    'logo_media_id' => $this->nullableUlidSchema(),
                ],
            ];
        }

        if (str_ends_with($name, 'artists.update')) {
            return [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
                    'biography' => ['type' => ['string', 'null'], 'maxLength' => 10000],
                    'website_url' => ['type' => ['string', 'null'], 'format' => 'uri'],
                    'logo_media_id' => $this->nullableUlidSchema(),
                    'image_media_id' => $this->nullableUlidSchema(),
                ],
            ];
        }

        if (str_ends_with($name, 'releases.store')) {
            return $this->releaseSchema(false);
        }

        if (str_ends_with($name, 'releases.update')) {
            return $this->releaseSchema(true);
        }

        if (str_ends_with($name, 'media.downloads.store')) {
            return [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['media_ids'],
                'properties' => [
                    'media_ids' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => config('media.batch_download_limit'),
                        'uniqueItems' => true,
                        'items' => ['type' => 'string', 'pattern' => '^[0-9A-Za-z]{26}$'],
                    ],
                ],
            ];
        }

        if (str_ends_with($name, 'releases.pages.store')) {
            return $this->pageSchema(false);
        }

        if (str_ends_with($name, 'pages.update')) {
            return $this->pageSchema(true);
        }

        if (str_ends_with($name, 'pages.blocks.store')) {
            return $this->contentBlockSchema(false);
        }

        if (str_ends_with($name, 'pages.blocks.update')) {
            return $this->contentBlockSchema(true);
        }

        if (str_ends_with($name, 'releases.pages.order')) {
            return $this->orderSchema('page_ids', 'Every page of the release exactly once, in the wanted order.');
        }

        if (str_ends_with($name, 'pages.blocks.order')) {
            return $this->orderSchema('block_ids', 'Every block on the page exactly once, in the wanted order.');
        }

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

    private function isProfileImageUpload(string $name): bool
    {
        return str_ends_with($name, 'organizations.logo.store')
            || str_ends_with($name, 'artists.logo.store')
            || str_ends_with($name, 'artists.image.store');
    }

    /** @return array<string, mixed> */
    private function profileImageUploadSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['image'],
            'properties' => [
                'image' => [
                    'type' => 'string',
                    'format' => 'binary',
                    'description' => 'JPEG, PNG, WebP or AVIF image within configured media limits.',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function nullableUlidSchema(): array
    {
        return [
            'type' => ['string', 'null'],
            'pattern' => '^[0-9A-Za-z]{26}$',
        ];
    }

    /** @return array<string, mixed> */
    private function ulidSchema(): array
    {
        return [
            'type' => 'string',
            'pattern' => '^[0-9A-Za-z]{26}$',
        ];
    }

    /** @return array<string, mixed> */
    private function pageSchema(bool $update): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            ...($update ? [] : ['required' => ['position']]),
            'properties' => [
                'position' => ['type' => 'integer', 'minimum' => 1],
                'title' => ['type' => ['string', 'null'], 'maxLength' => 200],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function orderSchema(string $field, string $description): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [$field],
            'properties' => [
                $field => [
                    'type' => 'array',
                    'minItems' => 1,
                    'uniqueItems' => true,
                    'description' => $description,
                    'items' => $this->ulidSchema(),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function contentBlockSchema(bool $update): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            ...($update ? [] : ['required' => ['position', 'type', 'payload']]),
            'properties' => [
                'position' => ['type' => 'integer', 'minimum' => 1],
                'type' => ['type' => 'string', 'enum' => ['heading', 'text', 'image', 'gallery', 'video', 'quote', 'lyrics']],
                'payload' => ['type' => 'object', 'additionalProperties' => true],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function releaseSchema(bool $update): array
    {
        $properties = [
            'type' => ['type' => 'string', 'enum' => ['album', 'ep', 'single']],
            'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
            'subtitle' => ['type' => ['string', 'null'], 'maxLength' => 200],
            'description' => ['type' => ['string', 'null'], 'maxLength' => 10000],
            'release_date' => ['type' => ['string', 'null'], 'format' => 'date'],
            'upc' => ['type' => ['string', 'null'], 'pattern' => '^[0-9]{12,14}$'],
            'cover_media_id' => $this->nullableUlidSchema(),
        ];

        if (! $update) {
            $properties = [
                'owner_type' => ['type' => 'string', 'enum' => ['organization', 'artist']],
                'owner_id' => ['type' => 'string', 'pattern' => '^[0-9A-Za-z]{26}$'],
                'primary_artist_id' => ['type' => 'string', 'pattern' => '^[0-9A-Za-z]{26}$'],
                ...$properties,
            ];
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            ...($update ? [] : ['required' => ['owner_type', 'owner_id', 'primary_artist_id', 'type', 'title']]),
            'properties' => $properties,
        ];
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
    private function responses(string $name, string $method, bool $authenticated): array
    {
        $success = $method === 'post' && ! str_ends_with($name, 'media.downloads.store')
            ? '201'
            : '200';
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

        $responseSchema = $this->successResponseSchema($name);
        if ($responseSchema !== null) {
            $responses[$success]['content'] = ['application/json' => ['schema' => $responseSchema]];
        }

        ksort($responses);

        return $responses;
    }

    /** @return array<string, mixed>|null */
    private function successResponseSchema(string $name): ?array
    {
        $profileProperties = match (true) {
            str_ends_with($name, 'organizations.update'), str_ends_with($name, 'organizations.logo.store') => [
                'logo_media_id' => $this->nullableUlidSchema(),
                'logo_media' => ['anyOf' => [['$ref' => '#/components/schemas/MediaReference'], ['type' => 'null']]],
            ],
            str_ends_with($name, 'artists.update'), str_ends_with($name, 'artists.logo.store'), str_ends_with($name, 'artists.image.store') => [
                'logo_media_id' => $this->nullableUlidSchema(),
                'image_media_id' => $this->nullableUlidSchema(),
                'logo_media' => ['anyOf' => [['$ref' => '#/components/schemas/MediaReference'], ['type' => 'null']]],
                'image_media' => ['anyOf' => [['$ref' => '#/components/schemas/MediaReference'], ['type' => 'null']]],
            ],
            default => null,
        };

        if ($profileProperties !== null) {
            return [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                        'required' => ['profile'],
                        'properties' => [
                            'profile' => [
                                'type' => 'object',
                                'additionalProperties' => true,
                                'required' => array_keys($profileProperties),
                                'properties' => $profileProperties,
                            ],
                        ],
                    ],
                ],
            ];
        }

        if (str_ends_with($name, 'releases.store') || str_ends_with($name, 'releases.update')) {
            return [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                        'required' => ['cover_media_id', 'cover_media', 'pages', 'editors', 'permissions'],
                        'properties' => [
                            'cover_media_id' => $this->nullableUlidSchema(),
                            'cover_media' => ['anyOf' => [['$ref' => '#/components/schemas/MediaReference'], ['type' => 'null']]],
                            'pages' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ReleasePage']],
                            'editors' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ReleaseEditor']],
                            'permissions' => ['$ref' => '#/components/schemas/ReleasePermissions'],
                        ],
                    ],
                ],
            ];
        }

        if (str_ends_with($name, 'media.downloads.store')) {
            return [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['expires_in', 'items'],
                        'properties' => [
                            'expires_in' => ['type' => 'integer'],
                            'items' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['media_id', 'url'],
                                    'properties' => [
                                        'media_id' => ['type' => 'string'],
                                        'url' => ['type' => 'string', 'format' => 'uri'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        if (str_ends_with($name, 'releases.pages.store') || str_ends_with($name, 'pages.update')) {
            return $this->dataResponseSchema('#/components/schemas/ReleasePage');
        }

        if (str_ends_with($name, 'pages.blocks.store') || str_ends_with($name, 'pages.blocks.update')) {
            return $this->dataResponseSchema('#/components/schemas/ContentBlock');
        }

        if (str_ends_with($name, 'releases.pages.order')) {
            return $this->dataCollectionResponseSchema('#/components/schemas/ReleasePage');
        }

        if (str_ends_with($name, 'pages.blocks.order')) {
            return $this->dataCollectionResponseSchema('#/components/schemas/ContentBlock');
        }

        if (str_ends_with($name, 'pages.blocks.versions')) {
            return [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['version', 'type', 'payload', 'created_at'],
                            'properties' => [
                                'version' => ['type' => 'integer', 'minimum' => 1],
                                'type' => ['type' => 'string', 'enum' => ['heading', 'text', 'image', 'gallery', 'video', 'quote', 'lyrics']],
                                'payload' => ['type' => 'object', 'additionalProperties' => true],
                                'created_at' => ['type' => 'string', 'format' => 'date-time'],
                            ],
                        ],
                    ],
                ],
            ];
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function dataResponseSchema(string $reference): array
    {
        return [
            'type' => 'object',
            'required' => ['data'],
            'properties' => ['data' => ['$ref' => $reference]],
        ];
    }

    /** @return array<string, mixed> */
    private function dataCollectionResponseSchema(string $reference): array
    {
        return [
            'type' => 'object',
            'required' => ['data'],
            'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => $reference]]],
        ];
    }

    private function isPortalReleaseBuilderOperation(string $name): bool
    {
        return str_ends_with($name, 'releases.pages.store')
            || str_ends_with($name, 'releases.pages.order')
            || str_ends_with($name, 'pages.update')
            || str_ends_with($name, 'pages.destroy')
            || str_contains($name, '.pages.blocks.');
    }

    private function isTrackOperation(string $path): bool
    {
        return str_contains($path, '/tracks') || str_contains($path, '{track}');
    }

    private function isLegacyTrackMutation(string $name): bool
    {
        return str_contains($name, '.releases.tracks.')
            || str_contains($name, '.tracks.pages.')
            || str_contains($name, '.tracks.streaming-links.')
            || str_contains($name, '.tracks.credits.');
    }

    private function tag(string $path): string
    {
        $segments = explode('/', trim($path, '/'));

        return str($segments[2] ?? 'root')->headline()->toString();
    }
}
