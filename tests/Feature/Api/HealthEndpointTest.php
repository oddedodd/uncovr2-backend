<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_liveness_reports_that_the_process_is_running(): void
    {
        $this->getJson('/api/v1/health/live')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Request-ID')
            ->assertExactJson([
                'data' => [
                    'status' => 'ok',
                ],
            ]);
    }

    public function test_readiness_reports_that_required_dependencies_are_available(): void
    {
        $this->getJson('/api/v1/health/ready')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Request-ID')
            ->assertExactJson([
                'data' => [
                    'status' => 'ready',
                ],
            ]);
    }

    public function test_readiness_returns_a_safe_error_when_the_database_is_unavailable(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with('select 1')
            ->andThrow(new RuntimeException('Sensitive database connection details'));

        $this->getJson('/api/v1/health/ready')
            ->assertServiceUnavailable()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Request-ID')
            ->assertExactJson([
                'error' => [
                    'code' => 'service_unavailable',
                    'message' => 'The service is not ready.',
                ],
            ])
            ->assertDontSee('Sensitive database connection details');
    }

    public function test_the_framework_health_route_is_not_exposed_separately(): void
    {
        $this->get('/up')->assertNotFound();
    }
}
