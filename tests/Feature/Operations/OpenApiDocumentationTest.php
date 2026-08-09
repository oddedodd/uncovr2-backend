<?php

namespace Tests\Feature\Operations;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class OpenApiDocumentationTest extends TestCase
{
    public function test_committed_openapi_document_is_current_and_covers_every_api_route(): void
    {
        $this->assertSame(0, Artisan::call('api:openapi', ['--check' => true]), Artisan::output());

        $document = json_decode((string) file_get_contents(base_path('docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame(
            ['organization', 'administrator', 'confirmation'],
            $document['paths']['/api/v1/platform/organization-onboardings']['post']['requestBody']['content']['application/json']['schema']['required'],
        );
        $this->assertSame(
            ['artist_admin', 'artist_user'],
            $document['paths']['/api/v1/artists/{artist}/invitations']['post']['requestBody']['content']['application/json']['schema']['properties']['role']['enum'],
        );

        collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1'))
            ->each(function (Route $route) use ($document): void {
                $path = '/'.preg_replace('/\{([^}:]+)(?::[^}]+)?\}/', '{$1}', $route->uri());

                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $this->assertArrayHasKey(strtolower($method), $document['paths'][$path], $method.' '.$path);
                }
            });
    }
}
