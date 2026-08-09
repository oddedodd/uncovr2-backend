<?php

namespace Tests\Feature\Api;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

class SanctumAuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/v1/testing/protected', fn () => ApiResponse::success([
            'user_id' => request()->user()->getKey(),
        ]))->middleware('auth:sanctum');
    }

    public function test_api_requests_are_prepared_for_stateful_spa_authentication(): void
    {
        $apiMiddleware = app(Router::class)->getMiddlewareGroups()['api'];

        $this->assertContains(EnsureFrontendRequestsAreStateful::class, $apiMiddleware);
        $this->assertContains('localhost:5173', config('sanctum.stateful'));
        $this->assertSame(['web'], config('sanctum.guard'));
        $this->assertSame('uncovr_', config('sanctum.token_prefix'));
    }

    public function test_a_protected_api_route_rejects_an_unauthenticated_request(): void
    {
        $this->assertApiError(
            $this->getApi('/testing/protected'),
            status: 401,
            code: 'unauthenticated',
            message: 'Authentication is required.',
        );
    }

    public function test_a_sanctum_bearer_token_authenticates_without_storing_plaintext(): void
    {
        $user = User::factory()->create();
        $newToken = $user->createToken('test-device');

        $this->assertStringStartsWith(
            $newToken->accessToken->getKey().'|uncovr_',
            $newToken->plainTextToken,
        );
        $this->assertNotSame(
            $newToken->plainTextToken,
            $newToken->accessToken->token,
        );

        $this->assertApiSuccess(
            $this->getApi('/testing/protected', [
                'Authorization' => 'Bearer '.$newToken->plainTextToken,
            ]),
            data: ['user_id' => $user->getKey()],
        );
    }
}
