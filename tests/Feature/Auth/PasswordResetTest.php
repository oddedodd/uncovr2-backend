<?php

namespace Tests\Feature\Auth;

use App\Models\DeviceSession;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    private const OLD_PASSWORD = 'a secure passphrase';

    private const NEW_PASSWORD = 'a newer secure passphrase';

    public function test_forgot_password_is_enumeration_safe_and_queues_an_encrypted_token(): void
    {
        Notification::fake();
        $user = $this->user();

        $known = $this->postApi('/auth/forgot-password', ['email' => strtoupper($user->email)]);
        $this->assertApiSuccess($known, [
            'message' => 'If the account exists, a password reset email will be sent.',
        ], 202);
        $notification = $this->capturedNotification($user);
        $plainToken = Crypt::decryptString($notification->encryptedToken);

        $resetRecord = DB::table('password_reset_tokens')->sole();
        $this->assertTrue(Hash::check($plainToken, $resetRecord->token));
        $this->assertNotSame($plainToken, $resetRecord->token);
        $this->assertStringNotContainsString($plainToken, serialize($notification));
        $this->assertDatabaseHas('security_audit_events', [
            'user_id' => $user->getKey(),
            'event_type' => 'auth.password_reset_requested',
        ]);

        Notification::fake();
        $unknown = $this->postApi('/auth/forgot-password', [
            'email' => 'unknown@example.com',
        ]);
        $this->assertSame($known->json(), $unknown->json());
        Notification::assertNothingSent();
    }

    public function test_a_password_reset_is_single_use_and_revokes_every_session(): void
    {
        Notification::fake();
        config([
            'rate_limiting.authentication_per_ip_per_minute' => 100,
            'rate_limiting.authentication_per_identity_per_minute' => 100,
        ]);
        $user = $this->user();
        $firstAccess = $this->mobileLogin($user, 'iPhone');
        $secondAccess = $this->mobileLogin($user, 'iPad');
        $this->postApi('/auth/forgot-password', ['email' => $user->email])->assertAccepted();
        $token = Crypt::decryptString($this->capturedNotification($user)->encryptedToken);

        $payload = [
            'email' => $user->email,
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ];

        $this->assertApiSuccess($this->postApi('/auth/reset-password', $payload), [
            'message' => 'Password reset. Sign in again on your devices.',
        ]);

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertSame(2, DeviceSession::query()->whereNotNull('revoked_at')->count());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->getApi('/me', $this->bearer($firstAccess))->assertUnauthorized();
        $this->getApi('/me', $this->bearer($secondAccess))->assertUnauthorized();
        $this->assertDatabaseHas('security_audit_events', [
            'user_id' => $user->getKey(),
            'event_type' => 'auth.password_reset_completed',
        ]);

        $this->assertApiError(
            $this->postApi('/auth/reset-password', $payload),
            422,
            'invalid_password_reset',
            'The password reset request is invalid or expired.',
        );

        $this->assertApiError($this->postApi('/auth/login', $this->loginPayload(
            $user,
            self::OLD_PASSWORD,
            'Old password phone',
        )), 401, 'invalid_credentials');
        $this->postApi('/auth/login', $this->loginPayload(
            $user,
            self::NEW_PASSWORD,
            'New password phone',
        ))->assertOk();
    }

    public function test_an_expired_reset_token_returns_the_same_safe_error(): void
    {
        Notification::fake();
        config(['auth.passwords.users.expire' => 1]);
        Carbon::setTestNow('2026-08-08 10:00:00');
        $user = $this->user();
        $this->postApi('/auth/forgot-password', ['email' => $user->email])->assertAccepted();
        $token = Crypt::decryptString($this->capturedNotification($user)->encryptedToken);

        Carbon::setTestNow('2026-08-08 10:02:00');
        $this->assertApiError($this->postApi('/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]), 422, 'invalid_password_reset');
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));
    }

    public function test_reset_email_has_both_formats_retries_and_a_portal_link(): void
    {
        $user = $this->user();
        $plainToken = 'secret-reset-token';
        $notification = new ResetPasswordNotification($plainToken);
        $message = $notification->toMail($user);

        $this->assertTrue($notification->afterCommit);
        $this->assertSame('emails', $notification->queue);
        $this->assertSame(3, $notification->tries);
        $this->assertSame([60, 300, 900], $notification->backoff());
        $this->assertSame('mail.auth.reset-password', $message->view['html']);
        $this->assertSame('mail.auth.reset-password-text', $message->view['text']);
        $this->assertStringStartsWith('http://localhost:5173/reset-password?', $message->viewData['resetUrl']);
        $this->assertStringContainsString(urlencode($plainToken), $message->viewData['resetUrl']);
        $this->assertStringContainsString(urlencode($user->email), $message->viewData['resetUrl']);

        $html = view($message->view['html'], $message->viewData)->render();
        $text = view($message->view['text'], $message->viewData)->render();
        $this->assertStringContainsString('Tilbakestill passordet ditt', $html);
        $this->assertStringContainsString($message->viewData['resetUrl'], $text);

        $email = new Email;
        foreach ($message->callbacks as $callback) {
            $callback($email);
        }
        $key = $email->getHeaders()
            ->get('X-Uncovr-Resend-Idempotency-Key')
            ?->getBodyAsString();
        $this->assertMatchesRegularExpression('/^password-reset-[a-f0-9]{64}$/', $key ?? '');
    }

    private function capturedNotification(User $user): ResetPasswordNotification
    {
        $captured = null;
        Notification::assertSentTo(
            $user,
            ResetPasswordNotification::class,
            function (ResetPasswordNotification $notification) use (&$captured): bool {
                $captured = $notification;

                return true;
            },
        );
        $this->assertInstanceOf(ResetPasswordNotification::class, $captured);

        return $captured;
    }

    private function user(): User
    {
        $user = User::factory()->create([
            'email' => 'artist@example.com',
            'password' => self::OLD_PASSWORD,
        ]);
        $user->profile()->create(['display_name' => 'Ada Artist']);

        return $user;
    }

    private function mobileLogin(User $user, string $deviceName): string
    {
        return $this->postApi('/auth/login', $this->loginPayload(
            $user,
            self::OLD_PASSWORD,
            $deviceName,
        ))->assertOk()->json('data.authentication.access_token');
    }

    /** @return array<string, mixed> */
    private function loginPayload(User $user, string $password, string $deviceName): array
    {
        return [
            'email' => $user->email,
            'password' => $password,
            'client_type' => 'mobile',
            'device' => ['name' => $deviceName, 'platform' => 'ios'],
        ];
    }

    /** @return array<string, string> */
    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}
