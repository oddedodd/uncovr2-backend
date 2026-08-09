<?php

namespace Tests\Feature\Operations;

use App\Logging\RedactSensitiveLogContext;
use App\Models\EmailDelivery;
use App\Services\Operations\OperationalHealthService;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use RuntimeException;
use Tests\TestCase;

class OperationsMonitoringTest extends TestCase
{
    public function test_operational_check_detects_queue_provider_bounce_and_complaint_failures(): void
    {
        config([
            'operations.minimum_email_volume' => 2,
            'operations.max_provider_failures' => 0,
            'operations.max_queue_failures' => 0,
            'operations.max_bounce_rate' => 0.1,
            'operations.max_complaint_rate' => 0.1,
        ]);

        $this->event('email.bounced', 'bounced');
        $this->event('email.complained', 'complained');
        $this->event('email.failed', 'failed');
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'emails',
            'payload' => '{}',
            'exception' => 'RuntimeException',
            'failed_at' => now(),
        ]);

        $result = $this->app->make(OperationalHealthService::class)->check(false);

        $this->assertSame('alert', $result['status']);
        $this->assertSame(3, $result['metrics']['email_volume']);
        $this->assertSame(1, $result['metrics']['queue_failures']);
        $this->assertEqualsCanonicalizing([
            'queue_failures',
            'email_provider_failures',
            'email_bounce_rate',
            'email_complaint_rate',
        ], array_column($result['alerts'], 'code'));

        $this->artisan('operations:check', ['--json' => true, '--no-alert' => true])
            ->assertFailed()
            ->expectsOutputToContain('"status":"alert"');
    }

    public function test_operational_check_is_healthy_without_recent_failures(): void
    {
        $result = $this->app->make(OperationalHealthService::class)->check(false);

        $this->assertSame('ok', $result['status']);
        $this->assertSame([], $result['alerts']);
        $this->artisan('operations:check', ['--json' => true, '--no-alert' => true])->assertSuccessful();
    }

    public function test_log_processor_redacts_secrets_personal_data_and_exception_context(): void
    {
        $handler = new TestHandler(Level::Debug);
        $monolog = new Logger('test', [$handler]);
        $logger = new IlluminateLogger($monolog);
        (new RedactSensitiveLogContext)($logger);

        $logger->error(
            'Failed for person@example.com using Bearer private.jwt.token and whsec_abc123',
            [
                'password' => 'unsafe',
                'nested' => ['api_key' => 're_unsafe', 'metric' => 3],
                'exception' => new RuntimeException('Token for other@example.com was re_secret'),
            ],
        );

        $record = $handler->getRecords()[0];
        $encoded = json_encode($record->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('person@example.com', $encoded);
        $this->assertStringNotContainsString('other@example.com', $encoded);
        $this->assertStringNotContainsString('private.jwt.token', $encoded);
        $this->assertStringNotContainsString('whsec_abc123', $encoded);
        $this->assertStringNotContainsString('re_secret', $encoded);
        $this->assertStringNotContainsString('unsafe', $encoded);
        $this->assertStringContainsString('[redacted-email]', $encoded);
        $this->assertSame(3, $record->context['nested']['metric']);
    }

    public function test_release_check_validates_migrations_tables_and_production_configuration(): void
    {
        config([
            'app.debug' => false,
            'app.url' => 'https://api.uncovr.test',
            'mail.default' => 'resend',
            'services.resend.key' => 're_test_only',
            'email.webhook.secret' => 'whsec_'.base64_encode(str_repeat('a', 32)),
            'email.webhook.url' => 'https://api.uncovr.test/api/v1/webhooks/resend',
            'email.credential_rotation.api_key_rotated_at' => now()->toDateString(),
            'email.credential_rotation.webhook_secret_rotated_at' => now()->toDateString(),
            'queue.default' => 'database',
            'logging.channels.stack.channels' => ['stderr'],
            'services.supabase.url' => 'https://project.supabase.co',
            'services.supabase.secret_key' => 'sb_secret_test_only',
        ]);

        $this->artisan('release:check', ['--production-like' => true, '--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"status":"ready"');
    }

    public function test_release_check_fails_closed_for_incomplete_production_configuration(): void
    {
        config([
            'app.debug' => true,
            'app.url' => 'http://localhost',
            'mail.default' => 'array',
            'services.resend.key' => null,
            'email.webhook.secret' => null,
            'email.webhook.url' => null,
            'queue.default' => 'database',
            'logging.channels.stack.channels' => ['single'],
            'services.supabase.url' => null,
            'services.supabase.secret_key' => null,
        ]);

        $this->artisan('release:check', ['--production-like' => true, '--json' => true])
            ->assertFailed()
            ->expectsOutputToContain('"status":"failed"');
    }

    private function event(string $eventType, string $status): void
    {
        $delivery = EmailDelivery::query()->create([
            'provider_message_id' => (string) Str::uuid(),
            'status' => $status,
            'last_event_at' => now(),
            'terminal_at' => now(),
        ]);
        $delivery->events()->create([
            'svix_id' => (string) Str::uuid(),
            'event_type' => $eventType,
            'event_occurred_at' => now(),
            'processed_at' => now(),
        ]);
    }
}
