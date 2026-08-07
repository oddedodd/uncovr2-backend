<?php

namespace Tests\Feature\Api;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    public function test_public_routes_are_limited_per_ip_with_the_api_error_format(): void
    {
        config(['rate_limiting.public_per_minute' => 2]);

        $this->getJson('/api/v1')->assertOk();
        $this->getJson('/api/v1')->assertOk();

        $this->getJson('/api/v1')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertHeader('X-Request-ID')
            ->assertExactJson([
                'error' => [
                    'code' => 'too_many_requests',
                    'message' => 'Too many requests. Please try again later.',
                ],
            ]);
    }

    public function test_public_limits_are_isolated_by_ip_address(): void
    {
        config(['rate_limiting.public_per_minute' => 1]);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->getJson('/api/v1')
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])
            ->getJson('/api/v1')
            ->assertOk();
    }

    public function test_authentication_routes_have_ip_and_hashed_identity_limits(): void
    {
        config([
            'rate_limiting.authentication_per_ip_per_minute' => 10,
            'rate_limiting.authentication_per_identity_per_minute' => 2,
        ]);

        Route::post(
            '/api/v1/testing/authentication',
            fn () => ApiResponse::success(null),
        )->middleware('throttle:authentication');

        $this->postJson('/api/v1/testing/authentication', ['email' => 'Artist@Example.com'])
            ->assertOk();
        $this->postJson('/api/v1/testing/authentication', ['email' => ' artist@example.com '])
            ->assertOk();
        $this->postJson('/api/v1/testing/authentication', ['email' => 'ARTIST@example.com'])
            ->assertTooManyRequests();

        $this->postJson('/api/v1/testing/authentication', ['email' => 'another@example.com'])
            ->assertOk();

        $limiter = RateLimiter::limiter('authentication');
        $limits = $limiter(Request::create(
            '/api/v1/testing/authentication',
            'POST',
            ['email' => 'artist@example.com'],
        ));

        $this->assertCount(2, $limits);
        $this->assertStringNotContainsString('artist@example.com', $limits[1]->key);
    }

    public function test_authenticated_limits_use_the_user_identifier(): void
    {
        $user = new User;
        $user->forceFill(['id' => 42]);

        $request = Request::create('/api/v1/testing/authenticated');
        $request->setUserResolver(fn () => $user);

        $limiter = RateLimiter::limiter('authenticated');
        $limit = $limiter($request);

        $this->assertSame('authenticated:user:42', $limit->key);
        $this->assertSame(120, $limit->maxAttempts);
    }

    public function test_authentication_ip_limit_applies_across_different_identities(): void
    {
        config([
            'rate_limiting.authentication_per_ip_per_minute' => 2,
            'rate_limiting.authentication_per_identity_per_minute' => 10,
        ]);

        Route::post(
            '/api/v1/testing/authentication-ip',
            fn () => ApiResponse::success(null),
        )->middleware('throttle:authentication');

        $this->postJson('/api/v1/testing/authentication-ip', ['email' => 'one@example.com'])
            ->assertOk();
        $this->postJson('/api/v1/testing/authentication-ip', ['email' => 'two@example.com'])
            ->assertOk();
        $this->postJson('/api/v1/testing/authentication-ip', ['email' => 'three@example.com'])
            ->assertTooManyRequests();
    }

    public function test_authenticated_users_receive_separate_quotas(): void
    {
        config(['rate_limiting.authenticated_per_minute' => 1]);

        Route::get(
            '/api/v1/testing/authenticated',
            fn () => ApiResponse::success(null),
        )->middleware('throttle:authenticated');

        $firstUser = (new User)->forceFill(['id' => 1]);
        $secondUser = (new User)->forceFill(['id' => 2]);

        $this->actingAs($firstUser)
            ->getJson('/api/v1/testing/authenticated')
            ->assertOk();
        $this->getJson('/api/v1/testing/authenticated')
            ->assertTooManyRequests();

        $this->actingAs($secondUser)
            ->getJson('/api/v1/testing/authenticated')
            ->assertOk();
    }

    public function test_health_checks_are_not_application_rate_limited(): void
    {
        config(['rate_limiting.public_per_minute' => 1]);

        $this->getJson('/api/v1/health/live')->assertOk();
        $this->getJson('/api/v1/health/live')->assertOk();
    }
}
