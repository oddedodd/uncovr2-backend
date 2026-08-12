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
        $this->assertArrayHasKey(
            'logo_media_id',
            $document['paths']['/api/v1/organizations/{organization}']['patch']['requestBody']['content']['application/json']['schema']['properties'],
        );
        $this->assertArrayHasKey(
            'image_media_id',
            $document['paths']['/api/v1/artists/{artist}']['patch']['requestBody']['content']['application/json']['schema']['properties'],
        );
        $this->assertArrayHasKey(
            'cover_media_id',
            $document['paths']['/api/v1/releases']['post']['requestBody']['content']['application/json']['schema']['properties'],
        );
        $this->assertSame(
            100,
            $document['paths']['/api/v1/media/downloads']['post']['requestBody']['content']['application/json']['schema']['properties']['media_ids']['maxItems'],
        );
        $this->assertArrayHasKey('200', $document['paths']['/api/v1/media/downloads']['post']['responses']);
        $this->assertArrayNotHasKey('201', $document['paths']['/api/v1/media/downloads']['post']['responses']);
        $this->assertSame(
            'portal-release-builder',
            $document['paths']['/api/v1/releases/{release}/pages']['post']['x-uncovr-contract'],
        );
        $this->assertSame(
            'portal-release-builder',
            $document['paths']['/api/v1/pages/{page}/blocks/{block}']['patch']['x-uncovr-contract'],
        );
        $this->assertSame(
            '#/components/schemas/ReleasePage',
            $document['paths']['/api/v1/releases/{release}/pages']['post']['responses']['201']['content']['application/json']['schema']['properties']['data']['$ref'],
        );
        $this->assertSame(
            '#/components/schemas/ContentBlock',
            $document['paths']['/api/v1/pages/{page}/blocks/{block}']['patch']['responses']['200']['content']['application/json']['schema']['properties']['data']['$ref'],
        );
        $this->assertArrayHasKey('200', $document['paths']['/api/v1/pages/{page}/blocks/{block}']['delete']['responses']);
        $this->assertArrayNotHasKey('204', $document['paths']['/api/v1/pages/{page}/blocks/{block}']['delete']['responses']);
        $this->assertTrue($document['paths']['/api/v1/releases/{release}/tracks']['post']['deprecated']);
        $this->assertSame(
            'legacy-track-compatibility',
            $document['paths']['/api/v1/releases/{release}/tracks']['post']['x-uncovr-contract'],
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
