<?php

namespace Tests\Feature\Auth;

use App\Http\Responses\ApiResponse;
use App\Models\DeviceSession;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RefreshTokenRotationTest extends TestCase
{
    private const PASSWORD = 'a secure passphrase';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/v1/testing/refresh-protected', fn () => ApiResponse::success([
            'user_id' => request()->user()->public_id,
        ]))->middleware(['auth:sanctum', 'abilities:mobile:access']);
    }

    public function test_a_refresh_token_is_rotated_and_the_access_token_is_replaced(): void
    {
        Carbon::setTestNow('2026-08-08 10:15:30.123');
        $login = $this->mobileLogin();
        $oldAccessToken = $login['access_token'];
        $oldRefreshToken = $login['refresh_token'];
        $oldRefreshRecord = RefreshToken::query()->sole();

        Carbon::setTestNow('2026-08-09 11:20:40.789');
        $response = $this->postApi('/auth/refresh', [
            'refresh_token' => $oldRefreshToken,
        ], [
            'User-Agent' => 'Uncovr/1.3.0 iOS',
        ]);

        $response
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.session.id', DeviceSession::query()->sole()->public_id)
            ->assertJsonPath('data.session.last_used_at', '2026-08-09T11:20:40.000Z')
            ->assertJsonPath('data.session.idle_expires_at', '2026-09-08T11:20:40.000Z')
            ->assertJsonPath('data.session.absolute_expires_at', '2026-11-06T10:15:30.000Z')
            ->assertJsonPath('data.authentication.type', 'bearer')
            ->assertJsonPath('data.authentication.token_type', 'Bearer')
            ->assertJsonPath('data.authentication.access_token_expires_at', '2026-08-09T11:35:40.000Z')
            ->assertJsonPath('data.authentication.refresh_token_expires_at', '2026-09-08T11:20:40.000Z');

        $newAccessToken = $response->json('data.authentication.access_token');
        $newRefreshToken = $response->json('data.authentication.refresh_token');
        $oldRefreshRecord->refresh();
        $successor = RefreshToken::query()->where('generation', 1)->sole();
        $session = DeviceSession::query()->sole();

        $this->assertNotSame($oldAccessToken, $newAccessToken);
        $this->assertNotSame($oldRefreshToken, $newRefreshToken);
        $this->assertSame('2026-08-09 11:20:40', $oldRefreshRecord->used_at->format('Y-m-d H:i:s'));
        $this->assertSame($successor->getKey(), $oldRefreshRecord->replaced_by_id);
        $this->assertSame(hash('sha256', $newRefreshToken), $successor->token_hash);
        $this->assertSame('127.0.0.1', $session->last_ip_address);
        $this->assertSame('Uncovr/1.3.0 iOS', $session->user_agent);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->getApi('/testing/refresh-protected', [
            'Authorization' => 'Bearer '.$oldAccessToken,
        ])->assertUnauthorized();

        $this->assertApiSuccess(
            $this->getApi('/testing/refresh-protected', [
                'Authorization' => 'Bearer '.$newAccessToken,
            ]),
            ['user_id' => $this->user()->public_id],
        );
    }

    public function test_reusing_a_rotated_token_revokes_the_whole_device_session(): void
    {
        Carbon::setTestNow('2026-08-08 10:15:30');
        $login = $this->mobileLogin();

        Carbon::setTestNow('2026-08-08 10:20:30');
        $rotation = $this->postApi('/auth/refresh', [
            'refresh_token' => $login['refresh_token'],
        ])->assertOk();
        $successor = $rotation->json('data.authentication.refresh_token');
        $successorAccessToken = $rotation->json('data.authentication.access_token');
        $sessionId = DeviceSession::query()->sole()->public_id;
        $userId = $this->user()->public_id;

        Carbon::setTestNow('2026-08-08 10:21:30');
        Log::spy();
        $replay = $this->postApi('/auth/refresh', [
            'refresh_token' => $login['refresh_token'],
        ]);

        $this->assertApiError(
            $replay,
            401,
            'invalid_refresh_token',
            'The refresh token is invalid or expired.',
        );

        $session = DeviceSession::query()->sole();
        $this->assertSame('refresh_token_reuse', $session->revocation_reason);
        $this->assertNotNull($session->revoked_at);
        $this->assertSame(0, RefreshToken::query()->whereNull('revoked_at')->count());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Log::shouldHaveReceived('warning')->once()->with(
            'auth.refresh_token_reuse_detected',
            [
                'user_id' => $userId,
                'device_session_id' => $sessionId,
            ],
        );

        $this->assertApiError(
            $this->postApi('/auth/refresh', ['refresh_token' => $successor]),
            401,
            'invalid_refresh_token',
            'The refresh token is invalid or expired.',
        );
        $this->getApi('/testing/refresh-protected', [
            'Authorization' => 'Bearer '.$successorAccessToken,
        ])->assertUnauthorized();
    }

    public function test_an_expired_refresh_token_revokes_the_device_session(): void
    {
        Carbon::setTestNow('2026-08-08 10:15:30');
        $login = $this->mobileLogin();
        RefreshToken::query()->sole()->forceFill([
            'expires_at' => now()->subSecond(),
        ])->save();

        $this->assertApiError(
            $this->postApi('/auth/refresh', [
                'refresh_token' => $login['refresh_token'],
            ]),
            401,
            'invalid_refresh_token',
            'The refresh token is invalid or expired.',
        );

        $session = DeviceSession::query()->sole();
        $this->assertSame('session_expired', $session->revocation_reason);
        $this->assertNotNull($session->revoked_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_refresh_expiry_never_exceeds_the_absolute_session_lifetime(): void
    {
        Carbon::setTestNow('2026-08-08 10:15:30');
        $login = $this->mobileLogin();

        Carbon::setTestNow('2026-09-06 10:15:30');
        $firstRotation = $this->postApi('/auth/refresh', [
            'refresh_token' => $login['refresh_token'],
        ])->assertOk();

        Carbon::setTestNow('2026-10-05 10:15:30');
        $secondRotation = $this->postApi('/auth/refresh', [
            'refresh_token' => $firstRotation->json('data.authentication.refresh_token'),
        ])->assertOk();

        Carbon::setTestNow('2026-10-28 10:15:30');
        $response = $this->postApi('/auth/refresh', [
            'refresh_token' => $secondRotation->json('data.authentication.refresh_token'),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.authentication.refresh_token_expires_at',
                '2026-11-06T10:15:30.000Z',
            )
            ->assertJsonPath(
                'data.session.idle_expires_at',
                '2026-11-06T10:15:30.000Z',
            );

        Carbon::setTestNow('2026-11-06 10:10:30');
        $finalRotation = $this->postApi('/auth/refresh', [
            'refresh_token' => $response->json('data.authentication.refresh_token'),
        ]);

        $finalRotation
            ->assertOk()
            ->assertJsonPath(
                'data.authentication.access_token_expires_at',
                '2026-11-06T10:15:30.000Z',
            )
            ->assertJsonPath(
                'data.authentication.refresh_token_expires_at',
                '2026-11-06T10:15:30.000Z',
            );
    }

    public function test_unknown_and_already_revoked_tokens_share_the_same_safe_error(): void
    {
        Carbon::setTestNow('2026-08-08 10:15:30');
        $login = $this->mobileLogin();
        RefreshToken::query()->sole()->forceFill(['revoked_at' => now()])->save();

        $revoked = $this->postApi('/auth/refresh', [
            'refresh_token' => $login['refresh_token'],
        ]);
        $unknown = $this->postApi('/auth/refresh', [
            'refresh_token' => 'uncovr_refresh_'.str_repeat('A', 43),
        ]);

        $this->assertApiError($revoked, 401, 'invalid_refresh_token');
        $this->assertApiError($unknown, 401, 'invalid_refresh_token');
        $this->assertSame($revoked->json(), $unknown->json());
    }

    public function test_refresh_requires_a_well_formed_token(): void
    {
        $response = $this->postApi('/auth/refresh', [
            'refresh_token' => 'not-a-refresh-token',
        ]);

        $this->assertApiError($response, 422, 'validation_failed')
            ->assertJsonStructure([
                'error' => [
                    'details' => [
                        'fields' => ['refresh_token'],
                    ],
                ],
            ]);
        $this->assertDatabaseCount('device_sessions', 0);
    }

    /** @return array{access_token: string, refresh_token: string} */
    private function mobileLogin(): array
    {
        $user = User::factory()->create([
            'email' => 'artist@example.com',
            'password' => self::PASSWORD,
        ]);
        $user->profile()->create(['display_name' => 'Ada Artist']);

        $response = $this->postApi('/auth/login', [
            'email' => 'artist@example.com',
            'password' => self::PASSWORD,
            'client_type' => 'mobile',
            'device' => [
                'name' => 'Ada’s iPhone',
                'platform' => 'ios',
                'app_version' => '1.2.3',
            ],
        ])->assertOk();

        return [
            'access_token' => $response->json('data.authentication.access_token'),
            'refresh_token' => $response->json('data.authentication.refresh_token'),
        ];
    }

    private function user(): User
    {
        return User::query()->sole();
    }
}
