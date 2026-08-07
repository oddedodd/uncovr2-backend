<?php

namespace Tests\Feature\Auth;

use App\Http\Responses\ApiResponse;
use App\Models\DeviceSession;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LoginTest extends TestCase
{
    private const PASSWORD = 'a secure passphrase';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/v1/testing/login-protected', fn () => ApiResponse::success([
            'user_id' => request()->user()->public_id,
        ]))->middleware(['auth:sanctum', 'abilities:mobile:access']);
    }

    public function test_a_verified_user_can_log_in_on_mobile_with_a_device_session_and_tokens(): void
    {
        Carbon::setTestNow('2026-08-08 10:15:30.123');
        $user = $this->verifiedUser();

        $response = $this->postApi('/auth/login', $this->mobilePayload(), [
            'User-Agent' => 'Uncovr/1.2.3 iOS',
        ]);

        $response
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.user.id', $user->public_id)
            ->assertJsonPath('data.user.email', 'artist@example.com')
            ->assertJsonPath('data.user.profile.display_name', 'Ada Artist')
            ->assertJsonPath('data.session.client_type', 'mobile')
            ->assertJsonPath('data.session.device_name', 'Ada’s iPhone')
            ->assertJsonPath('data.session.platform', 'ios')
            ->assertJsonPath('data.session.app_version', '1.2.3')
            ->assertJsonPath('data.session.last_used_at', '2026-08-08T10:15:30.000Z')
            ->assertJsonPath('data.session.idle_expires_at', '2026-09-07T10:15:30.000Z')
            ->assertJsonPath('data.session.absolute_expires_at', '2026-11-06T10:15:30.000Z')
            ->assertJsonPath('data.authentication.type', 'bearer')
            ->assertJsonPath('data.authentication.token_type', 'Bearer')
            ->assertJsonPath('data.authentication.access_token_expires_at', '2026-08-08T10:30:30.000Z')
            ->assertJsonPath('data.authentication.refresh_token_expires_at', '2026-09-07T10:15:30.000Z')
            ->assertJsonMissingPath('data.user.password');

        $deviceSession = DeviceSession::query()->sole();
        $refreshToken = RefreshToken::query()->sole();
        $accessToken = $user->tokens()->sole();
        $plainRefreshToken = $response->json('data.authentication.refresh_token');
        $plainAccessToken = $response->json('data.authentication.access_token');
        [, $accessSecret] = explode('|', $plainAccessToken, 2);

        $this->assertSame($deviceSession->getKey(), $refreshToken->device_session_id);
        $this->assertSame($deviceSession->getKey(), $accessToken->device_session_id);
        $this->assertSame(0, $refreshToken->generation);
        $this->assertSame(hash('sha256', $plainRefreshToken), $refreshToken->token_hash);
        $this->assertSame(hash('sha256', $accessSecret), $accessToken->token);
        $this->assertNotSame($plainRefreshToken, $refreshToken->token_hash);
        $this->assertStringStartsWith('uncovr_refresh_', $plainRefreshToken);
        $this->assertMatchesRegularExpression(
            '/^uncovr_refresh_[A-Za-z0-9_-]{43}$/',
            $plainRefreshToken,
        );
        $this->assertSame('127.0.0.1', $deviceSession->last_ip_address);
        $this->assertSame('Uncovr/1.2.3 iOS', $deviceSession->user_agent);

        $this->assertApiSuccess(
            $this->getApi('/testing/login-protected', [
                'Authorization' => 'Bearer '.$plainAccessToken,
            ]),
            ['user_id' => $user->public_id],
        );
    }

    public function test_a_verified_user_can_log_in_to_the_stateful_portal_without_tokens(): void
    {
        Carbon::setTestNow('2026-08-08 10:15:30.123');
        $user = $this->verifiedUser();
        $payload = $this->mobilePayload([
            'client_type' => 'portal',
            'device' => [
                'name' => '  Safari   on Mac  ',
                'platform' => 'macos',
                'app_version' => null,
            ],
        ]);

        $response = $this
            ->withHeader('Origin', 'http://localhost:3000')
            ->withHeader('Referer', 'http://localhost:3000/login')
            ->postApi('/auth/login', $payload);

        $response
            ->assertOk()
            ->assertJsonPath('data.authentication.type', 'session')
            ->assertJsonMissingPath('data.authentication.access_token')
            ->assertJsonMissingPath('data.authentication.refresh_token')
            ->assertJsonPath('data.session.client_type', 'portal')
            ->assertJsonPath('data.session.device_name', 'Safari on Mac')
            ->assertJsonPath('data.session.idle_expires_at', '2026-08-08T12:15:30.000Z')
            ->assertJsonPath('data.session.absolute_expires_at', '2026-08-08T22:15:30.000Z');

        $this->assertAuthenticatedAs($user, 'web');
        $deviceSession = DeviceSession::query()->sole();
        $this->assertNotNull($deviceSession->web_session_id);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('refresh_tokens', 0);
        $this->assertApiSuccess(
            $this->getApi('/testing/login-protected'),
            ['user_id' => $user->public_id],
        );
    }

    public function test_portal_login_rejects_a_non_stateful_request(): void
    {
        $this->verifiedUser();

        $this->assertApiError(
            $this->postApi('/auth/login', $this->mobilePayload([
                'client_type' => 'portal',
            ])),
            400,
            'stateful_session_required',
        );

        $this->assertGuest('web');
        $this->assertDatabaseCount('device_sessions', 0);
    }

    public function test_unknown_email_and_wrong_password_return_the_same_safe_error(): void
    {
        $this->verifiedUser();

        $unknown = $this->postApi('/auth/login', $this->mobilePayload([
            'email' => 'unknown@example.com',
        ]));
        $wrong = $this->postApi('/auth/login', $this->mobilePayload([
            'password' => 'the wrong password',
        ]));

        $this->assertApiError($unknown, 401, 'invalid_credentials');
        $this->assertApiError($wrong, 401, 'invalid_credentials');
        $this->assertSame($unknown->json(), $wrong->json());
        $this->assertDatabaseCount('device_sessions', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('refresh_tokens', 0);
    }

    public function test_an_unverified_user_receives_no_session_or_tokens(): void
    {
        $user = $this->verifiedUser();
        $user->forceFill(['email_verified_at' => null])->save();

        $this->assertApiError(
            $this->postApi('/auth/login', $this->mobilePayload()),
            403,
            'email_not_verified',
            'Verify the email address before signing in.',
        );

        $this->assertDatabaseCount('device_sessions', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('refresh_tokens', 0);
    }

    public function test_login_validates_client_and_bounded_device_metadata(): void
    {
        $this->verifiedUser();

        $response = $this->postApi('/auth/login', [
            'email' => 'artist@example.com',
            'password' => self::PASSWORD,
            'client_type' => 'desktop',
            'device' => [
                'name' => '',
                'unexpected' => 'rejected',
            ],
        ]);

        $this->assertApiError($response, 422, 'validation_failed')
            ->assertJsonStructure([
                'error' => [
                    'details' => [
                        'fields' => ['client_type', 'device', 'device.name'],
                    ],
                ],
            ]);
        $this->assertDatabaseCount('device_sessions', 0);
    }

    private function verifiedUser(): User
    {
        $user = User::factory()->create([
            'email' => 'artist@example.com',
            'password' => self::PASSWORD,
        ]);
        $user->profile()->create(['display_name' => 'Ada Artist']);

        return $user;
    }

    /** @return array<string, mixed> */
    private function mobilePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'email' => ' ARTIST@EXAMPLE.COM ',
            'password' => self::PASSWORD,
            'client_type' => 'mobile',
            'device' => [
                'name' => 'Ada’s iPhone',
                'platform' => 'ios',
                'app_version' => '1.2.3',
            ],
        ], $overrides);
    }
}
