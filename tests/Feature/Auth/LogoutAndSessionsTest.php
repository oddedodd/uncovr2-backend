<?php

namespace Tests\Feature\Auth;

use App\Models\DeviceSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LogoutAndSessionsTest extends TestCase
{
    private const PASSWORD = 'a secure passphrase';

    public function test_a_user_can_list_and_revoke_another_device_session(): void
    {
        Carbon::setTestNow('2026-08-08 10:15:30');
        $user = $this->user();
        $iphone = $this->login($user, 'iPhone');
        Carbon::setTestNow('2026-08-08 10:20:30');
        $ipad = $this->login($user, 'iPad');

        $response = $this->getApi('/me/sessions', $this->bearer($iphone['access_token']));
        $response
            ->assertOk()
            ->assertJsonCount(2, 'data.sessions')
            ->assertJsonPath('data.sessions.0.device_name', 'iPad')
            ->assertJsonPath('data.sessions.0.current', false)
            ->assertJsonPath('data.sessions.1.device_name', 'iPhone')
            ->assertJsonPath('data.sessions.1.current', true);

        $this->assertApiSuccess(
            $this->deleteApi(
                '/me/sessions/'.$ipad['session_id'],
                headers: $this->bearer($iphone['access_token']),
            ),
            ['message' => 'Session revoked.'],
        );
        $this->app['auth']->forgetGuards();

        $revoked = DeviceSession::query()->where('public_id', $ipad['session_id'])->sole();
        $this->assertSame('user_session_revoked', $revoked->revocation_reason);
        $this->assertNotNull($revoked->revoked_at);
        $this->getApi('/me', $this->bearer($ipad['access_token']))->assertUnauthorized();
        $this->getApi('/me', $this->bearer($iphone['access_token']))->assertOk();
        $this->assertApiError(
            $this->postApi('/auth/refresh', ['refresh_token' => $ipad['refresh_token']]),
            401,
            'invalid_refresh_token',
        );
        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => 'auth.session_revoked',
            'device_session_public_id' => $ipad['session_id'],
        ]);
    }

    public function test_current_device_logout_revokes_access_and_refresh_credentials(): void
    {
        $user = $this->user();
        $login = $this->login($user, 'iPhone');

        $this->assertApiSuccess(
            $this->postApi('/auth/logout', headers: $this->bearer($login['access_token'])),
            ['message' => 'Logged out.'],
        );
        $this->app['auth']->forgetGuards();

        $session = DeviceSession::query()->sole();
        $this->assertSame('user_logout', $session->revocation_reason);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->getApi('/me', $this->bearer($login['access_token']))->assertUnauthorized();
        $this->assertApiError(
            $this->postApi('/auth/refresh', ['refresh_token' => $login['refresh_token']]),
            401,
            'invalid_refresh_token',
        );
        $this->assertDatabaseHas('security_audit_events', ['event_type' => 'auth.logout']);
    }

    public function test_logout_all_revokes_every_device_but_not_other_users(): void
    {
        $user = $this->user();
        $first = $this->login($user, 'iPhone');
        $second = $this->login($user, 'iPad');
        $other = $this->user('other@example.com');
        $otherLogin = $this->login($other, 'Other phone');

        $this->assertApiSuccess(
            $this->postApi('/auth/logout-all', headers: $this->bearer($first['access_token'])),
            ['message' => 'Logged out from all devices.'],
        );
        $this->app['auth']->forgetGuards();

        $this->assertSame(2, DeviceSession::query()
            ->where('user_id', $user->getKey())
            ->whereNotNull('revoked_at')
            ->count());
        $this->getApi('/me', $this->bearer($first['access_token']))->assertUnauthorized();
        $this->getApi('/me', $this->bearer($second['access_token']))->assertUnauthorized();
        $this->getApi('/me', $this->bearer($otherLogin['access_token']))->assertOk();
        $this->assertDatabaseHas('security_audit_events', ['event_type' => 'auth.logout_all']);
    }

    public function test_a_user_cannot_revoke_another_users_session(): void
    {
        $user = $this->user();
        $login = $this->login($user, 'iPhone');
        $other = $this->user('other@example.com');
        $otherLogin = $this->login($other, 'Other phone');

        $this->deleteApi(
            '/me/sessions/'.$otherLogin['session_id'],
            headers: $this->bearer($login['access_token']),
        )->assertNotFound();

        $this->getApi('/me', $this->bearer($otherLogin['access_token']))->assertOk();
    }

    public function test_portal_logout_invalidates_the_browser_session(): void
    {
        $user = $this->user();
        $payload = [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'client_type' => 'portal',
            'device' => ['name' => 'Safari on Mac', 'platform' => 'macos'],
        ];

        $this->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Referer', 'http://localhost:5173/login')
            ->postApi('/auth/login', $payload)
            ->assertOk();
        $this->assertAuthenticatedAs($user, 'web');

        $this->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Referer', 'http://localhost:5173/account')
            ->postApi('/auth/logout')
            ->assertOk();

        $this->assertGuest('web');
        $session = DeviceSession::query()->sole();
        $this->assertSame('user_logout', $session->revocation_reason);
        $this->assertNotNull($session->revoked_at);
    }

    public function test_an_expired_portal_device_session_cannot_use_protected_routes(): void
    {
        Carbon::setTestNow('2026-08-08 10:15:30');
        $user = $this->user();
        $payload = [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'client_type' => 'portal',
            'device' => ['name' => 'Safari on Mac', 'platform' => 'macos'],
        ];
        $this->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Referer', 'http://localhost:5173/login')
            ->postApi('/auth/login', $payload)
            ->assertOk();
        $session = DeviceSession::query()->sole();
        $session->forceFill(['absolute_expires_at' => now()->subSecond()])->save();

        $this->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Referer', 'http://localhost:5173/account')
            ->getApi('/me')
            ->assertUnauthorized();

        $this->assertSame('session_expired', $session->fresh()->revocation_reason);
    }

    /** @return array{access_token: string, refresh_token: string, session_id: string} */
    private function login(User $user, string $deviceName): array
    {
        $response = $this->postApi('/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'client_type' => 'mobile',
            'device' => ['name' => $deviceName, 'platform' => 'ios'],
        ])->assertOk();

        return [
            'access_token' => $response->json('data.authentication.access_token'),
            'refresh_token' => $response->json('data.authentication.refresh_token'),
            'session_id' => $response->json('data.session.id'),
        ];
    }

    private function user(string $email = 'artist@example.com'): User
    {
        $user = User::factory()->create(['email' => $email, 'password' => self::PASSWORD]);
        $user->profile()->create(['display_name' => 'Test User']);

        return $user;
    }

    /** @return array<string, string> */
    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}
