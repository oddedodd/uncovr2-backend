<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    public function test_a_signed_link_verifies_an_email_once(): void
    {
        Notification::fake();
        $user = $this->unverifiedUser();
        $notification = $this->sendAndCapture($user);
        $url = $notification->toMail($user->fresh())->viewData['verificationUrl'];

        $this->assertApiSuccess($this->getJson($url), [
            'message' => 'Email address verified.',
        ]);
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->assertApiError($this->getJson($url), 410, 'gone');
    }

    public function test_expired_and_tampered_links_are_rejected(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-07 12:00:00');
        config(['auth.verification.expire' => 10]);
        $user = $this->unverifiedUser();
        $notification = $this->sendAndCapture($user);
        $url = $notification->toMail($user->fresh())->viewData['verificationUrl'];

        $tampered = str_replace('/1/'.sha1($user->email), '/2/'.sha1($user->email), $url);
        $this->assertApiError($this->getJson($tampered), 403, 'forbidden');

        Carbon::setTestNow('2026-08-07 12:11:00');
        $this->assertApiError($this->getJson($url), 403, 'forbidden');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resending_invalidates_the_previous_link_and_is_enumeration_safe(): void
    {
        Notification::fake();
        $user = $this->unverifiedUser();
        $first = $this->sendAndCapture($user);
        $oldUrl = $first->toMail($user->fresh())->viewData['verificationUrl'];

        Notification::fake();
        $response = $this->postApi('/auth/resend-verification', [
            'email' => strtoupper($user->email),
        ]);
        $this->assertApiSuccess($response, [
            'message' => 'If the address can be registered, a verification email will be sent.',
        ], 202);

        $user->refresh();
        $this->assertSame(2, $user->email_verification_version);
        Notification::assertSentTo(
            $user,
            VerifyEmailNotification::class,
            fn (VerifyEmailNotification $notification): bool => $notification->version === 2,
        );
        $this->assertApiError($this->getJson($oldUrl), 410, 'gone');

        Notification::fake();
        $unknownResponse = $this->postApi('/auth/resend-verification', [
            'email' => 'unknown@example.com',
        ]);
        $this->assertSame($response->json(), $unknownResponse->json());
        Notification::assertNothingSent();
    }

    public function test_verified_accounts_do_not_receive_resend_notifications(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->assertApiSuccess($this->postApi('/auth/resend-verification', [
            'email' => $user->email,
        ]), [
            'message' => 'If the address can be registered, a verification email will be sent.',
        ], 202);

        Notification::assertNothingSent();
    }

    public function test_verification_resends_are_rate_limited_per_normalized_identity(): void
    {
        Notification::fake();
        config([
            'rate_limiting.authentication_per_ip_per_minute' => 100,
            'rate_limiting.authentication_per_identity_per_minute' => 1,
        ]);
        $user = $this->unverifiedUser();

        $this->postApi('/auth/resend-verification', ['email' => strtoupper($user->email)])
            ->assertAccepted();
        $this->assertApiError(
            $this->postApi('/auth/resend-verification', ['email' => ' '.$user->email.' ']),
            429,
            'too_many_requests',
        );
    }

    public function test_verification_mail_is_queued_after_commit_with_retries_and_both_formats(): void
    {
        $user = $this->unverifiedUser();
        $notification = new VerifyEmailNotification(7);
        $message = $notification->toMail($user);

        $this->assertTrue($notification->afterCommit);
        $this->assertSame('emails', $notification->queue);
        $this->assertSame(3, $notification->tries);
        $this->assertSame([60, 300, 900], $notification->backoff());
        $this->assertSame('mail.auth.verify-email', $message->view['html']);
        $this->assertSame('mail.auth.verify-email-text', $message->view['text']);

        $html = view($message->view['html'], $message->viewData)->render();
        $text = view($message->view['text'], $message->viewData)->render();

        $this->assertStringContainsString('Bekreft e-postadressen din', $html);
        $this->assertStringContainsString('Ada Artist', $html);
        $this->assertStringContainsString('Bekreft e-postadressen din', $text);
        $this->assertStringContainsString($message->viewData['verificationUrl'], $text);

        $email = new Email;
        foreach ($message->callbacks as $callback) {
            $callback($email);
        }

        $key = $email->getHeaders()
            ->get('X-Uncovr-Resend-Idempotency-Key')
            ?->getBodyAsString();
        $this->assertMatchesRegularExpression('/^verify-email-[a-f0-9]{64}$/', $key ?? '');
    }

    private function unverifiedUser(): User
    {
        $user = User::factory()->unverified()->create();
        $user->profile()->create(['display_name' => 'Ada Artist']);

        return $user;
    }

    private function sendAndCapture(User $user): VerifyEmailNotification
    {
        $user->sendEmailVerificationNotification();
        $captured = null;

        Notification::assertSentTo(
            $user,
            VerifyEmailNotification::class,
            function (VerifyEmailNotification $notification) use (&$captured): bool {
                $captured = $notification;

                return true;
            },
        );

        $this->assertInstanceOf(VerifyEmailNotification::class, $captured);

        return $captured;
    }
}
