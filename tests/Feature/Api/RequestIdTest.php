<?php

namespace Tests\Feature\Api;

use Illuminate\Log\Formatters\JsonFormatter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class RequestIdTest extends TestCase
{
    public function test_api_responses_receive_a_generated_request_id(): void
    {
        $requestId = $this->getJson('/api/v1')->headers->get('X-Request-ID');

        $this->assertIsString($requestId);
        $this->assertTrue(Str::isUuid($requestId));
    }

    public function test_a_valid_client_request_id_is_preserved(): void
    {
        $requestId = (string) Str::uuid();

        $this->withHeader('X-Request-ID', $requestId)
            ->getJson('/api/v1')
            ->assertHeader('X-Request-ID', $requestId);
    }

    public function test_an_invalid_client_request_id_is_replaced(): void
    {
        $requestId = $this->withHeader('X-Request-ID', "invalid\nvalue")
            ->getJson('/api/v1')
            ->headers->get('X-Request-ID');

        $this->assertIsString($requestId);
        $this->assertNotSame("invalid\nvalue", $requestId);
        $this->assertTrue(Str::isUuid($requestId));
    }

    public function test_error_responses_keep_the_request_id(): void
    {
        $requestId = (string) Str::uuid();

        $this->withHeader('X-Request-ID', $requestId)
            ->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertHeader('X-Request-ID', $requestId);
    }

    public function test_request_log_context_is_cleared_after_termination(): void
    {
        $this->getJson('/api/v1')->assertOk();

        $this->assertSame([], Log::sharedContext());
    }

    public function test_a_structured_completion_event_is_logged(): void
    {
        Log::spy();

        $requestId = (string) Str::uuid();

        $this->withHeader('X-Request-ID', $requestId)
            ->getJson('/api/v1')
            ->assertOk();

        Log::shouldHaveReceived('shareContext')
            ->once()
            ->with(['request_id' => $requestId]);

        Log::shouldHaveReceived('info')
            ->once()
            ->with('HTTP request completed.', Mockery::on(
                fn (array $context) => $context['http_method'] === 'GET'
                    && $context['http_path'] === '/api/v1'
                    && $context['http_route'] === 'api.v1.index'
                    && $context['http_status'] === 200
                    && is_float($context['duration_ms']),
            ));
    }

    public function test_application_file_logs_use_json_formatting(): void
    {
        $this->assertSame(JsonFormatter::class, config('logging.channels.single.formatter'));
        $this->assertSame(JsonFormatter::class, config('logging.channels.daily.formatter'));
        $this->assertSame(JsonFormatter::class, config('logging.channels.stderr.formatter'));
    }
}
