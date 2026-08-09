<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\AddSecurityHeaders;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class CorsAndSecurityTest extends TestCase
{
    public function test_an_allowed_portal_origin_receives_credentialed_cors_headers(): void
    {
        $this->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/v1')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true')
            ->assertHeader('Access-Control-Expose-Headers', 'X-Request-ID');
    }

    public function test_an_untrusted_origin_is_not_granted_its_origin(): void
    {
        $response = $this->withHeader('Origin', 'https://attacker.example')
            ->getJson('/api/v1')
            ->assertOk();

        $this->assertNotSame(
            'https://attacker.example',
            $response->headers->get('Access-Control-Allow-Origin'),
        );
    }

    public function test_allowed_preflight_requests_are_handled_without_calling_the_endpoint(): void
    {
        $this->call('OPTIONS', '/api/v1', server: [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type, X-Request-ID',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true')
            ->assertHeader('Access-Control-Max-Age', '600');
    }

    public function test_api_success_and_error_responses_include_security_headers(): void
    {
        foreach (['/api/v1', '/api/v1/does-not-exist'] as $path) {
            $this->getJson($path)
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
                ->assertHeaderMissing('Strict-Transport-Security');
        }
    }

    public function test_hsts_is_added_only_to_secure_production_responses(): void
    {
        $application = Mockery::mock(Application::class);
        $application->shouldReceive('environment')
            ->once()
            ->with('production')
            ->andReturnTrue();

        $middleware = new AddSecurityHeaders($application);
        $request = Request::create('https://api.uncovr.example/api/v1');

        $response = $middleware->handle($request, fn () => new Response);

        $this->assertSame('max-age=31536000', $response->headers->get('Strict-Transport-Security'));
    }

    public function test_local_session_cookie_defaults_are_explicitly_safe_for_development(): void
    {
        $this->assertFalse(config('session.secure'));
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
        $this->assertSame(['^localhost$'], config('security.trusted_hosts'));
    }
}
