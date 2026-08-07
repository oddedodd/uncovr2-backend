<?php

namespace Tests\Feature\Auth;

use App\Models\SecurityAuditEvent;
use App\Models\User;
use App\Services\Auth\SecurityAuditLogger;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Tests\TestCase;

class SecurityAuditAndThrottlingTest extends TestCase
{
    public function test_security_audit_events_store_context_but_reject_secret_metadata(): void
    {
        $user = User::factory()->create();
        $request = Request::create('/api/v1/me', 'PATCH', server: [
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_USER_AGENT' => 'Uncovr Test Client',
        ]);
        $logger = app(SecurityAuditLogger::class);

        $event = $logger->record(
            'account.profile_updated',
            $user,
            $request,
            metadata: ['field' => 'display_name'],
        );

        $this->assertNotNull($event->public_id);
        $this->assertSame('192.0.2.10', $event->ip_address);
        $this->assertSame('Uncovr Test Client', $event->user_agent);
        $this->assertSame(['field' => 'display_name'], $event->metadata);

        $this->expectException(InvalidArgumentException::class);
        $logger->record('unsafe', $user, metadata: ['refresh_token' => 'secret']);
    }

    public function test_failed_login_has_a_safe_response_and_token_free_audit_event(): void
    {
        $user = User::factory()->create([
            'email' => 'artist@example.com',
            'password' => 'a secure passphrase',
        ]);

        $response = $this->postApi('/auth/login', [
            'email' => $user->email,
            'password' => 'incorrect password',
            'client_type' => 'mobile',
            'device' => ['name' => 'Test phone'],
        ]);

        $this->assertApiError(
            $response,
            401,
            'invalid_credentials',
            'The provided credentials are incorrect.',
        );
        $this->assertStringNotContainsString('incorrect password', $response->getContent());
        $event = SecurityAuditEvent::query()->where('event_type', 'auth.login_failed')->sole();
        $this->assertNull($event->metadata);
    }

    public function test_refresh_requests_are_limited_by_a_hash_not_the_plain_token(): void
    {
        config([
            'rate_limiting.refresh_per_ip_per_minute' => 100,
            'rate_limiting.refresh_per_token_per_minute' => 1,
        ]);
        $token = 'uncovr_refresh_'.str_repeat('A', 43);

        $this->assertApiError(
            $this->postApi('/auth/refresh', ['refresh_token' => $token]),
            401,
            'invalid_refresh_token',
        );
        $limited = $this->postApi('/auth/refresh', ['refresh_token' => $token]);
        $this->assertApiError($limited, 429, 'too_many_requests');
        $this->assertStringNotContainsString($token, $limited->getContent());
    }

    public function test_authentication_identity_limit_applies_across_ip_addresses(): void
    {
        config([
            'rate_limiting.authentication_per_ip_per_minute' => 100,
            'rate_limiting.authentication_per_identity_per_minute' => 1,
        ]);
        $payload = [
            'email' => 'target@example.com',
            'password' => 'incorrect password',
            'client_type' => 'mobile',
            'device' => ['name' => 'Test phone'],
        ];

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])
            ->postApi('/auth/login', $payload)
            ->assertUnauthorized();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2']);
        $this->assertApiError(
            $this->postApi('/auth/login', $payload),
            429,
            'too_many_requests',
        );
    }
}
