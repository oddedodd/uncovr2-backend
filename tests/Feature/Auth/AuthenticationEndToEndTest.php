<?php

namespace Tests\Feature\Auth;

use App\Models\DeviceSession;
use App\Models\RefreshToken;
use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthenticationEndToEndTest extends TestCase
{
    public function test_registration_verification_login_refresh_and_logout_flow(): void
    {
        Notification::fake();
        $password = 'a sufficiently secure password';

        $this->assertApiSuccess($this->postApi('/auth/register', [
            'display_name' => 'Ada Artist',
            'email' => 'ADA@EXAMPLE.COM',
            'password' => $password,
            'password_confirmation' => $password,
        ]), [
            'message' => 'If the address can be registered, a verification email will be sent.',
        ], 202);

        $user = User::query()->where('email', 'ada@example.com')->sole();
        $verification = null;
        Notification::assertSentTo(
            $user,
            VerifyEmailNotification::class,
            function (VerifyEmailNotification $notification) use (&$verification): bool {
                $verification = $notification;

                return true;
            },
        );
        $this->assertInstanceOf(VerifyEmailNotification::class, $verification);
        $verificationUrl = $verification->toMail($user)->viewData['verificationUrl'];
        $this->getJson($verificationUrl)->assertOk();

        $login = $this->postApi('/auth/login', [
            'email' => $user->email,
            'password' => $password,
            'client_type' => 'mobile',
            'device' => [
                'name' => 'Ada’s iPhone',
                'platform' => 'ios',
                'app_version' => '1.0.0',
            ],
        ])->assertOk();
        $oldAccess = $login->json('data.authentication.access_token');
        $oldRefresh = $login->json('data.authentication.refresh_token');

        $rotation = $this->postApi('/auth/refresh', [
            'refresh_token' => $oldRefresh,
        ])->assertOk();
        $newAccess = $rotation->json('data.authentication.access_token');
        $newRefresh = $rotation->json('data.authentication.refresh_token');
        $this->assertNotSame($oldAccess, $newAccess);
        $this->assertNotSame($oldRefresh, $newRefresh);

        $this->assertApiSuccess(
            $this->postApi('/auth/logout', headers: [
                'Authorization' => 'Bearer '.$newAccess,
            ]),
            ['message' => 'Logged out.'],
        );

        $session = DeviceSession::query()->sole();
        $this->assertNotNull($session->revoked_at);
        $this->assertSame('user_logout', $session->revocation_reason);
        $this->assertSame(0, RefreshToken::query()->whereNull('revoked_at')->count());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame('array', config('mail.default'));
    }
}
